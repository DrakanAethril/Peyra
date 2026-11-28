<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll;

use App\Entity\Audit\AuditEvent;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollEstablishment;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Payroll\EmployeeState;
use App\Repository\Payroll\EmployeeRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Payroll\Employee\EmployeeDraft;
use App\Service\Payroll\Employee\EmployeeSheet;
use App\Service\Payroll\Employee\EmploymentHistory;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An employee through the fiche's door (step 4.2a): Julien Roche, hired on 02/03/2015 at 2 250,00,
 * raised to 2 380,00 from 01/04/2026 - the dossier's date is 15/03/2026, so the raise is to come.
 */
final class EmployeeSheetTest extends KernelTestCase
{
    use SavesDossiers;

    public function testTheTermsFromADayAreTheHistory(): void
    {
        [$dossier, $student] = $this->setUpDossier();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $sheet = self::getContainer()->get(EmployeeSheet::class);

        $blank = $sheet->blank($dossier);
        self::assertSame(['S001', 'permanent', '2026-03-15', 'full_time'], [$blank->registrationNumber, $blank->contractType, $blank->hiredOn, $blank->workingTime]);

        $draft = self::julien();
        $draft->termsFrom = '2020-01-01';
        $fields = $sheet->check($draft, $dossier);
        self::assertNotNull($fields, implode(' ', $draft->errors));
        self::assertSame('2015-03-02', $fields->termsFrom->format('Y-m-d'), 'A new employee’s terms start on the day of hire.');
        $julien = $sheet->create($dossier, $fields, $student);
        $entityManager->flush();
        self::assertSame('S006', $sheet->blank($dossier)->registrationNumber, 'The number after the highest one.');

        // The raise from 01/04/2026: a new row of the history.
        $draft = EmployeeDraft::of($julien, $julien->termsAt($dossier->getDossierDate()));
        [$draft->termsFrom, $draft->jobTitle, $draft->baseSalary] = ['2026-04-01', 'Pilote du centre d’usinage', 238_000];
        $fields = $sheet->check($draft, $dossier, $julien);
        self::assertNotNull($fields, implode(' ', $draft->errors));
        self::assertTrue($sheet->update($julien, $fields, $student));
        $entityManager->flush();
        self::assertCount(2, $julien->getTerms());
        self::assertSame(225_000, $julien->termsAt(new \DateTimeImmutable('2026-03-31'))->getBaseSalary()->cents);
        self::assertSame(238_000, $julien->termsAt(new \DateTimeImmutable('2026-04-01'))->getBaseSalary()->cents);
        self::assertSame(225_000, $julien->termsAt(new \DateTimeImmutable('2010-01-01'))->getBaseSalary()->cents, 'Before the hire: the first terms.');
        self::assertSame('14.8348', (string) $julien->getTerms()[0]->getHourlyRate(), '2 250,00 / 151,67 = 14,83484…');

        $history = EmploymentHistory::of($julien, $dossier->getDossierDate());
        self::assertSame(['current', 'upcoming'], array_column($history, 'when'));
        self::assertSame(['Embauche'], $history[0]['changes']);
        self::assertSame(['Emploi : Menuisier d’atelier qualifié → Pilote du centre d’usinage', "Salaire de base : 2\u{202F}250,00 → 2\u{202F}380,00"], $history[1]['changes']);

        // The same day again corrects it, without a new row; nothing changed writes nothing.
        $draft = EmployeeDraft::of($julien, $julien->getTerms()[1]);
        $draft->baseSalary = 239_000;
        $fields = $sheet->check($draft, $dossier, $julien);
        self::assertNotNull($fields);
        self::assertTrue($sheet->update($julien, $fields, $student));
        $entityManager->flush();
        self::assertSame(['2015-03-02', '2026-04-01'], array_map(static fn ($t): string => $t->getEffectiveFrom()->format('Y-m-d'), $julien->getTerms()));
        self::assertSame(239_000, $julien->getTerms()[1]->getBaseSalary()->cents);
        $draft = EmployeeDraft::of($julien, $julien->getTerms()[1]);
        $fields = $sheet->check($draft, $dossier, $julien);
        self::assertNotNull($fields);
        self::assertFalse($sheet->update($julien, $fields, $student));

        // The hire moves, and its terms with it; never on or after a later change.
        $draft = EmployeeDraft::of($julien, $julien->getTerms()[0]);
        $draft->hiredOn = '2015-03-09';
        $fields = $sheet->check($draft, $dossier, $julien);
        self::assertNotNull($fields, implode(' ', $draft->errors));
        $sheet->update($julien, $fields, $student);
        $entityManager->flush();
        self::assertSame(['2015-03-09', '2026-04-01'], array_map(static fn ($t): string => $t->getEffectiveFrom()->format('Y-m-d'), $julien->getTerms()));
        $draft = EmployeeDraft::of($julien, $julien->getTerms()[0]);
        $draft->hiredOn = '2026-04-01';
        self::assertNull($sheet->check($draft, $dossier, $julien));
        self::assertSame('Les conditions du contrat changent le 01/04/2026 : la date d’entrée précède ce jour.', $draft->error('hiredOn'));

        // The matricule is the dossier's own.
        $other = self::julien();
        $other->lastName = 'Brun';
        self::assertNull($sheet->check($other, $dossier));
        self::assertSame('Le matricule S005 est déjà celui de Julien Roche.', $other->error('registrationNumber'));

        // The hire's terms stay; a later one goes.
        self::assertSame('Les conditions de l’embauche ne se suppriment pas : corrigez-les.', EmployeeSheet::termsDeletionRefusal($julien->getTerms()[0]));
        $sheet->deleteTerms($julien->getTerms()[1], $student);
        $entityManager->flush();
        self::assertSame(['2015-03-09'], array_map(static fn ($t): string => $t->getEffectiveFrom()->format('Y-m-d'), $julien->getTerms()));
        self::assertSame(EmployeeState::Present, $julien->stateAt($dossier->getDossierDate()));
        self::assertSame(EmployeeState::Upcoming, $julien->stateAt(new \DateTimeImmutable('2015-03-08')));

        $sheet->delete($julien, $student);
        $entityManager->flush();
        self::assertSame([], self::getContainer()->get(EmployeeRepository::class)->findByDossier($dossier));
        $events = $entityManager->getRepository(AuditEvent::class);
        self::assertSame(1, $events->count(['action' => AuditAction::EmployeeCreated]));
        self::assertSame(4, $events->count(['action' => AuditAction::EmployeeChanged]));
        self::assertSame(1, $events->count(['action' => AuditAction::EmployeeDeleted]));
    }

    /** @return array{Dossier, User} */
    private function setUpDossier(): array
    {
        self::bootKernel();
        $student = new User('uid:ines', 'ines.morel', 'Inès Morel', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        self::getContainer()->get(EntityManagerInterface::class)->persist($student);
        $dossier = $this->savedDossier(DossierKind::Student, $student);
        self::getContainer()->get(DossierContext::class)->enter($dossier);
        self::getContainer()->get(EntityManagerInterface::class)->persist(new PayrollEstablishment($dossier, '8.69', '2.1', null, '151.67'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return [$dossier, $student];
    }

    private static function julien(): EmployeeDraft
    {
        $draft = new EmployeeDraft();
        [$draft->registrationNumber, $draft->lastName, $draft->firstName, $draft->sex, $draft->birthDate, $draft->nir] = ['S005', 'Roche', 'Julien', 'M', '1990-09-05', '190094214723300'];
        [$draft->contractType, $draft->hiredOn] = ['permanent', '2015-03-02'];
        [$draft->jobTitle, $draft->classification, $draft->status, $draft->workingTime] = ['Menuisier d’atelier qualifié', 'Ouvrier hautement qualifié, niveau interne O4', 'non_executive', 'full_time'];
        $draft->baseSalary = 225_000;
        $draft->withholdingRate = '2,9';

        return $draft;
    }
}
