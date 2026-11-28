<?php

declare(strict_types=1);

namespace App\Tests\Controller\Closing;

use App\Entity\Dossier\Dossier;
use App\Service\Accounting\Lettering\Lettering;
use App\Service\Dossier\DossierContext;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Immobilisations et clôture › Clôture › FEC (step 1.9a) on a template of the common library at
 * its date, the 15/03/2026 (SIREN 999412736): the opening, the Scierie's invoice and its payment
 * validated - the payment lettered with the opening's debt (letter A, on the dossier's date) -, an
 * OD left in the brouillard. Every line of the file written by hand.
 */
final class FecScreensTest extends WebTestCase
{
    use BuildsLedger;
    use SignsIn;

    public function testTheFecHoldsTheValidatedEntriesTheOpeningFirstAndIsConform(): void
    {
        [$client, $dossier] = $this->dossier();

        $client->request('GET', '/d/'.$dossier->getId().'/closing/fec/download');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=999412736FEC20261231.txt');
        $lines = explode("\r\n", (string) $client->getResponse()->getContent());

        self::assertSame([
            'JournalCode	JournalLib	EcritureNum	EcritureDate	CompteNum	CompteLib	CompAuxNum	CompAuxLib	PieceRef	PieceDate	EcritureLib	Debit	Credit	EcritureLet	DateLet	ValidDate	Montantdevise	Idevise',
            'AN	À-nouveaux	AN-2026-00001	20260101	512000	Banques			AN-2026	20260101	Ouverture	10000,00	0,00			20260315		',
            'AN	À-nouveaux	AN-2026-00001	20260101	401000	Fournisseurs	401SCIE	Scierie du Haut-Forez	AN-2026	20260101	Ouverture	0,00	2400,00	A	20260315	20260315		',
            'AN	À-nouveaux	AN-2026-00001	20260101	101300	Capital souscrit - appelé, versé			AN-2026	20260101	Ouverture	0,00	7600,00			20260315		',
            'ACH	Achats	ACH-2026-00001	20260108	607000	Achats de marchandises			FA-2601-0012	20260108	Facture Scierie	3465,10	0,00			20260315		',
            'ACH	Achats	ACH-2026-00001	20260108	445660	TVA sur autres biens et services			FA-2601-0012	20260108	Facture Scierie	693,02	0,00			20260315		',
            'ACH	Achats	ACH-2026-00001	20260108	401000	Fournisseurs	401SCIE	Scierie du Haut-Forez	FA-2601-0012	20260108	Facture Scierie	0,00	4158,12			20260315		',
            'BQ	Banque	BQ-2026-00001	20260120	401000	Fournisseurs	401SCIE	Scierie du Haut-Forez	VIR-SCIE	20260120	Règlement Scierie	2400,00	0,00	A	20260315	20260315		',
            'BQ	Banque	BQ-2026-00001	20260120	512000	Banques			VIR-SCIE	20260120	Règlement Scierie	0,00	2400,00			20260315		',
            '',
        ], $lines);
    }

    public function testTheScreenShowsWhatTheFileHoldsAndItsCheck(): void
    {
        [$client, $dossier] = $this->dossier();

        $crawler = $client->request('GET', '/d/'.$dossier->getId().'/closing/fec');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'FEC de l’exercice 2026');
        self::assertSelectorTextContains('.px-pagehead', 'Conforme');
        self::assertSelectorTextContains('main', '999412736FEC20261231.txt');
        // 10 000,00 + 3 465,10 + 693,02 + 2 400,00 on each side.
        self::assertStringContainsString('16 558,12', str_replace("\u{202F}", ' ', $crawler->filter('.px-kpis')->text()));
        self::assertSelectorTextContains('main', '1 écriture du brouillard ne figure pas dans le FEC');
        self::assertSelectorTextContains('main', 'L’exercice n’est pas clos');
        self::assertSelectorNotExists('main tr.is-error');
        self::assertSelectorExists('a[href="/d/'.$dossier->getId().'/closing/fec/download?exercise=2026"]');
        self::assertSelectorExists('.px-rail a[href="/d/'.$dossier->getId().'/closing/fec"][aria-current="page"]');

        // An exercise the dossier does not have: the one of its date.
        $client->request('GET', '/d/'.$dossier->getId().'/closing/fec?exercise=1999');
        self::assertSelectorTextContains('h1', 'FEC de l’exercice 2026');
    }

    /** @return array{KernelBrowser, Dossier} */
    private function dossier(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $dossier = $this->ledgerDossier();
        $opening = $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026', ['512000 D 10000.00', '401000/F0001 C 2400.00', '101300 C 7600.00'], true, 'Ouverture');
        $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true, 'Facture Scierie');
        $payment = $this->entry($dossier, 'BQ', '2026-01-20', 'VIR-SCIE', ['401000/F0001 D 2400.00', '512000 C 2400.00'], true, 'Règlement Scierie');
        $this->entry($dossier, 'OD', '2026-02-01', 'OD-01', ['625100 D 50.00', '512000 C 50.00'], false, 'Déplacement');
        static::getContainer()->get(Lettering::class)->letter($dossier, [(int) $opening->getLines()[1]->getId(), (int) $payment->getLines()[0]->getId()], 'test');
        static::getContainer()->get(DossierContext::class)->leave();

        return [$client, $dossier];
    }
}
