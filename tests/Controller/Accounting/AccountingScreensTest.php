<?php

declare(strict_types=1);

namespace App\Tests\Controller\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Dossier\DossierKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * Comptabilité › Paramètres comptables (step 1.2a): the plan of accounts, an account's fiche, the
 * CSV export and import, the account picker's search (F4), the journals. ines.morel holds
 * Menuiserie Vasseur with the PCG 2026; prof.durand teaches her class; the common library's
 * template is read by every teacher and changed by administrators only.
 */
final class AccountingScreensTest extends WebTestCase
{
    use SavesDossiers;
    use BuildsSchool;
    use SignsIn;

    public function testThePlanIsListedByHeadingFilteredAndSearched(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-rail', 'Comptabilité');
        self::assertSelectorTextContains('h1', 'Plan de comptes');
        self::assertSelectorTextContains('.px-pagehead', 'PCG 2026');
        self::assertSelectorTextContains('.px-table__section', '10 · Capital et réserves');
        self::assertSelectorExists('a[data-px-shortcut="N"]');
        self::assertSelectorTextContains('.px-statusbar', 'Plan de référence · PCG 2026');
        self::assertSame('101000', trim($crawler->filter('tbody tr[data-rows-target="row"] td')->first()->text()));

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts?class=4&q=fourn'));
        self::assertSelectorTextContains('.px-filters__chip[aria-current="true"]', '4 · Tiers');
        $numbers = $crawler->filter('tbody tr[data-rows-target="row"] td.px-u-code')->each(static fn ($cell): string => trim($cell->text()));
        self::assertContains('401000', $numbers);
        self::assertNotContains('601000', $numbers);
        self::assertSelectorTextContains('.px-table__section', '40 · Fournisseurs et comptes rattachés');

        $client->request('GET', $this->url($dossier, '/accounting/accounts?q=zzz'));
        self::assertSelectorTextContains('.px-empty', 'Aucun compte de ce filtre ne correspond à « zzz ».');
    }

    public function testAnAccountIsCreatedChangedAndDeletedFromItsFiche(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts/new'));
        $form = $crawler->filter('#account-form')->form();
        $form['number'] = '601';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#number_error', 'Un numéro de compte compte de 6 à 8 chiffres');
        self::assertSelectorTextContains('#label_error', 'Indiquez l’intitulé du compte.');

        $form['number'] = '190000';
        $form['label'] = 'Compte inventé';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/accounting/accounts/190000'));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Compte 190000 créé. Le compte 190000 ne subdivise aucun compte du PCG 2026');
        self::assertSelectorTextContains('.px-alert--warning', 'ne subdivise aucun compte du PCG 2026');
        self::assertSelectorTextContains('.px-pagehead', 'Créé dans le dossier');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts/190000'));
        $form = $crawler->filter('#account-form')->form();
        $form['number'] = '601300';
        $form['label'] = 'Achats de vitrages';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/accounting/accounts/601300'));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main .px-card .px-facts', '601 · Achats stockés - Matières premières et fournitures');

        $client->submit($crawler->filter('#account-delete')->form());
        self::assertResponseRedirects($this->url($dossier, '/accounting/accounts'));
        $client->request('GET', $this->url($dossier, '/accounting/accounts/601300'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testThePlanGoesOutAndComesBackAsCsv(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');

        $client->request('GET', $this->url($dossier, '/accounting/accounts/export.csv'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment; filename=plan-de-comptes-menuiserie-vasseur-sarl.csv', (string) $client->getResponse()->headers->get('Content-Disposition'));
        $csv = (string) $client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}numero;intitule;lettrable;collectif\r\n101000;Capital;non;non\r\n", $csv);
        self::assertStringContainsString("\r\n401000;Fournisseurs;oui;oui\r\n", $csv);

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts/import'));
        $form = $crawler->filter('#import-form')->form();
        $file = $form['file'];
        \assert($file instanceof FileFormField);
        $file->upload($this->file("numero;intitule\n601300;Achats de vitrages\n601300;Achats de verre\n"));
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-alert--danger', 'Une ligne est refusée');
        self::assertSelectorTextContains('tbody tr.is-error', 'Le compte 601300 figure déjà ligne 2.');
        self::assertNull($this->accountOf($client, $dossier, '601300'));

        $file->upload($this->file("numero;intitule\n601300;Achats de vitrages\n401000;Fournisseurs\n"));
        $client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-alert--success', '1 compte ajouté, 0 modifié, 1 déjà dans le plan sans changement.');
        self::assertSame(AccountOrigin::Imported, $this->accountOf($client, $dossier, '601300')?->getOrigin());
    }

    public function testTheAccountPickerSearchesTheDossierByNumberLabelAndNature(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');

        $result = $this->json($client, $this->url($dossier, '/accounting/accounts/search?q=512&nature=bank'));
        $first = \is_array($result['groups'] ?? null) && \is_array($result['groups'][0] ?? null) ? $result['groups'][0] : [];
        self::assertSame('51 · Banques, établissements financiers et assimilés', $first['label'] ?? null);
        self::assertSame(['value' => '512000', 'label' => 'Banques'], \is_array($first['options'] ?? null) ? ($first['options'][0] ?? null) : null);

        self::assertSame([], $this->json($client, $this->url($dossier, '/accounting/accounts/search?q=valeurs encaissement&nature=bank,cash'))['groups'] ?? null, '511 n’est pas une banque.');
        self::assertSame('Aucun compte ne commence par 609999.', $this->json($client, $this->url($dossier, '/accounting/accounts/search?q=609999'))['empty'] ?? null);
    }

    public function testJournalsAreCreatedWithTheirCounterpartChangedAndDeleted(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');

        $client->request('GET', $this->url($dossier, '/accounting/journals'));
        self::assertSelectorTextContains('.px-empty', 'Aucun journal');
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/journals/new'));
        self::assertSelectorExists('#counterpart[role="combobox"][data-account-picker-target="input"]');
        $form = $crawler->filter('#journal-form')->form();
        $form['code'] = 'bq1';
        $form['label'] = 'Crédit Coopératif';
        $form['type'] = 'bank';
        $form['counterpart'] = '601000';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#counterpart_error', 'La contrepartie d’un journal de banque est un compte de banque (512000).');

        $form['counterpart'] = '512100';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/accounting/journals'));
        $client->followRedirect();
        self::assertSelectorTextContains('tbody', 'BQ1');
        self::assertSelectorTextContains('tbody', '512100 Comptes en euros');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/journals/BQ1'));
        self::assertSelectorTextContains('#counterpart_account', 'Comptes en euros');
        $client->request('GET', $this->url($dossier, '/accounting/accounts/512100'));
        self::assertSelectorTextContains('main .px-card .px-facts', 'Contrepartie de');
        self::assertSelectorNotExists('#account-delete');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/journals/BQ1'));
        $client->submit($crawler->filter('#journal-delete')->form());
        self::assertResponseRedirects($this->url($dossier, '/accounting/journals'));
        self::assertSame([], $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): array => self::getContainer()->get(JournalRepository::class)->findByDossier($dossier)));
    }

    public function testAnEmptyPlanReceivesTheChartOfItsExercise(): void
    {
        [$client] = $this->school();
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        $ines = $this->account($client, 'ines.morel');
        \assert($year instanceof SchoolYear && $ines instanceof User);
        $empty = $this->savedDossier(DossierKind::Student, $ines, $year, 'Atelier Vide');
        $this->as($client, 'ines.morel');

        $crawler = $client->request('GET', $this->url($empty, '/accounting/accounts'));
        self::assertSelectorTextContains('.px-empty', 'Le plan de comptes est vide');
        self::assertSelectorNotExists('a[href$="/export.csv"]');
        $client->submit($crawler->selectButton('Installer le PCG 2026')->form());
        self::assertResponseRedirects($this->url($empty, '/accounting/accounts'));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'PCG 2026 installé : ');
        self::assertNotNull($this->accountOf($client, $empty, '401000'));
    }

    public function testWhoOnlyReadsSeesThePlanWithoutAField(): void
    {
        [$client] = $this->school();
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        $template = $this->savedDossier(DossierKind::Template, null, null, 'Modèle commun');
        $this->installPlan($template);
        $this->as($client, 'prof.durand');

        $client->request('GET', $this->url($template, '/accounting/accounts'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[data-px-shortcut="N"]');
        self::assertSelectorNotExists('a[href$="/import"]');
        $client->request('GET', $this->url($template, '/accounting/accounts/401000'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#account-form');
        self::assertSelectorTextContains('main .px-card .px-facts', 'Fournisseurs');
        foreach (['/accounting/accounts/new', '/accounting/accounts/import', '/accounting/journals/new'] as $path) {
            $client->request('GET', $this->url($template, $path));
            self::assertResponseStatusCodeSame(404, $path);
        }
        $client->request('POST', $this->url($template, '/accounting/accounts/401000'), ['number' => '401000', 'label' => 'Changé', '_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * prof.durand teaches BTS CG 1; ines.morel is placed in it and holds Menuiserie Vasseur with
     * the PCG 2026. Ends signed in as prof.martin.
     *
     * @return array{KernelBrowser, Dossier, User}
     */
    private function school(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.durand');
        $this->as($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $durand = $this->account($client, 'prof.durand');
        \assert($durand instanceof User);
        $client->getContainer()->get(SchoolClasses::class)->addTeacher($cg1, $durand, $martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $this->as($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel');
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($ines instanceof User && $year instanceof SchoolYear);
        $dossier = $this->savedDossier(DossierKind::Student, $ines, $year);
        $this->installPlan($dossier);
        $this->as($client, 'prof.martin');

        return [$client, $dossier, $ines];
    }

    private function as(KernelBrowser $client, string $login): void
    {
        $this->signOut($client);
        $this->submitLogin($client, $login);
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }

    private function accountOf(KernelBrowser $client, Dossier $dossier, string $number): ?\App\Entity\Accounting\Account
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        $fresh = $client->getContainer()->get(EntityManagerInterface::class)->find(Dossier::class, $dossier->getId());
        \assert($fresh instanceof Dossier);

        return $client->getContainer()->get(DossierContext::class)->within($fresh, static fn () => self::getContainer()->get(AccountRepository::class)->findOneByNumber($fresh, $number));
    }

    /** @return array<mixed> */
    private function json(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $client->getInternalResponse()->getContent(), true);
        self::assertIsArray($result);

        return $result;
    }

    /** A CSV file on disk, for the form's file field. */
    private function file(string $content): string
    {
        $path = sys_get_temp_dir().'/plan-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $content);

        return $path;
    }
}
