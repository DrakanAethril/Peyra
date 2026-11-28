<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Enum\Accounting\AgeBand;
use App\Service\Accounting\Consultation\EntryExport;
use App\Service\Shared\Export\Column;
use App\Service\Shared\Export\ColumnKind;
use App\Service\Shared\Export\ExportTable;

/**
 * The editions as a spreadsheet reads them (lot 1, step 1.5b): one row per line of the state - a
 * line of an entry, a movement of an account, an account, a third party -, no subtotal (the
 * spreadsheet adds), the same columns as the screen, every amount a number.
 */
final class EditionExports
{
    public static function journals(JournalReport $report): ExportTable
    {
        return EntryExport::table('Journaux', $report->entries());
    }

    public static function generalLedger(GeneralLedgerReport $report, EditionWindow $window): ExportTable
    {
        $table = new ExportTable('Grand livre', [
            new Column('Compte', ColumnKind::Code),
            new Column('Intitulé', width: 32),
            new Column('Date', ColumnKind::Day),
            new Column('Journal', ColumnKind::Code),
            new Column('Pièce', ColumnKind::Code, 16),
            new Column('Numéro', ColumnKind::Code, 18),
            new Column('Tiers', ColumnKind::Code),
            new Column('Libellé', width: 40),
            new Column('Débit', ColumnKind::AmountCell),
            new Column('Crédit', ColumnKind::AmountCell),
            new Column('Solde (débit − crédit)', ColumnKind::Amount, 22),
        ]);
        foreach ($report->accounts as $account) {
            $number = $account->account->getNumber();
            $label = $account->account->getLabel();
            if ($report->hasOpening) {
                $table->add([$number, $label, $window->eve(), null, null, null, null, 'Solde au '.$window->eve()->format('d/m/Y'), $account->before->debit, $account->before->credit, $account->before->balance()]);
            }
            foreach ($account->movements as $movement) {
                $line = $movement->line;
                $entry = $line->getEntry();
                $party = $line->getThirdParty();
                $table->add([
                    $number, $label, $entry->getEntryDate(), $entry->getJournal()->getCode(), $entry->getDocument(), $entry->getNumberLabel() ?? 'Brouillard',
                    $party?->getAuxiliaryCode() ?? $party?->getCode(), '' !== $line->getLabel() ? $line->getLabel() : $entry->getLabel(),
                    $line->getDebit(), $line->getCredit(), $movement->balanceAfter,
                ]);
            }
        }

        return $table;
    }

    public static function trialBalance(BalanceReport $report, EditionWindow $window): ExportTable
    {
        $table = new ExportTable('Balance', [
            new Column('Compte', ColumnKind::Code),
            new Column('Intitulé', width: 40),
            ...self::balanceColumns($report, $window),
        ]);
        foreach ($report->groups as $group) {
            foreach ($group->lines as $line) {
                $account = $line->account ?? throw new \LogicException('A line of the general balance is an account.');
                $table->add([$account->getNumber(), $account->getLabel(), ...self::balanceCells($report, $line)]);
            }
        }

        return $table;
    }

    public static function thirdPartyBalance(BalanceReport $report, EditionWindow $window): ExportTable
    {
        $table = new ExportTable('Balance auxiliaire', [
            new Column('Nature', width: 14),
            new Column('Code', ColumnKind::Code),
            new Column('Compte', ColumnKind::Code),
            new Column('Nom', width: 36),
            ...self::balanceColumns($report, $window),
        ]);
        foreach ($report->groups as $group) {
            foreach ($group->lines as $line) {
                $party = $line->thirdParty ?? throw new \LogicException('A line of the third parties’ balance is a third party.');
                $table->add([$party->getKind()->label(), $party->getCode(), $party->getAuxiliaryCode() ?? $party->getAccount()?->getNumber(), $party->getName(), ...self::balanceCells($report, $line)]);
            }
        }

        return $table;
    }

    public static function agedBalance(AgedBalanceReport $report): ExportTable
    {
        $columns = [
            new Column('Code', ColumnKind::Code),
            new Column('Compte', ColumnKind::Code),
            new Column('Nom', width: 36),
            new Column('Solde', ColumnKind::Amount),
        ];
        foreach (AgeBand::cases() as $band) {
            $columns[] = new Column($band->label(), ColumnKind::AmountCell, 16);
        }
        $table = new ExportTable('Balance âgée '.mb_strtolower($report->kind->plural()), $columns);
        foreach ($report->rows as $row) {
            $party = $row->thirdParty ?? throw new \LogicException('A row of the aged balance is a third party.');
            $table->add([$party->getCode(), $party->getAuxiliaryCode() ?? $party->getAccount()?->getNumber(), $party->getName(), $row->total, ...array_map($row->band(...), AgeBand::cases())]);
        }

        return $table;
    }

    /** @return list<Column> */
    private static function balanceColumns(BalanceReport $report, EditionWindow $window): array
    {
        return [
            ...($report->hasOpening ? [new Column('Solde au '.$window->eve()->format('d/m/Y').' (débit − crédit)', ColumnKind::Amount, 24)] : []),
            new Column('Mouvements débit', ColumnKind::AmountCell, 18),
            new Column('Mouvements crédit', ColumnKind::AmountCell, 18),
            new Column('Solde débiteur', ColumnKind::AmountCell, 16),
            new Column('Solde créditeur', ColumnKind::AmountCell, 16),
        ];
    }

    /** @return list<\App\Service\Shared\Money> */
    private static function balanceCells(BalanceReport $report, BalanceLine $line): array
    {
        return [
            ...($report->hasOpening ? [$line->opening] : []),
            $line->movements->debit,
            $line->movements->credit,
            $line->closingDebit,
            $line->closingCredit,
        ];
    }
}
