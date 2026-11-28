<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\CopyKind;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\RestorePointOrigin;
use App\Repository\Dossier\DossierRestorationRepository;
use App\Repository\Dossier\RestorePointRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Copy\DossierCopier;
use App\Service\Dossier\Copy\DossierEraser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the restore points (design-ux.md § 4.9): pose one - a name, then its copy in the
 * background -, make that copy, say it failed, delete one. Who may: RestorePointVoter. Every
 * gesture is a line of the dossier's trail.
 *
 * A point's copy is DossierCopier's, CopyKind::Snapshot (the pending work kept), into a dossier of
 * kind Snapshot made empty when the point is asked for. It reads the dossier while its people may
 * go on working: the copy runs in one transaction, and each `INSERT … SELECT` takes the dossier's
 * rows of a table under a shared lock until it commits - a line written meanwhile waits the
 * fraction of a second the copy lasts, so the point is the dossier of one moment.
 */
final readonly class RestorePoints
{
    public const string FAILURE = 'La copie du dossier n’a pas pu être faite. Le dossier, lui, n’a pas changé.';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RestorePointRepository $points,
        private DossierRestorationRepository $restorations,
        private DossierCopier $copier,
        private DossierEraser $eraser,
        private AuditTrail $auditTrail,
        private RestoreQueue $queue,
    ) {
    }

    /**
     * A point posed by a person: named, counted against the limit, its copy queued.
     *
     * @throws RestoreRefused
     */
    public function pose(Dossier $dossier, string $name, User $by, \DateTimeImmutable $now): RestorePoint
    {
        $fault = RestorePointRules::nameFault($name);
        if (null !== $fault) {
            throw new RestoreRefused($fault, 'name');
        }
        $this->expectFree($dossier);
        if (RestorePointRules::limitReached($this->points->countByDossier($dossier))) {
            throw new RestoreRefused(RestorePointRules::LIMIT_REACHED, 'name');
        }
        $point = $this->create($dossier, $name, RestorePointOrigin::Manual, $by, $now);
        $this->entityManager->flush();
        $this->queue->point($point);

        return $point;
    }

    /**
     * A point and its empty copy, in preparation, with its line in the trail; the caller flushes
     * and has the copy made (Restorations for the point of the state a return leaves).
     */
    public function create(Dossier $dossier, string $name, RestorePointOrigin $origin, User $by, \DateTimeImmutable $now): RestorePoint
    {
        if (DossierKind::Snapshot === $dossier->getKind()) {
            throw new \LogicException('A restore point is never posed on a restore point.');
        }
        $snapshot = new Dossier(DossierKind::Snapshot, $dossier->getCompany(), $dossier->getDossierDate(), null, null, $now, $by);
        $this->entityManager->persist($snapshot);
        $point = new RestorePoint($dossier, $snapshot, $name, $origin, $by, $now);
        $this->entityManager->persist($point);
        $this->auditTrail->inDossier($dossier, AuditAction::RestorePointPosed, $by, \sprintf('Point de restauration « %s » posé', $point->getName()), [
            'name' => $point->getName(),
            'origin' => $origin->value,
        ]);

        return $point;
    }

    /**
     * Makes the point's copy, if it is still to make (the caller holds the dossier's lock,
     * RestoreRunner). An exception leaves the dossier untouched and the point in preparation: the
     * caller says it failed.
     */
    public function prepare(RestorePoint $point, \DateTimeImmutable $now): void
    {
        if (!$point->isPreparing()) {
            return;
        }
        $this->copier->copy($point->getDossier(), $point->getSnapshot(), CopyKind::Snapshot);
        $point->ready($now);
        $this->entityManager->flush();
    }

    /** The copy stopped: the point says so, and the trail. */
    public function fail(RestorePoint $point, \DateTimeImmutable $now): void
    {
        if (!$point->isPreparing()) {
            return;
        }
        $point->fail(self::FAILURE, $now);
        $this->auditTrail->inDossier($point->getDossier(), AuditAction::RestorePointFailed, 'Peyra', \sprintf('La copie du point de restauration « %s » a échoué ; le dossier n’a pas changé', $point->getName()), ['name' => $point->getName()]);
        $this->entityManager->flush();
    }

    /**
     * Deletes a point and its copy - never one being made, nor one a return is using.
     *
     * @throws RestoreRefused
     */
    public function delete(RestorePoint $point, User $by): void
    {
        if ($point->isPreparing()) {
            throw new RestoreRefused('Ce point est en préparation : vous pourrez le supprimer dans un instant.');
        }
        if ($this->restorations->usesPoint($point)) {
            throw new RestoreRefused('Le dossier revient à un point de restauration : patientez avant de supprimer celui-ci.');
        }
        $snapshot = $point->getSnapshot();
        $this->auditTrail->inDossier($point->getDossier(), AuditAction::RestorePointDeleted, $by, \sprintf('Point de restauration « %s » supprimé', $point->getName()), ['name' => $point->getName()]);
        $this->entityManager->remove($point);
        $this->entityManager->flush();
        $this->eraser->deleteDossier($snapshot);
    }

    /** @throws RestoreRefused while the dossier is going back to a point */
    public function expectFree(Dossier $dossier): void
    {
        if (null !== $this->restorations->findRunning($dossier)) {
            throw new RestoreRefused('Le dossier revient à un point de restauration : patientez un instant.');
        }
    }
}
