<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Hiring;

use App\Entity\Audit\AuditEvent;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\HiringDeclaration;
use App\Entity\Payroll\PayrollBody;
use App\Entity\Payroll\PayrollEstablishment;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Payroll\FormalityState;
use App\Enum\Payroll\PayrollBodyKind;
use App\Service\Dossier\DossierContext;
use App\Service\Payroll\Employee\EmployeeDraft;
use App\Service\Payroll\Employee\EmployeeSheet;
use App\Service\Payroll\Hiring\HiringFormalities;
use App\Service\Payroll\Hiring\PersonnelRegister;
use App\Service\Regulatory\RegulatorySync;
use App\Service\Shared\Portal\PortalFault;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Yanis Chabert's hire through the formalities' door (step 4.2b): a CDD from 01/04/2026, 07:30, born
 * in Feurs. The DPAE deposited on 15/03 is too early (from 24/03), on 30/03 accepted - the Urssaf
 * affiliation with it -; then the visit and the other affiliation recorded by hand.
 */
final class HiringFormalitiesTest extends KernelTestCase
{
    use SavesDossiers;

    public function testTheDpaeThenTheVisitAndTheAffiliations(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $container->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
        $student = new User('uid:ines', 'ines.morel', 'Inès Morel', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($student);
        $dossier = $this->savedDossier(DossierKind::Student, $student);
        $container->get(DossierContext::class)->enter($dossier);
        $entityManager->persist(new PayrollEstablishment($dossier, '8.69', '2.1', null, '151.67'));
        $entityManager->persist($urssaf = new PayrollBody($dossier, PayrollBodyKind::Urssaf, 'Urssaf (simulée)', 'U-1'));
        $entityManager->persist($pension = new PayrollBody($dossier, PayrollBodyKind::Retirement, 'Caisse de retraite (fictive)', 'R-1'));
        $entityManager->flush();
        $yanis = $this->yanis($dossier, $student);
        $formalities = $container->get(HiringFormalities::class);

        $refused = $formalities->declare($yanis, new \DateTimeImmutable('2026-03-15'), $student);
        self::assertIsArray($refused);
        self::assertSame(['DPAE-E04'], array_map(static fn (PortalFault $f): string => $f->code, $refused));
        self::assertNull($formalities->declaration($yanis), 'A rejection keeps nothing.');

        $declaration = $formalities->declare($yanis, new \DateTimeImmutable('2026-03-30'), $student);
        self::assertInstanceOf(HiringDeclaration::class, $declaration);
        $entityManager->flush();
        self::assertSame('DPAE-2026-000001', $declaration->getNumber());
        self::assertFalse($declaration->isLate());
        self::assertContains(['Date et heure d’embauche', '01/04/2026 à 07:30'], $declaration->getContent());
        self::assertContains(['Date et lieu de naissance', '09/05/2001 à Feurs (42)'], $declaration->getContent());
        self::assertSame([(int) $urssaf->getId() => '2026-03-30', (int) $pension->getId() => null], array_map(static fn (?\DateTimeImmutable $d): ?string => $d?->format('Y-m-d'), $formalities->affiliationDays($yanis)));

        $list = $formalities->checklist($yanis, new \DateTimeImmutable('2026-03-31'));
        self::assertSame([FormalityState::Done, FormalityState::ToDo, '2026-07-01', 1, 2], [$list->dpae, $list->visit, $list->visitDeadline->format('Y-m-d'), $list->affiliationsMissing, $list->openCount()]);

        self::assertTrue($formalities->record($yanis, new \DateTimeImmutable('2026-06-20'), [(int) $pension->getId() => new \DateTimeImmutable('2026-04-01')], $student));
        $entityManager->flush();
        self::assertSame(0, $formalities->checklist($yanis, new \DateTimeImmutable('2026-03-31'))->openCount());
        self::assertFalse($formalities->record($yanis, new \DateTimeImmutable('2026-06-20'), [(int) $pension->getId() => new \DateTimeImmutable('2026-04-01')], $student), 'Nothing changed.');
        self::assertTrue($formalities->record($yanis, new \DateTimeImmutable('2026-06-20'), [(int) $pension->getId() => null], $student));
        $entityManager->flush();
        self::assertSame(1, $formalities->checklist($yanis, new \DateTimeImmutable('2026-03-31'))->affiliationsMissing);

        $events = $entityManager->getRepository(AuditEvent::class);
        self::assertSame(1, $events->count(['action' => AuditAction::HiringDeclared]));
        self::assertSame(2, $events->count(['action' => AuditAction::HiringFormalitiesChanged]));

        // The register: the hire in order.
        $rows = PersonnelRegister::rows([$yanis], new \DateTimeImmutable('2026-03-31'));
        self::assertSame([1, 'Menuisier poseur', 'Ouvrier qualifié, niveau interne O3 (non-cadre)', 'CDD'], [$rows[0]['rank'], $rows[0]['job'], $rows[0]['qualification'], $rows[0]['contract']]);

        $this->expectException(\LogicException::class);
        $formalities->declare($yanis, new \DateTimeImmutable('2026-03-31'), $student);
    }

    private function yanis(\App\Entity\Dossier\Dossier $dossier, User $by): Employee
    {
        $draft = new EmployeeDraft();
        [$draft->registrationNumber, $draft->lastName, $draft->firstName, $draft->sex, $draft->birthDate, $draft->birthPlace] = ['S011', 'Chabert', 'Yanis', 'M', '2001-05-09', 'Feurs (42)'];
        [$draft->contractType, $draft->hiredOn, $draft->hireTime, $draft->contractEndsOn, $draft->fixedTermReason, $draft->trialPeriod] = ['fixed_term', '2026-04-01', '7h30', '2026-09-30', 'Accroissement temporaire d’activité', '14 jours'];
        [$draft->jobTitle, $draft->classification, $draft->status, $draft->workingTime] = ['Menuisier poseur', 'Ouvrier qualifié, niveau interne O3', 'non_executive', 'full_time'];
        $draft->baseSalary = 195_000;
        $sheet = self::getContainer()->get(EmployeeSheet::class);
        $employee = $sheet->create($dossier, $sheet->check($draft, $dossier) ?? throw new \LogicException(implode(' ', $draft->errors)), $by);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $employee;
    }
}
