<?php

declare(strict_types=1);

namespace App\Tests\Service\Banking;

use App\Entity\Accounting\Entry;
use App\Entity\Banking\BankStatement;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Banking\BankStatementStatus;
use App\Repository\Accounting\JournalRepository;
use App\Service\Banking\BankingRefused;
use App\Service\Banking\Reconciliation\Pointings;
use App\Service\Banking\Reconciliation\ReconciliationBoard;
use App\Service\Banking\Reconciliation\ReconciliationItem;
use App\Service\Banking\Statement\BankStatements;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The bank's doors (step 2.6a) in BuildsLedger's dossier (BQ on 512000, date 15/03/2026, level
 * « Avertissements »): a statement imported whole or not at all, pointed by letters - a selection
 * that does not meet refused -, the entry of a fee written through the gateway and pointed with its
 * line, the reconciliation validated with a warning while a line is left, refused to the pointing
 * once validated, reopened.
 */
final class PointingsTest extends KernelTestCase
{
    use BuildsLedger;

    public function testAStatementIsImportedPointedAndReconciled(): void
    {
        $dossier = $this->ledgerDossier();
        $dumas = $this->entry($dossier, 'BQ', '2026-03-03', 'VIR-DUMA', ['512000 D 4320.00', '411000/C0019 C 4320.00'], true);
        $faure = $this->entry($dossier, 'BQ', '2026-03-06', 'CHQ-7702214', ['401000/F0001 D 1260.00', '512000 C 1260.00'], true);
        $scierie = $this->entry($dossier, 'BQ', '2026-03-12', 'CHQ-7702219', ['401000/F0001 D 3248.40', '512000 C 3248.40']);
        $journal = self::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, 'BQ') ?? throw new \LogicException('BQ');
        $statements = self::getContainer()->get(BankStatements::class);
        $pointings = self::getContainer()->get(Pointings::class);
        $board = self::getContainer()->get(ReconciliationBoard::class);

        $defaults = $statements->defaults($journal);
        self::assertSame([1, '2026-03-01', '2026-03-31', 0], [$defaults['number'], $defaults['from']->format('Y-m-d'), $defaults['to']->format('Y-m-d'), $defaults['opening']->cents]);

        // One line outside the period: nothing is imported.
        try {
            $statements->import($journal, 1, $defaults['from'], $defaults['to'], Money::zero(), "03/03/2026;VIR SEPA DUMAS;;4320,00\n02/04/2026;FRAIS;24,00;\n", 'releve.csv', 'test');
            self::fail('A line outside the statement’s days is refused.');
        } catch (BankingRefused $refused) {
            self::assertSame(['line.2'], array_keys($refused->errors));
        }

        $statement = $statements->import($journal, 1, $defaults['from'], $defaults['to'], Money::zero(), "Date;Libellé;Débit;Crédit\n03/03/2026;VIR SEPA DUMAS AGENCEMENT;;4 320,00\n09/03/2026;CHQ 7702214;1 260,00;\n10/03/2026;FRAIS TENUE DE COMPTE;24,00;\n", 'releve.csv', 'test');
        self::assertSame([3, 303_600], [\count($statement->getLines()), $statement->getClosingBalance()->cents]);
        [$vir, $cheque, $fees] = $statement->getLines();

        try {
            $statements->import($journal, 2, new \DateTimeImmutable('2026-03-20'), new \DateTimeImmutable('2026-04-19'), Money::cents(303_600), "21/03/2026;X;1,00;\n", 'b.csv', 'test');
            self::fail('Two statements never cover the same day.');
        } catch (BankingRefused $refused) {
            self::assertSame(['from'], array_keys($refused->errors));
        }

        $level = Assistance::at(AssistanceLevel::Warnings);
        self::assertSame(BankStatementStatus::ToReconcile, $board->view($statement, $level)->status());
        self::assertSame('A', $pointings->point($statement, [(int) $vir->getId()], [(int) $dumas->getId()], 'test'));
        self::assertSame('B', $pointings->point($statement, [(int) $cheque->getId()], [(int) $faure->getId()], 'test'));
        try {
            $pointings->point($statement, [(int) $fees->getId()], [(int) $scierie->getId()], 'test');
            self::fail('A selection whose two sides do not meet is refused.');
        } catch (BankingRefused $refused) {
            self::assertStringStartsWith('La sélection ne tombe pas juste', $refused->getMessage());
        }

        // 4 320,00 − 1 260,00 − 3 248,40 = − 188,40 in the books; − 188,40 − 24,00 = − 212,40; 3 036,00 − 3 248,40 = − 212,40.
        $view = $board->view($statement, $level);
        self::assertSame(BankStatementStatus::InProgress, $view->status());
        self::assertSame([-18_840, -21_240, -21_240], [$view->report->accountBalance->cents, $view->report->rectifiedBooks()->cents, $view->report->rectifiedBank()->cents]);
        self::assertSame([(int) $fees->getId()], array_map(static fn (ReconciliationItem $item): int => $item->id, $view->report->toPost));
        self::assertSame([(int) $scierie->getId()], array_map(static fn (ReconciliationItem $item): int => $item->id, $view->report->outstanding));

        // At « Avertissements » the line left is a warning, not a refusal.
        self::assertSame('Une ligne du relevé n’est ni pointée ni comptabilisée (24,00).', $pointings->validate($statement, 'test'));
        self::assertTrue($statement->isReconciled());
        try {
            $pointings->unpoint($statement, 'B', 'test');
            self::fail('A validated reconciliation is pointed no more.');
        } catch (BankingRefused $refused) {
            self::assertStringContainsString('rouvrez-le', $refused->getMessage());
        }
        $pointings->reopen($statement, 'test');
        self::assertFalse($statement->isReconciled());

        // The fee's entry, written through the gateway and pointed with its line.
        $entry = $pointings->generate($statement, (int) $fees->getId(), '627800', null, '', 'test');
        self::assertSame(['BQ', '2026-03-10', 'RLV1-03', AccountingModule::Banking->value, false], [$entry->getJournal()->getCode(), $entry->getEntryDate()->format('Y-m-d'), $entry->getDocument(), $entry->getSource(), $entry->isValidated()]);
        self::assertSame([['627800', 2_400, 0], ['512000', 0, 2_400]], self::lines($entry));
        $view = $board->view($statement, $level);
        self::assertSame('C', $view->lineLetter($fees));
        self::assertSame([], $view->report->toPost);
        self::assertTrue($view->report->gap()->isZero());
        self::assertNull($pointings->validate($statement, 'test'));

        try {
            $statements->delete($statement, 'test');
            self::fail('A statement with pointings stays.');
        } catch (BankingRefused) {
        }
        $pointings->reopen($statement, 'test');
        self::assertSame(2, $pointings->unpoint($statement, 'C', 'test'));
        self::assertNull($this->freshLetter($statement, 2));
    }

    /** @return list<array{string, int, int}> */
    private static function lines(Entry $entry): array
    {
        return array_map(static fn ($line): array => [$line->getAccount()->getNumber(), $line->getDebit()->cents, $line->getCredit()->cents], $entry->getLines());
    }

    private function freshLetter(BankStatement $statement, int $index): ?string
    {
        return $statement->getLines()[$index]->getLetter();
    }
}
