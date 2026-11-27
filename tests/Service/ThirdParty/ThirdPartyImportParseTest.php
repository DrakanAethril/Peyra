<?php

declare(strict_types=1);

namespace App\Tests\Service\ThirdParty;

use App\Service\Shared\Csv\CsvRefused;
use App\Service\ThirdParty\ThirdPartyImport;
use PHPUnit\Framework\TestCase;

/** How a CSV file of third parties is read before any check against the dossier. */
final class ThirdPartyImportParseTest extends TestCase
{
    public function testColumnsAreFoundByTheirNamesInAnyOrder(): void
    {
        [$rows, $hasAccount] = ThirdPartyImport::parse("Nom;Code;Nature;Code postal;Conditions;Jours;Mode\r\nScierie du Haut-Forez;F0001;Fournisseur;42600;fin de mois plus;45;Chèque\r\n");

        self::assertFalse($hasAccount);
        self::assertCount(1, $rows);
        $draft = $rows[0]['draft'];
        self::assertNull($rows[0]['refusal']);
        self::assertSame(2, $rows[0]['line']);
        self::assertSame(['F0001', 'supplier', 'Scierie du Haut-Forez', '42600', 'end_of_month_net', '45', 'cheque'], [$draft->code, $draft->kind, $draft->name, $draft->postalCode, $draft->termKind, $draft->termDays, $draft->paymentMethod]);
    }

    public function testDefaultsAndRefusalsOfALine(): void
    {
        [$rows, $hasAccount] = ThirdPartyImport::parse("code;nature;nom;compte;particulier;conditions;mode\nC1;client;Girard;411000;oui;;\nC2;ami;Moulin;;peut-être;hebdomadaire;troc\n");

        self::assertTrue($hasAccount);
        self::assertNull($rows[0]['refusal']);
        self::assertSame(['customer', true, 'net', '30', 'transfer'], [$rows[0]['draft']->kind, $rows[0]['draft']->individual, $rows[0]['draft']->termKind, $rows[0]['draft']->termDays, $rows[0]['draft']->paymentMethod]);
        self::assertSame(
            'Nature « ami » inconnue : client, fournisseur, salarie ou autre. La colonne « particulier » vaut oui ou non. Conditions « hebdomadaire » inconnues : comptant, date_de_facture, fin_de_mois, fin_de_mois_plus, mois_suivant. Mode « troc » inconnu : virement, cheque, carte, prelevement, especes, effet.',
            $rows[1]['refusal'],
        );
    }

    public function testAHeaderIsRequired(): void
    {
        $this->expectException(CsvRefused::class);
        $this->expectExceptionMessage('La première ligne doit nommer les colonnes, au moins « code », « nature » et « nom »');
        ThirdPartyImport::parse("C0003;client;Bâtir Loire Habitat\n");
    }
}
