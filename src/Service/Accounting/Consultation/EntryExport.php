<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Entity\Accounting\Entry;
use App\Service\Shared\Export\Column;
use App\Service\Shared\Export\ColumnKind;
use App\Service\Shared\Export\ExportTable;

/**
 * Entries as a spreadsheet reads them (lot 1, step 1.5): one row per line, the entry's date,
 * journal, document, number and state repeated - what « Recherche d'écritures » and the brouillard
 * export.
 */
final class EntryExport
{
    /** @param list<Entry> $entries lines loaded */
    public static function table(string $name, array $entries): ExportTable
    {
        $table = new ExportTable($name, [
            new Column('Date', ColumnKind::Day),
            new Column('Journal', ColumnKind::Code),
            new Column('Pièce', ColumnKind::Code, 16),
            new Column('Numéro', ColumnKind::Code, 18),
            new Column('État', width: 12),
            new Column('Compte', ColumnKind::Code),
            new Column('Tiers', ColumnKind::Code),
            new Column('Libellé', width: 40),
            new Column('Échéance', ColumnKind::Day),
            new Column('Débit', ColumnKind::AmountCell),
            new Column('Crédit', ColumnKind::AmountCell),
        ]);
        foreach ($entries as $entry) {
            foreach ($entry->getLines() as $line) {
                $party = $line->getThirdParty();
                $table->add([
                    $entry->getEntryDate(), $entry->getJournal()->getCode(), $entry->getDocument(), $entry->getNumberLabel(), $entry->getState()->label(),
                    $line->getAccount()->getNumber(), $party?->getAuxiliaryCode() ?? $party?->getCode(),
                    '' !== $line->getLabel() ? $line->getLabel() : $entry->getLabel(), $line->getDueDate(), $line->getDebit(), $line->getCredit(),
                ]);
            }
        }

        return $table;
    }
}
