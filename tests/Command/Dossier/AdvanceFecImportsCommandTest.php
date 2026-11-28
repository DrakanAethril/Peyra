<?php

declare(strict_types=1);

namespace App\Tests\Command\Dossier;

use App\Entity\Accounting\FecImportJob;
use App\Entity\Identity\User;
use App\Enum\Accounting\FecImportState;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Service\Accounting\Fec\Import\FecImport;
use App\Service\Accounting\Fec\Import\FecImportDraft;
use App\Service\Accounting\Fec\Import\FecImportRunner;
use App\Service\Dossier\Library\EmptyTemplateDraft;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

/**
 * app:fec-import:advance (step 1.10b) runs every pass an import was left with - a worker stopped
 * after the answer -, and a pass that fails stops its import, said on the progress page, rather
 * than being retried every minute.
 */
final class AdvanceFecImportsCommandTest extends KernelTestCase
{
    use SavesDossiers;

    private const string HEADER = "JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise";

    public function testItFinishesWhatARequestLeftAndStopsAnImportThatFails(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $teacher = new User('uid:importeur', 'prof.importeur', 'Prof Importeur', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], new \DateTimeImmutable('2026-11-28'));
        $entityManager->persist($teacher);
        $entityManager->flush();

        $draft = new FecImportDraft();
        $draft->template = EmptyTemplateDraft::fromRequest(new Request(request: [
            'title' => 'Ravel, reprise',
            'denomination' => 'Boulangerie Ravel', 'legalForm' => 'sarl', 'capital' => '1000000', 'siret' => '99912345600011',
            'activity' => 'Boulangerie pâtisserie', 'street' => '3 place du Marché', 'postalCode' => '42600', 'city' => 'Montbrison',
            'vatRegime' => 'real_normal_monthly', 'profitTaxRegime' => 'is_real_normal',
            'exerciseStart' => '2026-01-01', 'exerciseEnd' => '2026-12-31', 'dossierDate' => '2026-01-31', 'chart' => 'pcg-2026',
        ]));
        $draft->fileName = 'FEC.txt';
        $draft->bytes = self::HEADER."\r\n"
            ."BQ\tBanque\t1\t20260120\t606300\tFournitures\t\t\tT1\t20260120\tPetit matériel\t40,00\t0,00\t\t\t20260131\t\t\r\n"
            ."BQ\tBanque\t1\t20260120\t512000\tBanque\t\t\tT1\t20260120\tPetit matériel\t0,00\t40,00\t\t\t20260131\t\t\r\n";
        $waiting = self::getContainer()->get(FecImport::class)->launch($draft, $teacher, new \DateTimeImmutable('2026-11-28 10:00'));
        self::assertNotNull($waiting);
        $waitingId = $waiting->getId();

        // A file that no longer reads: its first pass throws.
        $template = $this->savedDossier(DossierKind::Template, $teacher);
        $template->markImporting();
        $broken = new FecImportJob($teacher, $template, 'FEC.txt', 'pas un FEC', new \DateTimeImmutable('2026-01-01'), 1, 1, 0, 0, 0, 0, new \DateTimeImmutable('2026-11-28 10:00'));
        $entityManager->persist($broken);
        $entityManager->flush();
        $brokenId = $broken->getId();

        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find('app:fec-import:advance'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 import(s) de FEC traité(s).', $tester->getDisplay());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $done = $entityManager->find(FecImportJob::class, $waitingId);
        self::assertSame(FecImportState::Done, $done?->getState());
        self::assertFalse($done->getTemplate()?->isImporting());
        $stopped = $entityManager->find(FecImportJob::class, $brokenId);
        self::assertSame(FecImportState::Failed, $stopped?->getState());
        self::assertSame(FecImportRunner::FAILURE, $stopped->getFailure());
        self::assertTrue($stopped->getTemplate()?->isImporting(), 'Nothing is deleted on its own: the teacher relaunches or abandons.');

        $tester->execute([]);
        self::assertStringContainsString('0 import(s) de FEC traité(s).', $tester->getDisplay(), 'A stopped import is not retried every minute.');
    }
}
