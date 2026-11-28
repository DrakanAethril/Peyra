<?php

declare(strict_types=1);

namespace App\Tests\Controller\Accounting;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Accounting\EntryState;
use App\Enum\Dossier\DossierKind;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Consultation, search and brouillard through HTTP (step 1.5a), on ines.morel's Menuiserie Vasseur:
 * the opening (AN 01/01: 512000 D 10 000,00, 401SCIE C 2 400,00…), the Scierie's invoice (ACH 08/01,
 * 4 158,12) and its payment left in the brouillard (BQ 20/01, 2 400,00). From the balance to the
 * entry and back, the exports, F5's peek, the validation of a selection.
 */
final class ConsultationScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testAnAccountsConsultationLeadsToItsEntriesAndBack(): void
    {
        [$client, $dossier, $entries] = $this->school();

        $client->request('GET', $this->url($dossier, '/accounting/consultation'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Choisissez un compte ou un tiers');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/consultation?account=401scie'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '401SCIE · Scierie du Haut-Forez');
        $text = self::spaces($crawler->filter('main table')->text());
        self::assertStringContainsString('4 158,12', $text);
        // 2 400,00 C, then 6 558,12 C, then 2 400,00 paid back in the brouillard: 4 158,12 C.
        self::assertStringContainsString('6 558,12 C', $text);
        self::assertSame('4 158,12 C', self::spaces($crawler->filter('main tfoot td')->last()->text()));

        // Validated entries only: the payment is left out.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/consultation?account=401SCIE&scope=validated'));
        self::assertSame('6 558,12 C', self::spaces($crawler->filter('main tfoot td')->last()->text()));

        // From February: January is the opening.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/consultation?account=401SCIE&from=2026-02-01'));
        self::assertStringContainsString('Solde au 31/01/2026', $crawler->filter('main tbody')->text());

        // From the line to its entry, and back.
        $link = $client->request('GET', $this->url($dossier, '/accounting/consultation?account=401SCIE'))->filter('main tbody a')->eq(1);
        $client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'ACH-2026-00001');
        self::assertSelectorExists('a[href="'.$this->url($dossier, '/accounting/consultation?account=401SCIE').'"]');
        self::assertSelectorExists('main tbody a[href*="/accounting/consultation?account=607000"]', 'Chaque compte de l’écriture mène à sa consultation.');

        // A way back elsewhere is not drawn.
        $client->request('GET', $this->url($dossier, '/accounting/entries/'.$entries['ACH']->getId().'?return=https://example.org'));
        self::assertSelectorNotExists('.px-pagehead a[href="https://example.org"]');
        self::assertSelectorTextNotContains('.px-pagehead', 'Revenir');
    }

    public function testWhatNothingAnswersIsSaid(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/accounting/consultation?account=999999'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Ni compte ni tiers ne répond à 999999.');

        $client->request('GET', $this->url($dossier, '/accounting/consultation?account=512000&from=2026-03-01&to=2026-02-01'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'La période commence le 01/03/2026, après sa fin, le 01/02/2026.');

        $client->request('GET', $this->url($dossier, '/accounting/entries?amount=douze'));
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', $this->url($dossier, '/accounting/consultation/export.csv?account=999999'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheListsExportToASpreadsheet(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/accounting/consultation/export.csv?account=401SCIE'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $csv = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}Date;Journal;Pièce;Numéro;Compte;Tiers;Libellé;Échéance;Lettre;Débit;Crédit;Solde (débit − crédit)\r\n", $csv);
        self::assertStringContainsString('08/01/2026;ACH;FA-2601-0012;ACH-2026-00001;401000;401SCIE;Facture Scierie;;;;4158,12;-6558,12', $csv);
        self::assertStringContainsString(';Brouillard;401000;401SCIE;', $csv);

        $client->request('GET', $this->url($dossier, '/accounting/consultation/export.xlsx?account=401SCIE'));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('PK', (string) $client->getResponse()->getContent());

        $client->request('GET', $this->url($dossier, '/accounting/entries/export.csv?journal=ACH'));
        $csv = (string) $client->getResponse()->getContent();
        self::assertSame(4, substr_count($csv, "\r\n"), 'L’en-tête, puis les trois lignes de la facture.');
        self::assertStringContainsString('08/01/2026;ACH;FA-2601-0012;ACH-2026-00001;Validée;445660;;Facture Scierie;;693,02;', $csv);

        $client->request('GET', $this->url($dossier, '/accounting/brouillard/export.xlsx'));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('attachment; filename=brouillard-menuiserie-vasseur', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringEndsWith('.xlsx', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testTheSearchIsTheJournalsInterrogation(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entries?journal=ACH&period=2026-01-01'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Journal ACH · Achats');
        self::assertCount(1, $crawler->filter('main tbody tr'));
        self::assertSame('1 écriture', trim($crawler->filter('main tfoot th')->text()));

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entries?amount=2400'));
        self::assertCount(2, $crawler->filter('main tbody tr'), 'À-nouveaux et règlement : une ligne de 2 400,00 chacun.');
        self::assertStringContainsString('Brouillard · 1', self::spaces($crawler->filter('.px-filters')->text()));
    }

    public function testF5PeeksAtTheAccountOfTheLine(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/accounting/consultation/peek?account=401SCIE&return='.rawurlencode($this->url($dossier, '/accounting/entry'))));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-peek__subject', '401SCIE Scierie du Haut-Forez');
        self::assertSelectorTextContains('.px-peek', "4\u{202F}158,12\u{00A0}C");
        self::assertSelectorExists('a[href="'.$this->url($dossier, '/accounting/consultation?account=401SCIE&return=/d/'.$dossier->getId().'/accounting/entry').'"]');

        $client->request('GET', $this->url($dossier, '/accounting/consultation/peek?account=XYZ'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-empty', 'Ni compte ni tiers ne répond à XYZ');
    }

    public function testTheBrouillardValidatesTheSelection(): void
    {
        [$client, $dossier, $entries] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/brouillard'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('main tbody tr'));
        self::assertSelectorTextContains('main tbody', 'VIR-SCIE');
        $token = (string) $crawler->filter('form[action$="/brouillard/validate"] input[name="_token"]')->attr('value');

        $client->request('POST', $this->url($dossier, '/accounting/brouillard/validate'), ['_token' => 'forged', 'entries' => [(string) $entries['BQ']->getId()]]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', $this->url($dossier, '/accounting/brouillard/validate'), ['_token' => $token, 'entries' => [(string) $entries['BQ']->getId()], 'journal' => 'BQ']);
        self::assertResponseRedirects($this->url($dossier, '/accounting/brouillard?journal=BQ'));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Écriture BQ-2026-00001 validée.');
        self::assertSelectorTextContains('main', 'Le brouillard est vide');

        $client->getContainer()->get(DossierContext::class)->enter($dossier);
        self::assertSame(EntryState::Validated, $client->getContainer()->get(EntryRepository::class)->find($entries['BQ']->getId())?->getState());
        $client->getContainer()->get(DossierContext::class)->leave();
    }

    /** @return array{KernelBrowser, Dossier, array<string, Entry>} */
    private function school(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $client->getContainer()->get(SchoolClasses::class)->addTeacher($cg1, $martin, $martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel');
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($ines instanceof User && $year instanceof SchoolYear);
        $dossier = $this->savedDossier(DossierKind::Student, $ines, $year);
        $this->installPlan($dossier);
        $this->prepareLedger($dossier);
        $entries = [
            'AN' => $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026', ['512000 D 10000.00', '401000/F0001 C 2400.00', '101300 C 7600.00'], true),
            'ACH' => $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true, 'Facture Scierie'),
            'BQ' => $this->entry($dossier, 'BQ', '2026-01-20', 'VIR-SCIE', ['401000/F0001 D 2400.00', '512000 C 2400.00'], false, 'Règlement Scierie'),
        ];
        $client->getContainer()->get(DossierContext::class)->leave();

        return [$client, $dossier, $entries];
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }

    private static function spaces(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\u{202F}", "\u{00A0}"], ' ', $text)));
    }
}
