<?php

declare(strict_types=1);

namespace App\Tests\Controller\ThirdParty;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Service\ThirdParty\ThirdPartyDraft;
use App\Service\ThirdParty\ThirdPartySheet;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * Comptabilité › Paramètres comptables › Tiers (step 1.2b): the list, a third party's fiche, the
 * CSV export and import, the account picker's search on auxiliary codes (F4), and the guards on an
 * account that carries third parties. ines.morel holds Menuiserie Vasseur with the PCG 2026 (date
 * 15/03/2026); prof.durand teaches her class; the common library is read by every teacher.
 */
final class ThirdPartyScreensTest extends WebTestCase
{
    use SavesDossiers;
    use BuildsSchool;
    use SignsIn;

    public function testAThirdPartyIsCreatedFromItsFicheListedChangedAndDeleted(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');

        $client->request('GET', $this->url($dossier, '/accounting/third-parties'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-tabs [aria-current="page"]', 'Tiers');
        self::assertSelectorTextContains('.px-empty', 'Aucun tiers');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/third-parties/new?kind=supplier'));
        $form = $crawler->filter('#third-party-form')->form();
        self::assertSame(['F0001', 'supplier', '401000', 'net', '30', 'transfer'], [$form['code']->getValue(), $form['kind']->getValue(), $form['account']->getValue(), $form['termKind']->getValue(), $form['termDays']->getValue(), $form['paymentMethod']->getValue()]);
        self::assertSelectorTextContains('#account_account', 'Fournisseurs');
        $form['account'] = '411000';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#name_error', 'Indiquez le nom du tiers.');
        self::assertSelectorTextContains('#account_error', 'Le compte d’un fournisseur est un compte de fournisseurs');

        $form['account'] = '401000';
        $form['name'] = 'Scierie du Haut-Forez';
        $form['siren'] = '512 904 337';
        $form['vatNumber'] = 'FR41512904337';
        $form['city'] = 'Montbrison';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/accounting/third-parties/F0001'));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Tiers F0001 créé. Code auxiliaire 401SCIE attribué sur le compte 401000.');
        self::assertSelectorTextContains('.px-pagehead', 'Fournisseur');
        self::assertSelectorTextContains('main', '30 jours date de facture : une facture du 15/03/2026 (date du dossier) échoit le 14/04/2026.');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/third-parties?kind=supplier&q=scie'));
        self::assertSelectorTextContains('.px-filters__chip[aria-current="true"]', 'Fournisseurs');
        self::assertSame(['F0001', 'Scierie du Haut-Forez', 'Fournisseur', '401000', '401SCIE', 'Montbrison', '30 jours date de facture · virement', ''], $crawler->filter('tbody tr td')->each(static fn ($cell): string => trim($cell->text())));
        $client->request('GET', $this->url($dossier, '/accounting/third-parties?q=dumas'));
        self::assertSelectorTextContains('.px-empty', 'Aucun tiers de ce filtre ne correspond à « dumas ».');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/third-parties/F0001'));
        $form = $crawler->filter('#third-party-form')->form();
        $form['termKind'] = 'end_of_month_net';
        $form['termDays'] = '45';
        $form['paymentMethod'] = 'cheque';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/accounting/third-parties/F0001'));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Tiers F0001 enregistré.');
        self::assertSelectorTextContains('main', 'Fin de mois + 45 jours : une facture du 15/03/2026 (date du dossier) échoit le 15/05/2026.');

        $client->submit($crawler->filter('#third-party-delete')->form());
        self::assertResponseRedirects($this->url($dossier, '/accounting/third-parties'));
        $client->request('GET', $this->url($dossier, '/accounting/third-parties/F0001'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheAccountPickerFindsAuxiliaryCodesButNotWhereANatureNarrowsIt(): void
    {
        [$client, $dossier] = $this->school();
        $this->thirdParty($client, $dossier, 'F0001', 'supplier', 'Scierie du Haut-Forez', '401000', '401SCIE');
        $this->thirdParty($client, $dossier, 'O0001', 'other', 'Urssaf Rhône-Alpes', '431000');
        $this->as($client, 'ines.morel');

        foreach (['401S', 'scierie', 'F00'] as $query) {
            $groups = $this->json($client, $this->url($dossier, '/accounting/accounts/search?q='.$query))['groups'] ?? null;
            self::assertSame([['label' => 'Tiers', 'options' => [['value' => '401SCIE', 'label' => 'Scierie du Haut-Forez · 401000', 'balance' => '']]]], $groups, $query);
        }
        self::assertNotContains('Tiers', $this->groupLabels($client, $this->url($dossier, '/accounting/accounts/search?q=urssaf')), 'Un tiers sur un compte général se saisit par son compte.');
        self::assertSame([], $this->groupLabels($client, $this->url($dossier, '/accounting/accounts/search?q=scierie&nature=supplier')));
        self::assertNotContains('Tiers', $this->groupLabels($client, $this->url($dossier, '/accounting/accounts/search?q=401')));
        self::assertSame('Aucun compte ni tiers ne correspond à « 401X ».', $this->json($client, $this->url($dossier, '/accounting/accounts/search?q=401X'))['empty'] ?? null);
    }

    public function testAnAccountThatCarriesThirdPartiesKeepsItsNatureAndCannotLeave(): void
    {
        [$client, $dossier] = $this->school();
        $this->thirdParty($client, $dossier, 'F0001', 'supplier', 'Scierie du Haut-Forez', '401000', '401SCIE');
        $this->as($client, 'ines.morel');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts/401000'));
        self::assertSelectorNotExists('#account-delete');
        self::assertSelectorTextContains('main', 'Ce compte porte le tiers F0001 : placez-le d’abord sur un autre compte.');
        self::assertSelectorTextContains('main .px-facts', '1 tiers tenu sur ce compte');
        $form = $crawler->filter('#account-form')->form();
        $collective = $form['collective'];
        \assert($collective instanceof ChoiceFormField);
        $collective->untick();
        $form['number'] = '601500';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#number_error', 'Le tiers F0001 est tenu sur ce compte : le compte d’un fournisseur est un compte de fournisseurs');
        self::assertSelectorTextContains('#collective_error', 'Ce compte porte un tiers et son code auxiliaire : il reste collectif.');

        $client->request('GET', $this->url($dossier, '/accounting/third-parties?account=401000'));
        self::assertSelectorTextContains('.px-alert', 'Tiers tenus sur le compte 401000 Fournisseurs.');
        self::assertSelectorTextContains('tbody', '401SCIE');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts/import'));
        $form = $crawler->filter('#import-form')->form();
        $file = $form['file'];
        \assert($file instanceof FileFormField);
        $file->upload($this->file("numero;intitule;lettrable;collectif\n401000;Fournisseurs;oui;non\n"));
        $update = $form['update'];
        \assert($update instanceof ChoiceFormField);
        $update->tick();
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('tbody tr.is-error', 'Ce compte porte un tiers et son code auxiliaire : il reste collectif.');
    }

    public function testThirdPartiesGoOutAndComeBackAsCsv(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/third-parties/import'));
        $form = $crawler->filter('#import-form')->form();
        $file = $form['file'];
        \assert($file instanceof FileFormField);
        $file->upload($this->file("code;nature;nom;ville;conditions;jours\nC0008;client;Atelier Dumas EURL;Feurs;date_de_facture;30\nC0009;client;Atelier Dumont;Feurs;;\nF0001;fournisseur;Scierie;Montbrison;hebdomadaire;\nC0008;client;Doublon;;;\n"));
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-alert--danger', '2 lignes sont refusées');
        self::assertSelectorTextContains('tbody', 'Conditions « hebdomadaire » inconnues');
        self::assertSelectorTextContains('tbody', 'Le code C0008 est déjà celui de Atelier Dumas EURL (ligne 2).');
        self::assertSame([], $this->stored($client, $dossier));

        $file->upload($this->file("code;nature;nom;ville;conditions;jours\nC0008;client;Atelier Dumas EURL;Feurs;date_de_facture;30\nC0009;client;Atelier Dumont;Feurs;;\nF0001;fournisseur;Scierie du Haut-Forez;Montbrison;fin_de_mois_plus;45\n"));
        $client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-alert--success', '3 tiers ajoutés, 0 modifié, 0 déjà dans le dossier sans changement.');
        self::assertSelectorTextContains('main', 'Codes auxiliaires attribués');
        self::assertSelectorTextContains('tbody', 'Code auxiliaire 411ATEL2 attribué sur le compte 411000.');
        self::assertSame(['C0008 411000 411ATEL', 'C0009 411000 411ATEL2', 'F0001 401000 401SCIE'], $this->stored($client, $dossier));

        $client->request('GET', $this->url($dossier, '/accounting/third-parties/export.csv'));
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment; filename=tiers-menuiserie-vasseur-sarl.csv', (string) $client->getResponse()->headers->get('Content-Disposition'));
        $csv = (string) $client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}code;nature;nom;particulier;compte;auxiliaire;siren;tva;adresse;complement;code_postal;ville;pays;contact;email;telephone;conditions;jours;jour;mode;note\r\n", $csv);
        self::assertStringContainsString("\r\nF0001;fournisseur;Scierie du Haut-Forez;non;401000;401SCIE;;;;;;Montbrison;FR;;;;fin_de_mois_plus;45;;virement;\r\n", $csv);

        // The export read back: everything is already there.
        $file->upload($this->file($csv));
        $update = $form['update'];
        \assert($update instanceof ChoiceFormField);
        $update->tick();
        $client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-alert--success', '0 tiers ajouté, 0 modifié, 3 déjà dans le dossier sans changement.');
    }

    public function testWhoOnlyReadsSeesTheFactsWithoutAField(): void
    {
        [$client] = $this->school();
        $template = $this->savedDossier(DossierKind::Template, null, null, 'Modèle commun');
        $this->installPlan($template);
        $this->thirdParty($client, $template, 'F0001', 'supplier', 'Scierie du Haut-Forez', '401000', '401SCIE');
        $this->as($client, 'prof.durand');

        $client->request('GET', $this->url($template, '/accounting/third-parties'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[data-px-shortcut="N"]');
        $client->request('GET', $this->url($template, '/accounting/third-parties/F0001'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#third-party-form');
        self::assertSelectorTextContains('main .px-facts', '401SCIE');
        foreach (['/accounting/third-parties/new', '/accounting/third-parties/import'] as $path) {
            $client->request('GET', $this->url($template, $path));
            self::assertResponseStatusCodeSame(404, $path);
        }
        $client->request('POST', $this->url($template, '/accounting/third-parties/F0001'), ['name' => 'Changé', '_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $this->url($template, '/accounting/third-parties/F0001/delete'), ['_token' => 'x']);
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

    /** A third party saved through ThirdPartySheet, as the fiche would. */
    private function thirdParty(KernelBrowser $client, Dossier $dossier, string $code, string $kind, string $name, string $account, string $auxiliary = ''): void
    {
        $martin = $this->account($client, 'prof.martin');
        $dossier = $client->getContainer()->get(EntityManagerInterface::class)->find(Dossier::class, $dossier->getId());
        \assert($martin instanceof User && $dossier instanceof Dossier);
        $client->getContainer()->get(DossierContext::class)->within($dossier, static function () use ($client, $dossier, $code, $kind, $name, $account, $auxiliary, $martin): void {
            $sheet = $client->getContainer()->get(ThirdPartySheet::class);
            $draft = new ThirdPartyDraft();
            [$draft->code, $draft->kind, $draft->name, $draft->account, $draft->auxiliaryCode] = [$code, $kind, $name, $account, $auxiliary];
            [$draft->termKind, $draft->termDays, $draft->paymentMethod] = ['net', '30', 'transfer'];
            $fields = $sheet->check($draft, $dossier);
            \assert(null !== $fields);
            $sheet->create($dossier, $draft, $fields, $martin);
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
        });
    }

    /** @return list<string> « code account auxiliary » of the stored third parties */
    private function stored(KernelBrowser $client, Dossier $dossier): array
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        $fresh = $client->getContainer()->get(EntityManagerInterface::class)->find(Dossier::class, $dossier->getId());
        \assert($fresh instanceof Dossier);

        return $client->getContainer()->get(DossierContext::class)->within($fresh, static fn (): array => array_map(
            static fn (ThirdParty $t): string => trim($t->getCode().' '.$t->getAccount()?->getNumber().' '.$t->getAuxiliaryCode()),
            self::getContainer()->get(ThirdPartyRepository::class)->findByDossier($fresh),
        ));
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

    /** @return array<mixed> */
    private function json(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $client->getInternalResponse()->getContent(), true);
        self::assertIsArray($result);

        return $result;
    }

    /** @return list<mixed> the labels of the picker's groups */
    private function groupLabels(KernelBrowser $client, string $url): array
    {
        $groups = $this->json($client, $url)['groups'] ?? null;

        return array_values(array_map(static fn (mixed $g): mixed => \is_array($g) ? ($g['label'] ?? null) : null, \is_array($groups) ? $groups : []));
    }

    /** A CSV file on disk, for the form's file field. */
    private function file(string $content): string
    {
        $path = sys_get_temp_dir().'/tiers-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, $content);

        return $path;
    }
}
