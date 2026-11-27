<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Entity\ThirdParty\ThirdParty;
use App\Service\Shared\Csv\CsvText;

/**
 * The dossier's third parties as a CSV file, one line each, the file ThirdPartyImport reads back:
 * the nature, the conditions and the mode as words (client, fin_de_mois, virement), `oui`/`non`.
 */
final class ThirdPartyCsv
{
    public const array HEADER = [
        'code', 'nature', 'nom', 'particulier', 'compte', 'auxiliaire', 'siren', 'tva',
        'adresse', 'complement', 'code_postal', 'ville', 'pays', 'contact', 'email', 'telephone',
        'conditions', 'jours', 'jour', 'mode', 'note',
    ];

    /** @param list<ThirdParty> $thirdParties */
    public static function export(array $thirdParties): string
    {
        $rows = [self::HEADER];
        foreach ($thirdParties as $t) {
            $terms = $t->getPaymentTerms();
            $rows[] = [
                $t->getCode(), $t->getKind()->csvValue(), $t->getName(), $t->isIndividual() ? 'oui' : 'non',
                $t->getAccount()?->getNumber() ?? '', $t->getAuxiliaryCode() ?? '', $t->getSiren() ?? '', $t->getVatNumber() ?? '',
                $t->getStreet() ?? '', $t->getStreet2() ?? '', $t->getPostalCode() ?? '', $t->getCity() ?? '', $t->getCountry(),
                $t->getContactName() ?? '', $t->getEmail() ?? '', $t->getPhone() ?? '',
                $terms->getKind()->csvValue(), null === $terms->getDays() ? '' : (string) $terms->getDays(), null === $terms->getDayOfMonth() ? '' : (string) $terms->getDayOfMonth(),
                $t->getPaymentMethod()->csvValue(), $t->getNote() ?? '',
            ];
        }

        return CsvText::write($rows);
    }
}
