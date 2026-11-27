<?php

declare(strict_types=1);

namespace App\Tests\Controller\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Accounting\Chart\ChartImport;
use App\Service\Accounting\Chart\ChartImportLine;
use App\Service\Dossier\DossierContext;
use App\Service\ThirdParty\ThirdPartyImport;
use App\Service\ThirdParty\ThirdPartyImportLine;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the entries change on the screens of step 1.2 (step 1.3): the balances in the account picker
 * (F4) and on an account's fiche, the third parties' outstanding amounts, and the guards - an
 * account, a journal or a third party that carries entries keeps what they are entered on and
 * stays. ines.morel's Menuiserie Vasseur (date 15/03/2026) holds:
 *
 * - AN 01/01, validated: 512000 D 10 000,00 · 401000 F0001 C 2 400,00 · 101300 C 7 600,00;
 * - BQ 20/01, brouillard: 401000 F0001 D 400,00 · 512000 C 400,00;
 * - OD 31/01, brouillard: 467000 D 50,00 · 512000 C 50,00.
 *
 * Bank 10 000,00 - 400,00 - 50,00 = 9 550,00 D; Scierie 2 400,00 - 400,00 = 2 000,00 C.
 */
final class EntryGuardsScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    private const string NNBSP = "\u{202F}";
    private const string NBSP = "\u{A0}";

    public function testTheAccountPickerShowsEachBalanceAtTheDossiersDate(): void
    {
        [$client, $dossier] = $this->school();

        self::assertSame(['value' => '512000', 'label' => 'Banques', 'balance' => '9'.self::NNBSP.'550,00'.self::NBSP.'D'], $this->firstOption($client, $this->url($dossier, '/accounting/accounts/search?q=512000')));
        self::assertSame('2'.self::NNBSP.'000,00'.self::NBSP.'C', $this->firstOption($client, $this->url($dossier, '/accounting/accounts/search?q=scie'))['balance'] ?? null);
        self::assertSame('', $this->firstOption($client, $this->url($dossier, '/accounting/accounts/search?q=606100'))['balance'] ?? null, 'Rien ne l’a mouvementé : pas de solde.');
    }

    public function testAnAccountWithValidatedEntriesKeepsItsNumberAndStays(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/accounting/accounts/512000'));
        self::assertSelectorTextContains('main', '9'.self::NNBSP.'550,00'.self::NBSP.'D au 15/03/2026, depuis l’ouverture de l’exercice, brouillard compris');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts/101300'));
        self::assertSelectorTextContains('main', '7'.self::NNBSP.'600,00'.self::NBSP.'C au 15/03/2026');
        self::assertSelectorTextContains('main', 'Ce compte porte des écritures validées : il reste au plan.');
        self::assertSelectorNotExists('#account-delete');

        $form = $crawler->filter('#account-form')->form();
        $form['number'] = '101390';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#number_error', 'Ce compte porte des écritures validées : son numéro ne change plus.');

        $client->request('GET', $this->url($dossier, '/accounting/accounts/467000'));
        self::assertSelectorTextContains('main', 'Ce compte figure sur des écritures du brouillard : changez-y le compte ou supprimez-les d’abord.');
        $form = $client->getCrawler()->filter('#account-form')->form();
        $form['number'] = '467100';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/accounting/accounts/467100'), null, 'Au brouillard seulement : le numéro change, les lignes suivent.');
    }

    public function testAnAccountThatCarriesLinesKeepsItsCollectiveBoxInTheImport(): void
    {
        [$client, $dossier] = $this->school();

        $report = $client->getContainer()->get(DossierContext::class)->within($this->fresh($client, $dossier), fn () => $client->getContainer()->get(ChartImport::class)->prepare($this->fresh($client, $dossier), "numero;intitule;lettrable;collectif\r\n467000;Autres comptes débiteurs ou créditeurs;oui;oui\r\n", true));
        self::assertSame([ChartImportLine::REFUSED, 'Ce compte porte des écritures sans tiers : il reste un compte général.'], [$report->lines[0]->action, $report->lines[0]->message]);
    }

    public function testAJournalWithEntriesKeepsItsTypeAndStays(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/journals/BQ'));
        self::assertSelectorTextContains('main', 'Ce journal porte une écriture au brouillard : supprimez-la d’abord.');
        self::assertSelectorNotExists('#journal-delete');
        $form = $crawler->filter('#journal-form')->form();
        $form['type'] = 'general';
        $form['counterpart'] = '';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#type_error', 'Ce journal porte des écritures : il reste de type « Banque ».');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/journals/AN'));
        self::assertSelectorTextContains('main', 'Ce journal porte des écritures validées : il reste au dossier.');
        $form = $crawler->filter('#journal-form')->form();
        $form['code'] = 'RAN';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#code_error', 'Ce journal porte des écritures validées, numérotées à son code : son code ne change plus.');

        $client->request('GET', $this->url($dossier, '/accounting/journals/VTE'));
        self::assertSelectorExists('#journal-delete', 'Un journal sans écriture se supprime encore.');
    }

    public function testAThirdPartyNamedOnEntriesShowsItsOutstandingAmountAndKeepsItsCodes(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/third-parties'));
        $cells = $crawler->filter('tbody tr')->each(static fn ($row): array => [trim($row->filter('td')->first()->text()), trim($row->filter('td')->last()->text())]);
        self::assertSame([['A0001', ''], ['C0019', ''], ['F0001', '2'.self::NNBSP.'000,00'.self::NBSP.'C']], $cells);

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/third-parties/F0001'));
        self::assertSelectorTextContains('main', 'Encours 2'.self::NNBSP.'000,00'.self::NBSP.'C au 15/03/2026');
        self::assertSelectorTextContains('main', 'Ce tiers est nommé sur 2 lignes d’écriture : il reste au dossier.');
        self::assertSelectorNotExists('#third-party-delete');
        $form = $crawler->filter('#third-party-form')->form();
        $form['auxiliaryCode'] = '401SCI2';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#auxiliaryCode_error', 'Ce tiers est nommé sur des écritures : son code auxiliaire reste 401SCIE.');

        $client->request('GET', $this->url($dossier, '/accounting/third-parties/C0019'));
        self::assertSelectorTextContains('main', 'aucune écriture ne le nomme au 15/03/2026');
        self::assertSelectorExists('#third-party-delete');

        $client->request('GET', $this->url($dossier, '/accounting/third-parties/export.csv'));
        $csv = str_replace('401SCIE', '401SCI2', (string) $client->getInternalResponse()->getContent());
        $report = $client->getContainer()->get(DossierContext::class)->within($this->fresh($client, $dossier), fn () => $client->getContainer()->get(ThirdPartyImport::class)->prepare($this->fresh($client, $dossier), $csv, true));
        $refused = array_values(array_filter($report->lines, static fn (ThirdPartyImportLine $line): bool => ThirdPartyImportLine::REFUSED === $line->action));
        self::assertSame([['F0001', 'Ce tiers est nommé sur des écritures : son code auxiliaire reste 401SCIE.']], array_map(static fn (ThirdPartyImportLine $line): array => [$line->code, $line->message], $refused));
    }

    /**
     * prof.martin and ines.morel's class of 2026-2027; her dossier with the PCG 2026, the journals,
     * three third parties and three entries. Ends signed in as ines.morel, who
     * works in it.
     *
     * @return array{KernelBrowser, Dossier}
     */
    private function school(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        $this->buildYear($client, $martin, 2026, true);
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel');
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($ines instanceof User && $year instanceof SchoolYear);
        $dossier = $this->savedDossier(DossierKind::Student, $ines, $year);
        $this->installPlan($dossier);
        $this->prepareLedger($dossier);
        $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026-001', ['512000 D 10000.00', '401000/F0001 C 2400.00', '101300 C 7600.00'], true);
        $this->entry($dossier, 'BQ', '2026-01-20', 'VIR-SCIE', ['401000/F0001 D 400.00', '512000 C 400.00']);
        $this->entry($dossier, 'OD', '2026-01-31', 'OD-1', ['467000 D 50.00', '512000 C 50.00']);
        $client->getContainer()->get(DossierContext::class)->leave();

        return [$client, $dossier];
    }

    private function fresh(KernelBrowser $client, Dossier $dossier): Dossier
    {
        $fresh = $client->getContainer()->get(EntityManagerInterface::class)->find(Dossier::class, $dossier->getId());
        \assert($fresh instanceof Dossier);

        return $fresh;
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }

    /** @return array<mixed> the first option of the picker's first group */
    private function firstOption(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $client->getInternalResponse()->getContent(), true);
        $groups = \is_array($result) ? ($result['groups'] ?? null) : null;
        $group = \is_array($groups) ? ($groups[0] ?? null) : null;
        $options = \is_array($group) ? ($group['options'] ?? null) : null;
        $option = \is_array($options) ? ($options[0] ?? null) : null;
        self::assertIsArray($option);

        return $option;
    }
}
