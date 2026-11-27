<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierDateChange;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door that moves a dossier's date (conception § 4): a teacher sets it, forwards to play a
 * month or back to replay one. The move is written to the dossier's history (the chip's « trois
 * derniers changements ») and to the audit trail, signed with the teacher's name. The caller
 * checks DossierVoter::SET_DATE and flushes.
 */
final readonly class DossierDates
{
    public function __construct(private EntityManagerInterface $entityManager, private AuditTrail $auditTrail)
    {
    }

    /** Null when the dossier is already at that date: nothing is written. */
    public function move(Dossier $dossier, \DateTimeImmutable $to, User $by, \DateTimeImmutable $at): ?DossierDateChange
    {
        $from = $dossier->getDossierDate();
        $to = $to->setTime(0, 0);
        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return null;
        }
        $change = new DossierDateChange($dossier, $from, $to, $at, $by);
        $this->entityManager->persist($change);
        $dossier->moveDate($to, $at);
        $this->auditTrail->record(
            AuditAction::DossierDateMoved,
            $by,
            'dossier',
            (string) $dossier->getId(),
            \sprintf('Date du dossier %s : du %s au %s', $dossier->getName(), $from->format('d/m/Y'), $to->format('d/m/Y')),
            ['dossier' => $dossier->getId(), 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );

        return $change;
    }
}
