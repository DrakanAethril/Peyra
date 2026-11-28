<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Fec\Import;

use App\Enum\Accounting\JournalType;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Fec\FecDocument;
use App\Service\Accounting\Fec\FecFinding;
use App\Service\Accounting\Fec\FecReader;
use App\Service\Accounting\Fec\Import\FecImportAccount;
use App\Service\Accounting\Fec\Import\FecImportJournal;
use App\Service\Accounting\Fec\Import\FecImportPlan;
use App\Service\Accounting\Fec\Import\FecImportReading;
use App\Service\Accounting\Fec\Import\FecImportThirdParty;
use PHPUnit\Framework\TestCase;

/**
 * What a FEC makes of a template (step 1.9b), on files written by hand: accounts padded and set,
 * journals typed by their lines and names, third parties by their CompAuxNum, amounts netted,
 * lettering groups; then the faults, each on its line.
 */
final class FecImportReadingTest extends TestCase
{
    private const string HEADER = "JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise";

    public function testAccountsJournalsThirdPartiesEntriesAndLettering(): void
    {
        $plan = $this->plan([
            // line 2…
            ['RAN', 'Reprise', '1', '20260101', '512', 'Banque', '', '', 'AN', '0,00', '', '1000,00', '0,00', '', ''],
            ['RAN', 'Reprise', '1', '20260101', '401', 'Fournisseurs', 'scie', 'Scierie', 'AN', '0,00', '', '0,00', '400,00', 'A', '20260131'],
            ['RAN', 'Reprise', '1', '20260101', '101300', 'Capital', '', '', 'AN', '0,00', '', '0,00', '600,00', '', ''],
            // line 5…
            ['HA', 'Journal des achats', '7', '20260108', '6070000', 'Marchandises', '', '', 'F12', '0,00', 'Facture 12', '100,00', '0,00', '', ''],
            ['HA', 'Journal des achats', '7', '20260108', '445660', 'TVA', '', '', 'F12', '0,00', 'Facture 12', '20,00', '0,00', '', ''],
            ['HA', 'Journal des achats', '7', '20260108', '401', 'Fournisseurs', 'SCIE', 'Scierie', 'F12', '0,00', 'Facture 12', '0,00', '120,00', '', ''],
            ['HA', 'Journal des achats', '7', '20260108', '471000', 'Attente', '', '', 'F12', '0,00', 'Ligne vide', '0,00', '0,00', '', ''],
            // line 9…
            ['BQ', 'Crédit Agricole', '3', '20260120', '401', 'Fournisseurs', 'SCIE', 'Scierie', 'VIR', '0,00', 'Règlement', '400,00', '0,00', 'a', '20260131'],
            ['BQ', 'Crédit Agricole', '3', '20260120', '512', 'Banque', '', '', 'VIR', '0,00', 'Règlement', '-400,00', '0,00', '', ''],
            // line 11…
            ['BQ', 'Crédit Agricole', '4', '20260125', '411', 'Clients', '00123', 'Dupont SA', 'REM', '0,00', 'Remise', '0,00', '50,00', 'B', '20260131'],
            ['BQ', 'Crédit Agricole', '4', '20260125', '512', 'Banque', '', '', 'REM', '0,00', 'Remise', '50,00', '0,00', '', ''],
            // line 13…
            ['VT', 'Ventes', '1', '20260126', '411', 'Clients', '00123', 'Dupont SA', 'V1', '0,00', 'Vente', '60,00', '0,00', '', ''],
            ['VT', 'Ventes', '1', '20260126', '706000', 'Prestations', '', '', 'V1', '0,00', 'Vente', '0,00', '60,00', '', ''],
            // line 15…
            ['OD', 'Divers', '1', '20260131', '421000', 'Personnel', '', '', 'OD1', '0,00', 'Avance', '30,00', '10,00', '', ''],
            ['OD', 'Divers', '1', '20260131', '530000', 'Caisse', '', '', 'OD1', '0,00', 'Avance', '0,00', '20,00', '', ''],
        ]);

        self::assertSame([], $plan->findings);
        self::assertEquals([
            new FecImportAccount('512000', 'Banque', false, false),
            new FecImportAccount('401000', 'Fournisseurs', true, true),
            new FecImportAccount('101300', 'Capital', false, false),
            new FecImportAccount('6070000', 'Marchandises', false, false),
            new FecImportAccount('445660', 'TVA', false, false),
            new FecImportAccount('471000', 'Attente', false, true),
            new FecImportAccount('411000', 'Clients', true, true),
            new FecImportAccount('706000', 'Prestations', false, false),
            new FecImportAccount('421000', 'Personnel', false, true),
            new FecImportAccount('530000', 'Caisse', false, false),
        ], $plan->accounts);
        self::assertEquals([
            new FecImportJournal('RAN', 'Reprise', JournalType::Opening, null, 2),
            new FecImportJournal('HA', 'Journal des achats', JournalType::Purchases, null, 5),
            new FecImportJournal('BQ', 'Crédit Agricole', JournalType::Bank, '512000', 9),
            new FecImportJournal('VT', 'Ventes', JournalType::Sales, null, 13),
            new FecImportJournal('OD', 'Divers', JournalType::Cash, '530000', 15),
        ], $plan->journals);
        // « 00123 » holds no letter: a code is proposed from its collective and its name.
        self::assertEquals([
            new FecImportThirdParty('F0001', 'SCIE', 'Scierie', ThirdPartyKind::Supplier, '401000', 3),
            new FecImportThirdParty('C0001', '411DUPO', 'Dupont SA', ThirdPartyKind::Customer, '411000', 11),
        ], $plan->thirdParties);

        self::assertCount(6, $plan->entries);
        $purchase = $plan->entries[1];
        self::assertSame(['HA', '2026-01-08', 'F12', 'Facture 12'], [$purchase->journal, $purchase->date->format('Y-m-d'), $purchase->document, $purchase->label]);
        // The line without an amount is left out.
        self::assertCount(3, $purchase->lines);
        self::assertSame(1, $plan->zeroLines);
        self::assertSame(['401000', 'F0001', 0, 12000], [$purchase->lines[2]->account, $purchase->lines[2]->thirdParty, $purchase->lines[2]->debit->cents, $purchase->lines[2]->credit->cents]);
        // -400,00 at the debit is 400,00 at the credit; 30,00 and 10,00 on one line, 20,00 at the debit.
        self::assertSame([0, 40000], [$plan->entries[2]->lines[1]->debit->cents, $plan->entries[2]->lines[1]->credit->cents]);
        self::assertSame([2000, 0], [$plan->entries[5]->lines[0]->debit->cents, $plan->entries[5]->lines[0]->credit->cents]);

        // « A » and « a » on SCIE are one group (opening's credit, payment's debit); Dupont's « B » has no other line.
        self::assertSame([[[0, 1], [2, 0]]], $plan->letterings);
        self::assertSame(1, $plan->lettersLeft);
    }

    public function testSignedAmountsAndTheOpeningJournalNeedsItsFirstDay(): void
    {
        $header = str_replace("Debit\tCredit", "Montant\tSens", self::HEADER);
        $plan = FecImportReading::read($this->document([
            ['AN', 'A nouveaux', '1', '20260102', '471000', 'Attente', '', '', 'AN', '0,00', 'Ouverture', '10,00', 'D', '', ''],
            ['AN', 'A nouveaux', '1', '20260102', '101300', 'Capital', '', '', 'AN', '0,00', 'Ouverture', '10,00', 'C', '', ''],
        ], $header), new \DateTimeImmutable('2026-01-01'));

        self::assertSame([], $plan->findings);
        self::assertSame(JournalType::General, $plan->journals[0]->type);
        self::assertSame([1000, 0], [$plan->entries[0]->lines[0]->debit->cents, $plan->entries[0]->lines[0]->credit->cents]);
        self::assertSame([0, 1000], [$plan->entries[0]->lines[1]->debit->cents, $plan->entries[0]->lines[1]->credit->cents]);
    }

    public function testEveryFaultIsOnItsLine(): void
    {
        $plan = $this->plan([
            ['ACHATS1', 'Achats', '1', '20260108', '607000', 'Achats', '', '', 'F1', '0,00', 'Facture', '100,00', '0,00', '', ''],
            ['ACHATS1', 'Achats', '1', '20260108', '401SCIE', 'Scierie', '', '', 'F1', '0,00', 'Facture', '0,00', '100,00', '', ''],
            ['OD', 'Divers', '1', '20260110', '901000', 'Interne', '', '', 'X', '0,00', 'Divers', '5,00', '0,00', '', ''],
            ['OD', 'Divers', '1', '20260110', '607000', 'Achats', 'SCIE', 'Scierie', 'X', '0,00', 'Divers', '0,00', '5,00', '', ''],
            ['OD', 'Divers', '2', '20260111', '401000', 'Fournisseurs', 'DUP', 'Dupont', 'X', '0,00', 'Divers', '5,00', '0,00', '', ''],
            ['OD', 'Divers', '2', '20260111', '401000', 'Fournisseurs', '', '', 'X', '0,00', 'Divers', '0,00', '5,00', '', ''],
            ['OD', 'Divers', '3', '20260112', '411000', 'Clients', 'DUP', 'Dupont', str_repeat('P', 41), '0,00', 'Divers', '5,00', '0,00', '', ''],
            ['OD', 'Divers', '3', '20260112', '512000', 'Banque', '', '', str_repeat('P', 41), '0,00', 'Divers', '0,00', '5,00', '', ''],
            ['OD', 'Divers', '4', '20260113', '512000', 'Banque', '', '', 'Z', '0,00', 'Divers', '5,00', '5,00', '', ''],
            ['OD', 'Divers', '4', '20260113', '580000', 'Virements', '', '', 'Z', '0,00', 'Divers', '0,00', '0,00', '', ''],
        ]);

        self::assertFalse($plan->isApplicable());
        self::assertEquals([
            new FecFinding(2, 'JournalCode', 'Un code de journal compte de 1 à 6 lettres ou chiffres, sans accent : « ACHATS1 ».'),
            new FecFinding(3, 'CompteNum', 'Le compte « 401SCIE » n’entre pas dans un plan de Peyra. Un numéro de compte ne s’écrit qu’avec des chiffres ; un compte de tiers comme 401SCIE est le code d’un tiers.'),
            new FecFinding(4, 'CompteNum', 'Le compte « 901000 » n’entre pas dans un plan de Peyra. Un numéro de compte commence par sa classe, de 1 à 8.'),
            new FecFinding(5, 'CompAuxNum', 'Un compte auxiliaire se tient sur un compte de tiers de la classe 4 : le compte 607000 n’en est pas un.'),
            new FecFinding(7, 'CompAuxNum', 'Les lignes du compte 401000 nomment leur compte auxiliaire : celle-ci doit nommer le sien.'),
            new FecFinding(8, 'PieceRef', 'Une référence de pièce tient en 40 caractères : « '.str_repeat('P', 41).' ».'),
            new FecFinding(8, 'CompteNum', 'Le compte auxiliaire DUP est fournisseur, tenu sur le compte 401000 : il ne se porte pas sur le compte 411000. Le compte d’un fournisseur est un compte de fournisseurs (401000, 404000…).'),
            new FecFinding(10, null, 'Cette écriture n’a pas deux lignes qui portent un montant.'),
        ], $plan->findings);
    }

    /** @param list<list<string>> $rows the zones from JournalCode to DateLet; ValidDate, Montantdevise and Idevise are added */
    private function plan(array $rows): FecImportPlan
    {
        return FecImportReading::read($this->document($rows, self::HEADER), new \DateTimeImmutable('2026-01-01'));
    }

    /** @param list<list<string>> $rows */
    private function document(array $rows, string $header): FecDocument
    {
        $lines = [$header];
        foreach ($rows as $row) {
            $row[9] = $row[3];
            $lines[] = implode("\t", [...$row, '20260131', '', '']);
        }
        $document = FecReader::read(implode("\r\n", $lines)."\r\n");
        self::assertInstanceOf(FecDocument::class, $document);

        return $document;
    }
}
