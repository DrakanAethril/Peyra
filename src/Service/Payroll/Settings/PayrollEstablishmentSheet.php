<?php

declare(strict_types=1);

namespace App\Service\Payroll\Settings;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollEstablishment;
use App\Enum\Audit\AuditAction;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to the establishment of the payroll (Paie et social › Paramètres de paie ›
 * Établissement): saved whole, made at its first save, in the dossier's trail. The caller checks
 * DossierRightVoter::PAYROLL_SETTINGS and flushes.
 */
final readonly class PayrollEstablishmentSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PayrollEstablishmentRepository $establishments,
        private AuditTrail $auditTrail,
    ) {
    }

    /** False when nothing changed. */
    public function save(Dossier $dossier, PayrollEstablishmentFields $fields, User|string $by): bool
    {
        $establishment = $this->establishments->findOfDossier($dossier);
        if (null === $establishment) {
            $establishment = new PayrollEstablishment($dossier, $fields->headcount, $fields->workAccidentRate, $fields->mobilityRate, $fields->monthlyHours);
            $this->entityManager->persist($establishment);
        } elseif ($fields->headcount === $establishment->getHeadcount() && $fields->workAccidentRate === $establishment->getWorkAccidentRate()
            && $fields->mobilityRate === $establishment->getMobilityRate() && $fields->monthlyHours === $establishment->getMonthlyHours()) {
            return false;
        } else {
            $establishment->describe($fields->headcount, $fields->workAccidentRate, $fields->mobilityRate, $fields->monthlyHours);
        }
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::PayrollEstablishmentChanged,
            $by,
            'Établissement de la paie enregistré',
            ['dossier' => $dossier->getId(), 'headcount' => $fields->headcount, 'work_accident_rate' => $fields->workAccidentRate, 'mobility_rate' => $fields->mobilityRate, 'monthly_hours' => $fields->monthlyHours],
        );

        return true;
    }
}
