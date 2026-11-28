<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

use App\Entity\Dossier\DossierRestoration;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\CopyKind;
use App\Enum\Dossier\RestorePointOrigin;
use App\Repository\Dossier\DossierRestorationRepository;
use App\Repository\Dossier\RestorePointRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Copy\DossierCopier;
use App\Service\Dossier\Copy\DossierEraser;
use App\Service\Shared\Storage\DossierFiles;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Taking a dossier back to one of its restore points (design-ux.md § 4.9, steps 2 and 3): asked by
 * a person after the récapitulatif, then done in the background while every tab of the dossier
 * shows its waiting page. Who may: RestorePointVoter::RETURN.
 *
 * The return erases the dossier's rows and copies the point's back in **one transaction**: it
 * holds whole or not at all, and a failure leaves the dossier as it was. Files cannot be rolled
 * back, so they are dealt with around it - the point's are copied in beside the dossier's, then
 * the ones no row names any more are deleted once the transaction holds (or the ones it brought,
 * when it did not). The dossier's date stays where the teacher put it (DECISIONS 1.8a); the trail
 * is never rewritten, it gains a line.
 */
final readonly class Restorations
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private RestorePoints $points,
        private RestorePointRepository $pointRepository,
        private DossierRestorationRepository $restorations,
        private DossierCopier $copier,
        private DossierEraser $eraser,
        private DossierFiles $files,
        private AuditTrail $auditTrail,
        private RestoreQueue $queue,
    ) {
    }

    /**
     * Asks for the return; with $keepCurrent, a point of the dossier as it is now is posed first
     * (its copy made by the return itself, before anything is erased).
     *
     * @throws RestoreRefused
     */
    public function returnTo(RestorePoint $point, bool $keepCurrent, User $by, \DateTimeImmutable $now): DossierRestoration
    {
        $dossier = $point->getDossier();
        if (!$point->isReady()) {
            throw new RestoreRefused('On revient à un point prêt seulement.');
        }
        $this->points->expectFree($dossier);
        $safety = null;
        if ($keepCurrent) {
            if (RestorePointRules::limitReached($this->pointRepository->countByDossier($dossier))) {
                throw new RestoreRefused(RestorePointRules::LIMIT_REACHED, 'keep');
            }
            $safety = $this->points->create($dossier, RestorePointRules::safetyName($point->getName()), RestorePointOrigin::BeforeReturn, $by, $now);
        }
        $restoration = new DossierRestoration($point, $safety, $by, $now);
        $this->entityManager->persist($restoration);
        $this->entityManager->flush();
        $this->queue->restoration($restoration);

        return $restoration;
    }

    /**
     * Does the return, if it is still to do (the caller holds the dossier's lock, RestoreRunner).
     * Throws RestorationStopped - the dossier untouched - when it cannot be done; the caller then
     * says it failed. The entity manager is cleared once the rows are rewritten: what it held of
     * the dossier is stale.
     */
    public function restore(DossierRestoration $restoration, \DateTimeImmutable $now): void
    {
        if (!$restoration->isRunning()) {
            return;
        }
        $restorationId = $restoration->getId();
        $dossier = $restoration->getDossier();
        $safety = $restoration->getSafetyPoint();
        if (null !== $safety) {
            try {
                $this->points->prepare($safety, $now);
            } catch (\Throwable $exception) {
                throw new RestorationStopped('Le point de l’état actuel n’a pas pu être posé : le dossier n’a pas changé.', $exception);
            }
        }
        $point = $restoration->getPoint();
        if (null === $point || !$point->isReady()) {
            throw new RestorationStopped('Le point demandé n’est plus là : le dossier n’a pas changé.');
        }
        $dossierId = $dossier->getId() ?? throw new \LogicException('A saved dossier goes back.');
        $snapshotId = $point->getSnapshot()->getId() ?? throw new \LogicException('A saved point is restored.');

        $before = $this->files->keys($dossierId);
        $brought = array_map(
            static fn (string $key): string => DossierFiles::prefix($dossierId).substr($key, \strlen(DossierFiles::prefix($snapshotId))),
            $this->files->keys($snapshotId),
        );
        try {
            $report = $this->connection->transactional(function () use ($dossier, $point) {
                $this->eraser->eraseRows($dossier);

                return $this->copier->copy($point->getSnapshot(), $dossier, CopyKind::Snapshot);
            });
        } catch (\Throwable $exception) {
            $this->files->deleteKeys($dossierId, array_values(array_diff($brought, $before)));
            throw new RestorationStopped('Le retour au point n’a pas pu être fait : le dossier est resté dans l’état d’avant.', $exception);
        }
        $this->files->deleteKeys($dossierId, array_values(array_diff($before, $brought)));

        $this->entityManager->clear();
        $restoration = $this->restorations->find($restorationId) ?? throw new \LogicException('The return outlives its own work.');
        $restoration->done($now);
        $this->auditTrail->inDossier($restoration->getDossier(), AuditAction::DossierRestored, $restoration->getRequestedBy() ?? 'Peyra', \sprintf(
            'Dossier revenu au point « %s » posé le %s (date du dossier %s)',
            $restoration->getPointName(),
            $point->getCreatedAt()->format('d/m/Y à H:i'),
            $point->getDossierDate()->format('d/m/Y'),
        ), ['point' => $point->getId(), 'name' => $restoration->getPointName(), 'rows' => $report->rowCount(), 'files' => $report->files]);
        $this->entityManager->flush();
    }

    /** The return stopped: it says so, the trail too, and the dossier holds what it held. */
    public function fail(DossierRestoration $restoration, string $reason, \DateTimeImmutable $now): void
    {
        if (!$restoration->isRunning()) {
            return;
        }
        $restoration->fail($reason, $now);
        $this->auditTrail->inDossier($restoration->getDossier(), AuditAction::DossierRestorationFailed, 'Peyra', \sprintf('Le retour au point « %s » a échoué ; le dossier est resté dans l’état d’avant', $restoration->getPointName()), ['name' => $restoration->getPointName()]);
        $this->entityManager->flush();
    }
}
