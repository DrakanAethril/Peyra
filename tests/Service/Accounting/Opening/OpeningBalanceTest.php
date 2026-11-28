<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Opening;

use App\Entity\Identity\User;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Accounting\Entry\PendingEntries;
use App\Service\Accounting\Opening\OpeningBalance;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The opening balance of an exercise (step 1.6): the à-nouveaux journal's entries by account, and
 * the controls - the typing left unbalanced in the grid is the one place it does not balance.
 * Amounts reckoned by hand: 10 000,00 + 1 200,00 = 2 400,00 + 8 800,00.
 */
final class OpeningBalanceTest extends KernelTestCase
{
    use BuildsLedger;

    public function testAnOpeningBalanceByAccountAndItsControls(): void
    {
        $dossier = $this->ledgerDossier();
        $exercise = self::getContainer()->get(ExerciseRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-03-15'));
        self::assertNotNull($exercise);
        $openings = self::getContainer()->get(OpeningBalance::class);

        $empty = $openings->of($dossier, $exercise);
        self::assertSame('AN', $empty->journal?->getCode());
        self::assertSame([['state' => 'warning', 'text' => 'Aucun à-nouveau pour l’exercice 2026 : saisissez la balance d’ouverture dans le journal AN, datée du 01/01/2026.']], $empty->checks());

        $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026-001', ['512000 D 10000.00', '411000/C0019 D 1200.00', '401000/F0001 C 2400.00', '101300 C 8800.00'], true);
        $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026-002', ['607000 D 50.00', '512000 C 50.00']);
        $report = $openings->of($dossier, $exercise);

        self::assertSame(['101300', '401000', '411000', '512000', '607000'], array_map(static fn ($row): string => $row->account->getNumber(), $report->rows));
        self::assertSame([1_000_000, 5_000, 2], [$report->rows[3]->debit->cents, $report->rows[3]->credit->cents, $report->rows[3]->lines]);
        self::assertSame([1_125_000, 1_125_000], [$report->debit()->cents, $report->credit()->cents]);
        self::assertTrue($report->isBalanced());
        self::assertSame(1, $report->drafts());
        self::assertSame([
            ['state' => 'passed', 'text' => "Les écritures d’à-nouveaux sont équilibrées : 11\u{202F}250,00 au débit et au crédit."],
            ['state' => 'warning', 'text' => '1 écriture d’à-nouveaux au brouillard : validez-la pour arrêter la balance d’ouverture.'],
            ['state' => 'warning', 'text' => 'Les à-nouveaux reprennent 607000 : un compte de charges ou de produits se solde à la clôture, il ne se reprend pas.'],
        ], $report->checks());

        // A third entry typed in the grid, 85,00 short on the credit side.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $me = new User('uidNumber:9001', 'lucas.bernard', 'Lucas Bernard', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($me);
        $entityManager->flush();
        $journal = self::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, 'AN');
        $january = self::getContainer()->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-01-01'));
        self::assertNotNull($journal);
        self::assertNotNull($january);
        self::getContainer()->get(PendingEntries::class)->keep($journal, $january, $me, [
            new GridGroup('k1', null, '2026-01-01', 'AN-2026-003', [new GridLine('205000', '', 'Logiciel', 68_000), new GridLine('101300', '', 'Capital', 0, 59_500)]),
        ], null, new \DateTimeImmutable('2026-11-28 10:00'));
        $entityManager->flush();

        $pending = $openings->of($dossier, $exercise);
        self::assertFalse($pending->isBalanced());
        self::assertSame(8_500, $pending->gap()->cents);
        self::assertContains(['state' => 'blocking', 'text' => 'Saisie en attente de Lucas Bernard : 2 lignes, écart de 85,00 au débit. La balance d’ouverture ne tombe pas juste.'], $pending->checks());
    }
}
