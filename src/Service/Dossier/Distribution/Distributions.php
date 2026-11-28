<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\DistributionCopy;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\CopyKind;
use App\Enum\Dossier\DistributionCopyState;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\RestorePointOrigin;
use App\Repository\Dossier\DistributionCopyRepository;
use App\Repository\Identity\UserRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Copy\DossierCopier;
use App\Service\Dossier\Copy\DossierEraser;
use App\Service\Dossier\DossierDates;
use App\Service\Dossier\Restore\RestorePoints;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * The one door of the distributions (lot 1, step 1.8; design-ux.md § 4.2): launch one from a
 * checked plan, make one of its dossiers, say a copy failed, relaunch it, move the date of all its
 * dossiers, withdraw it. Who may: DistributionVoter (its author). Every gesture is in the audit
 * trail; each dossier made has its own line in its trail.
 *
 * **A dossier of a distribution is made whole or not at all**: its row, DossierCopier's copy of
 * the template (CopyKind::Template: nobody's pending work), a group's members with their
 * profiles, and its « État de départ » - a restore point outside the limit of ten, what the
 * teacher's reset goes back to - in one transaction. The files cannot be rolled back: a copy that
 * stops has DossierEraser delete the ones it wrote.
 */
final readonly class Distributions
{
    public const string START_POINT = 'État de départ';

    public const string COPY_FAILURE = 'La copie de ce dossier n’a pas pu être faite : rien n’en a été gardé. Relancez-la.';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private DistributionCopyRepository $copies,
        private UserRepository $users,
        private DossierCopier $copier,
        private DossierEraser $eraser,
        private RestorePoints $points,
        private DossierDates $dates,
        private AuditTrail $auditTrail,
        private DistributionQueue $queue,
        private LockFactory $locks,
    ) {
    }

    /** The lock each copy is made under (LOCK_DSN: `php` and `worker` see the same one). */
    public static function lockName(int $copyId): string
    {
        return 'distribution-copy-'.$copyId;
    }

    /**
     * The distribution and its dossiers, waiting; their copies follow in the background. A command
     * launches one without an author.
     *
     * @throws DistributionRefused
     */
    public function launch(DistributionPlan $plan, ?User $by, \DateTimeImmutable $now): Distribution
    {
        if (!$plan->isValid()) {
            throw new DistributionRefused(array_values($plan->faults)[0]);
        }
        $distribution = new Distribution($plan->template, $by, $plan->schoolClass, $plan->mode, $plan->startDate, $now);
        $this->entityManager->persist($distribution);
        if (DistributionMode::Group === $plan->mode) {
            foreach ($plan->groups() as $group) {
                $this->entityManager->persist(DistributionCopy::forGroup($distribution, $group['name'], $group['members']));
            }
        } else {
            foreach ($plan->ticked() as $line) {
                $this->entityManager->persist(DistributionCopy::forStudent($distribution, $line->student));
            }
        }
        $this->entityManager->flush();
        $this->auditTrail->record(AuditAction::DistributionLaunched, $by ?? 'Peyra', 'distribution', (string) $distribution->getId(), \sprintf(
            '« %s » distribué en %s : %s',
            $distribution->getTitle(),
            $plan->schoolClass->getLabel(),
            mb_lcfirst($plan->sentence()),
        ), [
            'template' => $plan->template->getId(),
            'class' => $plan->schoolClass->getId(),
            'mode' => $plan->mode->value,
            'dossiers' => $plan->dossierCount(),
            'start' => $plan->startDate->format('Y-m-d'),
        ]);
        $this->entityManager->flush();
        $this->queue->add($distribution);

        return $distribution;
    }

    /**
     * Makes one dossier of a distribution, if it is still to make (the caller holds the copy's
     * lock, DistributionRunner). An exception leaves nothing of it; the caller says it failed.
     *
     * @throws DistributionRefused when it cannot be made at all
     */
    public function make(DistributionCopy $copy, \DateTimeImmutable $now): void
    {
        if (!$copy->getState()->isPending()) {
            return;
        }
        $distribution = $copy->getDistribution();
        $template = $distribution->getTemplate() ?? throw new DistributionRefused('Le modèle a été supprimé : ce dossier ne peut plus être créé.');
        $copy->markCopying();
        $this->entityManager->flush();

        $written = [];
        try {
            $this->connection->transactional(function () use ($copy, $distribution, $template, $now, &$written): void {
                $author = $distribution->getAuthor();
                $student = $copy->getStudent();
                $dossier = new Dossier(null === $student ? DossierKind::Group : DossierKind::Student, $template->getCompany(), $distribution->getStartDate(), $student, $distribution->getSchoolClass()->getSchoolYear(), $now, $author);
                $dossier->comesFrom($template, $author);
                $this->entityManager->persist($dossier);
                $this->entityManager->flush();
                $written[] = $dossier->getId() ?? 0;
                $report = $this->copier->copy($template, $dossier, CopyKind::Template);
                $names = [];
                foreach ($copy->getMembers() as $member) {
                    $user = $this->users->find($member['student']) ?? throw new DistributionRefused('Un membre du groupe n’existe plus : ce dossier ne peut plus être créé.');
                    $row = new DossierMember($dossier, $user, $now);
                    $row->assignProfile($member['profile']);
                    $this->entityManager->persist($row);
                    $names[] = $user->getDisplayName().(null === $member['profile'] ? '' : ' ('.mb_strtolower($member['profile']->label()).')');
                }
                $this->auditTrail->inDossier($dossier, AuditAction::DossierCreated, $author ?? 'Peyra', \sprintf(
                    'Dossier distribué depuis « %s » en %s : %s',
                    $distribution->getTitle(),
                    $distribution->getSchoolClass()->getLabel(),
                    null === $student ? $copy->getGroupName().', '.implode(', ', $names) : $student->getDisplayName(),
                ), [
                    'distribution' => $distribution->getId(),
                    'source' => $template->getId(),
                    'rows' => $report->rowCount(),
                    'files' => $report->files,
                ]);
                $point = $this->points->create($dossier, self::START_POINT, RestorePointOrigin::Start, $author ?? 'Peyra', $now);
                $this->entityManager->flush();
                $written[] = $point->getSnapshot()->getId() ?? 0;
                $this->points->prepare($point, $now);
                $copy->markReady($dossier, $now);
                $this->entityManager->flush();
            });
        } catch (\Throwable $exception) {
            foreach ($written as $dossierId) {
                if ($dossierId > 0) {
                    $this->eraser->deleteFilesOf($dossierId);
                }
            }
            throw $exception;
        }
    }

    /** The copy stopped: it says so, and the trail. */
    public function fail(DistributionCopy $copy, string $reason, \DateTimeImmutable $now): void
    {
        if (!$copy->getState()->isPending()) {
            return;
        }
        $copy->markFailed($reason, $now);
        $distribution = $copy->getDistribution();
        $this->auditTrail->record(AuditAction::DistributionCopyFailed, 'Peyra', 'distribution', (string) $distribution->getId(), \sprintf(
            'Dossier de %s non créé (« %s ») : %s',
            self::holderOf($copy),
            $distribution->getTitle(),
            $reason,
        ), ['copy' => $copy->getId()]);
        $this->entityManager->flush();
    }

    /** « Relancer » a failed copy: it waits again, its copy follows in the background. */
    public function retry(DistributionCopy $copy, User $by): void
    {
        if (DistributionCopyState::Failed !== $copy->getState()) {
            throw new DistributionRefused('Seule une copie en échec se relance.');
        }
        $copy->retry();
        $distribution = $copy->getDistribution();
        $this->auditTrail->record(AuditAction::DistributionCopyRetried, $by, 'distribution', (string) $distribution->getId(), \sprintf('Copie du dossier de %s relancée (« %s »)', self::holderOf($copy), $distribution->getTitle()), ['copy' => $copy->getId()]);
        $this->entityManager->flush();
        $this->queue->add($distribution);
    }

    /**
     * Moves the date of every dossier of the distribution made so far (lot 1, step 1.8: « en un
     * geste »), through the one door of a dossier's date; a dossier the teacher may not date
     * (DossierVoter::SET_DATE, asked by $allowed) is left and counted.
     *
     * @param callable(Dossier): bool $allowed
     *
     * @return array{moved: int, unchanged: int, refused: int}
     */
    public function moveDate(Distribution $distribution, \DateTimeImmutable $to, User $by, \DateTimeImmutable $now, callable $allowed): array
    {
        $counts = ['moved' => 0, 'unchanged' => 0, 'refused' => 0];
        foreach ($this->copies->findByDistribution($distribution) as $copy) {
            $dossier = $copy->getDossier();
            if (null === $dossier || DistributionCopyState::Ready !== $copy->getState()) {
                continue;
            }
            if (!$allowed($dossier)) {
                ++$counts['refused'];
            } elseif (null === $this->dates->move($dossier, $to, $by, $now)) {
                ++$counts['unchanged'];
            } else {
                ++$counts['moved'];
            }
        }
        $this->entityManager->flush();

        return $counts;
    }

    /**
     * Withdraws the distribution (a teacher's gesture, never automatic): every dossier it made is
     * deleted with its restore points (their trails stay), then the distribution. The title is
     * typed to confirm (design-ux.md § 6.2). Answers the sentence that refuses, or null once done.
     */
    public function withdraw(Distribution $distribution, string $typed, User $by): ?string
    {
        if (trim($typed) !== $distribution->getTitle()) {
            return \sprintf('Tapez « %s » pour retirer cette distribution.', $distribution->getTitle());
        }
        $copies = $this->copies->findByDistribution($distribution);
        /** @var list<SharedLockInterface> $held */
        $held = [];
        try {
            foreach ($copies as $copy) {
                $lock = $this->locks->createLock(self::lockName($copy->getId() ?? 0));
                if (!$lock->acquire()) {
                    return 'Un dossier de cette distribution est en cours de copie : réessayez dans un instant.';
                }
                $held[] = $lock;
            }
            $dossiers = array_values(array_filter(array_map(static fn (DistributionCopy $copy): ?Dossier => $copy->getDossier(), $copies)));
            $this->auditTrail->record(AuditAction::DistributionWithdrawn, $by, 'distribution', (string) $distribution->getId(), \sprintf(
                'Distribution de « %s » en %s retirée : %d %s',
                $distribution->getTitle(),
                $distribution->getSchoolClass()->getLabel(),
                \count($dossiers),
                \count($dossiers) > 1 ? 'dossiers supprimés' : 'dossier supprimé',
            ), ['dossiers' => array_map(static fn (Dossier $dossier): ?int => $dossier->getId(), $dossiers)]);
            $this->entityManager->flush();
            // The copies name the dossiers about to go: the database forgets them (SET NULL), the
            // unit of work must not hold them any more.
            foreach ($copies as $copy) {
                $this->entityManager->detach($copy);
            }
            foreach ($dossiers as $dossier) {
                $this->eraser->deleteDossier($dossier);
            }
            $this->entityManager->remove($distribution);
            $this->entityManager->flush();

            return null;
        } finally {
            foreach ($held as $lock) {
                $lock->release();
            }
        }
    }

    /** « Lucas Bernard », or « Groupe 2 ». */
    public static function holderOf(DistributionCopy $copy): string
    {
        return $copy->getStudent()?->getDisplayName() ?? (string) $copy->getGroupName();
    }
}
