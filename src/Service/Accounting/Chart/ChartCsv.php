<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

use App\Entity\Accounting\Account;
use App\Service\Shared\Csv\CsvText;

/** The plan of accounts as a CSV file (`numero;intitule;lettrable;collectif`), the one ChartImport reads back. */
final class ChartCsv
{
    public const array HEADER = ['numero', 'intitule', 'lettrable', 'collectif'];

    /** @param list<Account> $accounts */
    public static function export(array $accounts): string
    {
        $rows = [self::HEADER];
        foreach ($accounts as $account) {
            $rows[] = [$account->getNumber(), $account->getLabel(), $account->isLettrable() ? 'oui' : 'non', $account->isCollective() ? 'oui' : 'non'];
        }

        return CsvText::write($rows);
    }
}
