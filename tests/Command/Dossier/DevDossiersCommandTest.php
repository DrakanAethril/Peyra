<?php

declare(strict_types=1);

namespace App\Tests\Command\Dossier;

use App\Entity\Identity\Enrolment;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\ClassLevel;
use App\Enum\Identity\EnrolmentOrigin;
use App\Enum\Identity\EntryBasis;
use App\Repository\Dossier\DossierRepository;
use App\Service\Regulatory\RegulatorySync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** app:dev:dossiers gives each student placed this year their own Menuiserie Vasseur, once. */
final class DevDossiersCommandTest extends KernelTestCase
{
    public function testEachPlacedStudentGetsOneDossier(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:dev:dossiers'));
        // As in development, the millésimes are installed first: the demonstration's DPAE reads them (step 4.2b).
        self::getContainer()->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $at = new \DateTimeImmutable('2026-01-01');
        $year = new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-08-31'), $at);
        $year->markCurrent();
        $class = new SchoolClass($year, 'BTS CG 1', ClassLevel::First, $at);
        $manon = new User('uid:manon', 'manon.chevalier', 'Manon Chevalier', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], $at);
        foreach ([$year, $class, $manon, new Enrolment($manon, $class, EnrolmentOrigin::Directory, $at, null)] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 dossier créé', $tester->getDisplay());
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Chaque étudiant placé a déjà son dossier', $tester->getDisplay());

        $dossiers = self::getContainer()->get(DossierRepository::class)->findForStudent($manon);
        self::assertCount(1, $dossiers);
        self::assertSame($year->getId(), $dossiers[0]->getSchoolYear()?->getId(), 'The install clears the entity manager: compared by id.');
        self::assertTrue($dossiers[0]->getSourceTemplate()?->isTemplate());
    }
}
