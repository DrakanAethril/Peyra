<?php

declare(strict_types=1);

namespace App\Service\Payroll\Hiring;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\EmployeeAffiliation;
use App\Entity\Payroll\HiringDeclaration;
use App\Entity\Payroll\PayrollBody;
use App\Enum\Audit\AuditAction;
use App\Enum\Payroll\PayrollBodyKind;
use App\Repository\Dossier\ExerciseRepository;
use App\Repository\Payroll\EmployeeAffiliationRepository;
use App\Repository\Payroll\HiringDeclarationRepository;
use App\Repository\Payroll\PayrollBodyRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Payroll\Employee\Nir;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Portal\PortalFault;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to an employee's hiring formalities (Paie et social › Salariés › Formalités, step
 * 4.2b): where they stand (HiringChecklist, for one employee or the whole list in a fixed number
 * of queries), the DPAE as the simulated social portal receives it, its deposit - checked by
 * DpaeControls, accepted with an acknowledgement number and the Urssaf affiliation of that day, or
 * rejected and nothing written -, and the visit's day and the affiliations recorded by hand. Each
 * gesture goes to the audit trail. Runs inside the dossier's context; the caller checks
 * DossierRightVoter::PAYROLL_ENTER to write and flushes.
 */
final readonly class HiringFormalities
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExerciseRepository $exercises,
        private HiringDeclarationRepository $declarations,
        private EmployeeAffiliationRepository $affiliations,
        private PayrollBodyRepository $bodies,
        private RegulatoryReader $regulatory,
        private AuditTrail $auditTrail,
    ) {
    }

    public function declaration(Employee $employee): ?HiringDeclaration
    {
        return $this->declarations->findOfEmployee($employee);
    }

    /** @return list<PayrollBody> */
    public function bodies(Dossier $dossier): array
    {
        return $this->bodies->findByDossier($dossier);
    }

    /** @return array<int, ?\DateTimeImmutable> by body id of the dossier, the employee's affiliation day or null */
    public function affiliationDays(Employee $employee): array
    {
        return self::days($this->bodies($employee->getDossier()), $this->affiliations->findOfEmployee($employee));
    }

    public function checklist(Employee $employee, \DateTimeImmutable $today): HiringChecklist
    {
        $dossier = $employee->getDossier();

        return self::listOf($employee, $this->firstExerciseStart($dossier), $this->declaration($employee)?->getDeclaredOn(), $this->affiliationDays($employee), $today, $this->delays($today));
    }

    /**
     * @param list<Employee> $employees
     *
     * @return array<int, HiringChecklist> by employee id
     */
    public function checklists(Dossier $dossier, array $employees, \DateTimeImmutable $today): array
    {
        $start = $this->firstExerciseStart($dossier);
        $delays = $this->delays($today);
        $bodies = $this->bodies($dossier);
        $declarations = $this->declarations->findByEmployee($dossier);
        $affiliations = $this->affiliations->findByEmployee($dossier);
        $lists = [];
        foreach ($employees as $employee) {
            $id = (int) $employee->getId();
            $lists[$id] = self::listOf($employee, $start, ($declarations[$id] ?? null)?->getDeclaredOn(), self::days($bodies, $affiliations[$id] ?? []), $today, $delays);
        }

        return $lists;
    }

    public function delays(\DateTimeImmutable $day): HiringDelays
    {
        return HiringDelays::from($this->regulatory->at($day));
    }

    /** @return list<array{0: string, 1: string}> the DPAE as the portal receives it: the employer, the employee, the hire */
    public static function content(Employee $employee): array
    {
        $company = $employee->getDossier()->getCompany();
        $first = $employee->getTerms()[0] ?? null;
        $contract = $employee->getContractType()->longLabel().(null === $employee->getContractEndsOn() ? '' : ', jusqu’au '.FrenchFormat::date($employee->getContractEndsOn()));

        return [
            ['Employeur', $company->getName()],
            ['SIRET de l’établissement', $company->getSiret()],
            ['Code APE', $company->getNafCode() ?? '—'],
            ['Adresse de l’établissement', implode(', ', array_filter([$company->getStreet(), $company->getStreet2(), $company->getPostalCode().' '.$company->getCity()]))],
            ['Nom et prénom du salarié', $employee->getLastName().' '.$employee->getFirstName()],
            ['Sexe', $employee->getSex()->label()],
            ['Date et lieu de naissance', FrenchFormat::date($employee->getBirthDate()).' à '.($employee->getBirthPlace() ?? '—')],
            ['N° de sécurité sociale', null === $employee->getNir() ? 'Non immatriculé' : Nir::format($employee->getNir())],
            ['Date et heure d’embauche', FrenchFormat::date($employee->getHiredOn()).' à '.($employee->getHireTime() ?? '—')],
            ['Nature du contrat', $contract],
            ['Période d’essai', $employee->getTrialPeriod() ?? 'Aucune'],
            ['Emploi', null === $first ? '—' : $first->getJobTitle()],
        ];
    }

    /** @return list<PortalFault> what the portal would refuse in a deposit that day; empty: it accepts */
    public function faults(Employee $employee, \DateTimeImmutable $on): array
    {
        return DpaeControls::check($employee->getDossier()->getCompany()->getNafCode(), $employee->getBirthPlace(), $employee->getHireTime(), $employee->getHiredOn(), $on, $this->delays($on)->dpaeEarliestDays);
    }

    /**
     * Deposits the DPAE on the dossier's day `$on`: accepted - its acknowledgement, and the Urssaf
     * bodies' affiliation that day where none is recorded - or the list of the portal's motives,
     * nothing written. One DPAE per employee: a second is a programming error (the screen offers none).
     *
     * @return HiringDeclaration|list<PortalFault>
     */
    public function declare(Employee $employee, \DateTimeImmutable $on, User|string $by): HiringDeclaration|array
    {
        if (null !== $this->declaration($employee)) {
            throw new \LogicException(\sprintf('Employee %s is already declared.', $employee->getRegistrationNumber()));
        }
        $faults = $this->faults($employee, $on);
        if ([] !== $faults) {
            return $faults;
        }
        $dossier = $employee->getDossier();
        $number = \sprintf('DPAE-%s-%06d', $on->format('Y'), $this->declarations->countOfDossier($dossier) + 1);
        $declaration = new HiringDeclaration($employee, $number, $on, DpaeControls::isLate($employee->getHiredOn(), $on), self::content($employee));
        $this->entityManager->persist($declaration);
        $recorded = $this->affiliations->findOfEmployee($employee);
        foreach ($this->bodies($dossier) as $body) {
            if (PayrollBodyKind::Urssaf === $body->getKind() && null === self::days([$body], $recorded)[(int) $body->getId()]) {
                $this->entityManager->persist(new EmployeeAffiliation($employee, $body, $on));
            }
        }
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::HiringDeclared,
            $by,
            \sprintf('DPAE de %s %s déposée sur le portail social (accusé %s%s)', $employee->getRegistrationNumber(), $employee->getFullName(), $number, $declaration->isLate() ? ', après l’embauche' : ''),
            ['dossier' => $dossier->getId(), 'number' => $employee->getRegistrationNumber(), 'declaration' => $number],
        );

        return $declaration;
    }

    /**
     * Records the visit's day and the affiliations (by body id of the dossier: a day, or null to
     * forget one). False when nothing changed (nothing is written).
     *
     * @param array<int, ?\DateTimeImmutable> $affiliations
     */
    public function record(Employee $employee, ?\DateTimeImmutable $visitOn, array $affiliations, User|string $by): bool
    {
        $changes = [];
        if ($visitOn != $employee->getInformationVisitOn()) {
            $employee->planInformationVisit($visitOn);
            $changes[] = null === $visitOn ? 'visite d’information effacée' : 'visite d’information le '.FrenchFormat::date($visitOn);
        }
        $existing = [];
        foreach ($this->affiliations->findOfEmployee($employee) as $affiliation) {
            $existing[(int) $affiliation->getBody()->getId()] = $affiliation;
        }
        foreach ($this->bodies($employee->getDossier()) as $body) {
            $id = (int) $body->getId();
            if (!\array_key_exists($id, $affiliations)) {
                continue;
            }
            $day = $affiliations[$id];
            $current = $existing[$id] ?? null;
            if (null === $day && null !== $current) {
                $this->entityManager->remove($current);
                $changes[] = 'affiliation '.$body->getName().' effacée';
            } elseif (null !== $day && null === $current) {
                $this->entityManager->persist(new EmployeeAffiliation($employee, $body, $day));
                $changes[] = 'affilié à '.$body->getName().' le '.FrenchFormat::date($day);
            } elseif (null !== $day && $day != $current->getAffiliatedOn()) {
                $current->affiliateOn($day);
                $changes[] = 'affilié à '.$body->getName().' le '.FrenchFormat::date($day);
            }
        }
        if ([] === $changes) {
            return false;
        }
        $this->auditTrail->inDossier(
            $employee->getDossier(),
            AuditAction::HiringFormalitiesChanged,
            $by,
            \sprintf('Formalités de %s : %s', $employee->getRegistrationNumber(), implode(', ', $changes)),
            ['dossier' => $employee->getDossier()->getId(), 'number' => $employee->getRegistrationNumber()],
        );

        return true;
    }

    private function firstExerciseStart(Dossier $dossier): ?\DateTimeImmutable
    {
        $all = $this->exercises->findByDossier($dossier);

        return [] === $all ? null : end($all)->getStartsOn();
    }

    /** @param array<int, ?\DateTimeImmutable> $days */
    private static function listOf(Employee $employee, ?\DateTimeImmutable $start, ?\DateTimeImmutable $declaredOn, array $days, \DateTimeImmutable $today, HiringDelays $delays): HiringChecklist
    {
        return HiringChecklist::of($employee->getHiredOn(), $employee->getContractType(), $employee->getBirthDate(), $start, $declaredOn, $employee->getInformationVisitOn(), $days, $today, $delays);
    }

    /**
     * @param list<PayrollBody>         $bodies
     * @param list<EmployeeAffiliation> $affiliations
     *
     * @return array<int, ?\DateTimeImmutable>
     */
    private static function days(array $bodies, array $affiliations): array
    {
        $days = [];
        foreach ($bodies as $body) {
            $days[(int) $body->getId()] = null;
        }
        foreach ($affiliations as $affiliation) {
            $id = (int) $affiliation->getBody()->getId();
            if (\array_key_exists($id, $days)) {
                $days[$id] = $affiliation->getAffiliatedOn();
            }
        }

        return $days;
    }
}
