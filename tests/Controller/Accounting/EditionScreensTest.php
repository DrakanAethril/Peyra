<?php

declare(strict_types=1);

namespace App\Tests\Controller\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The editions through HTTP (step 1.5b), on ines.morel's Menuiserie Vasseur at its date, the
 * 15/03/2026: the opening (AN 01/01: 512000 D 10 000,00, 401SCIE C 2 400,00, 101300 C 7 600,00),
 * the Scierie's invoice (ACH 08/01, 4 158,12), its payment left in the brouillard (BQ 20/01,
 * 2 400,00), a sale to the Girards (VTE 10/02, 1 200,00 TTC). Figures computed by hand.
 */
final class EditionScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testTheGeneralBalanceAddsUpByClassAndBalances(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/trial-balance'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Balance générale');
        // 401000: 2 400,00 D and 6 558,12 C of movements, 4 158,12 C at the end.
        self::assertSame(['401000', 'Fournisseurs', '2 400,00', '6 558,12', '', '4 158,12'], $this->row($crawler, '401000'));
        self::assertSame(['512000', 'Banques', '10 000,00', '2 400,00', '7 600,00', ''], $this->row($crawler, '512000'));
        // Class 4: 1 200,00 + 693,02 D; 4 158,12 + 200,00 C.
        self::assertSame(['Total classe 4', '4 293,02', '6 758,12', '1 893,02', '4 358,12'], $this->row($crawler, 'Total classe 4'));
        // 10 000 + 3 465,10 + 693,02 + 2 400 + 1 200 on each side; 12 958,12 of balances on each side.
        self::assertSame(['Total général · 8 comptes', '17 758,12', '17 758,12', '12 958,12', '12 958,12'], self::cells($crawler->filter('main tfoot tr')));
        self::assertSelectorTextContains('.px-statusbar', 'Balance équilibrée');

        // Validated entries only: the payment is left out.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/trial-balance?scope=validated'));
        self::assertSame(['401000', 'Fournisseurs', '', '6 558,12', '', '6 558,12'], $this->row($crawler, '401000'));

        // From February: the eve's balance, then February's movements.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/trial-balance?from=2026-02-01'));
        self::assertSelectorTextContains('main thead', 'Solde au 31/01');
        self::assertSame(['401000', 'Fournisseurs', '4 158,12 C', '', '', '', '4 158,12'], $this->row($crawler, '401000'));
        self::assertSame(['411000', 'Clients', '', '1 200,00', '', '1 200,00', ''], $this->row($crawler, '411000'));

        // Classes 6 and 7 only.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/trial-balance?account_from=6&account_to=7'));
        self::assertCount(2, $crawler->filter('main tbody tr[data-rows-target]'));

        // An amount leads to its account's consultation, which leads back here.
        $link = $client->request('GET', $this->url($dossier, '/accounting/editions/trial-balance'))->filter('main tbody tr[data-rows-target] a.px-table__drill')->first();
        $crawler = $client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', '101300');
        self::assertSelectorExists('.px-pagehead a[href="'.$this->url($dossier, '/accounting/editions/trial-balance').'"]');
    }

    public function testTheJournalsListEveryLineByJournalAndMonth(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/journals'));
        self::assertResponseIsSuccessful();
        $sections = $crawler->filter('main tr.px-table__section')->each(static fn (Crawler $row): string => self::spaces($row->text()));
        self::assertSame(['ACH · Achats · janvier 2026', 'AN · À-nouveaux · janvier 2026', 'BQ · Banque · janvier 2026', 'VTE · Ventes · février 2026'], $sections);
        self::assertSame(['Total ACH · janvier 2026', '4 158,12', '4 158,12'], $this->row($crawler, 'Total ACH'));
        self::assertSame(['Total général du 01/01/2026 au 15/03/2026', '17 758,12', '17 758,12'], self::cells($crawler->filter('main tfoot tr')));
        self::assertStringContainsString('Brouillard', $crawler->filter('main table')->text(), 'The payment is in the brouillard.');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/journals?journal=bq&scope=validated'));
        self::assertSelectorTextContains('main', 'Le journal BQ n’a d’écriture');

        $client->request('GET', $this->url($dossier, '/accounting/editions/journals?journal=XYZ'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-alert', 'Le dossier n’a pas de journal XYZ');
    }

    public function testTheGeneralLedgerCarriesTheEvesBalanceAndRunsEachAccount(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/general-ledger?account_from=401&account_to=401&from=2026-01-15'));
        self::assertResponseIsSuccessful();
        // On the 14/01: 2 400,00 + 4 158,12 C; then the payment of the 20/01.
        self::assertSame(['Solde au 14/01/2026', '', '6 558,12', '6 558,12 C'], self::cells($crawler->filter('main tbody tr.is-dim')));
        self::assertSame(['Total 401000', '2 400,00', '6 558,12', '4 158,12 C'], $this->row($crawler, 'Total 401000'));
        self::assertCount(1, $crawler->filter('main tbody tr[data-rows-target]'));

        $client->request('GET', $this->url($dossier, '/accounting/editions/general-ledger?account_from=4SCIE'));
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', $this->url($dossier, '/accounting/editions/general-ledger?from=2026-12-01&to=2027-01-31'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-alert', 'Une édition tient dans un exercice');
    }

    public function testTheThirdPartiesAndTheirAge(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/third-party-balance'));
        self::assertResponseIsSuccessful();
        self::assertSame(['411GIRA', 'Girard, Jean et Anne', '411000', '1 200,00', '', '1 200,00', ''], $this->row($crawler, '411GIRA'));
        self::assertSame(['401SCIE', 'Scierie du Haut-Forez', '401000', '2 400,00', '6 558,12', '', '4 158,12'], $this->row($crawler, '401SCIE'));
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/third-party-balance?kind=customer'));
        self::assertSame(['Clients'], $crawler->filter('main tr.px-table__section')->each(static fn (Crawler $row): string => self::spaces($row->text())));

        // The sale of the 10/02 is 33 days old on the 15/03; on the 15/02, 5 days.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/aged-balance'));
        self::assertSame(['411GIRA', 'Girard, Jean et Anne', '1 200,00', '', '', '1 200,00', '', ''], $this->row($crawler, '411GIRA'));
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/aged-balance?at=2026-02-15'));
        self::assertSame(['411GIRA', 'Girard, Jean et Anne', '1 200,00', '', '1 200,00', '', '', ''], $this->row($crawler, '411GIRA'));
        $client->request('GET', $this->url($dossier, '/accounting/editions/aged-balance?at=2026-02-09'));
        self::assertSelectorTextContains('main', 'Aucun client n’a de solde au 09/02/2026');

        // The Scierie is owed 4 158,12: the invoice of the 08/01 (due then), 66 days late.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/editions/aged-balance?kind=supplier'));
        self::assertSame(['401SCIE', 'Scierie du Haut-Forez', '4 158,12', '', '', '', '4 158,12', ''], $this->row($crawler, '401SCIE'));
    }

    public function testEachEditionPrintsAndExports(): void
    {
        [$client, $dossier] = $this->school();

        foreach (['journals', 'general-ledger', 'trial-balance', 'third-party-balance', 'aged-balance'] as $edition) {
            $client->request('GET', $this->url($dossier, '/accounting/editions/'.$edition.'.pdf'));
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/pdf');
            self::assertStringStartsWith('%PDF-', (string) $client->getInternalResponse()->getContent());
            self::assertStringContainsString('inline;', (string) $client->getResponse()->headers->get('Content-Disposition'));
        }

        $client->request('GET', $this->url($dossier, '/accounting/editions/trial-balance/export.csv'));
        self::assertResponseIsSuccessful();
        $csv = (string) $client->getInternalResponse()->getContent();
        self::assertStringContainsString("401000;Fournisseurs;2400,00;6558,12;;4158,12\r\n", $csv);
        self::assertStringContainsString('balance-menuiserie-vasseur', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $client->request('GET', $this->url($dossier, '/accounting/editions/aged-balance/export.csv?kind=supplier'));
        self::assertStringContainsString("F0001;401SCIE;Scierie du Haut-Forez;4158,12;;;;4158,12;\r\n", (string) $client->getInternalResponse()->getContent());

        $client->request('GET', $this->url($dossier, '/accounting/editions/journals/export.xlsx'));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('PK', (string) $client->getInternalResponse()->getContent());
    }

    /**
     * ines.morel's dossier, her teacher placed; signed in as her.
     *
     * @return array{0: KernelBrowser, 1: Dossier}
     */
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
        $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026', ['512000 D 10000.00', '401000/F0001 C 2400.00', '101300 C 7600.00'], true);
        $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true, 'Facture Scierie');
        $this->entry($dossier, 'BQ', '2026-01-20', 'VIR-SCIE', ['401000/F0001 D 2400.00', '512000 C 2400.00'], false, 'Règlement Scierie');
        $this->entry($dossier, 'VTE', '2026-02-10', 'FV-2026-0001', ['411000/C0019 D 1200.00', '706000 C 1000.00', '445710 C 200.00'], true, 'Vente Girard');
        $client->getContainer()->get(DossierContext::class)->leave();

        return [$client, $dossier];
    }

    /** The cells of the first row whose first cell starts with `first`. @return list<string> */
    private function row(Crawler $crawler, string $first): array
    {
        foreach ($crawler->filter('main tbody tr') as $node) {
            $cells = self::cells(new Crawler($node));
            if (str_starts_with($cells[0] ?? '', $first)) {
                return $cells;
            }
        }

        self::fail('No row starts with '.$first.'.');
    }

    /** @return list<string> */
    private static function cells(Crawler $row): array
    {
        return $row->filter('th, td')->each(static fn (Crawler $cell): string => self::spaces($cell->text()));
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
