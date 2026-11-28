<?php

declare(strict_types=1);

namespace App\Tests\Controller\Dossier;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\Search\SearchScreens;
use App\Service\Identity\SchoolClasses;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The global search (Ctrl K, step 1.5c) through HTTP, on ines.morel's dossier: journals ACH, VTE,
 * BQ, OD, AN; the Scierie (F0001, 401SCIE) and two other third parties; the opening (AN-2026-00001),
 * the Scierie's invoice (ACH-2026-00001, document FA-2601-0012, 4 158,12) and its payment left in
 * the brouillard (VIR-SCIE, 2 400,00).
 */
final class GlobalSearchTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testTheTriggerIsInEveryScreenOfTheDossier(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/accounting/brouillard'));
        self::assertSelectorExists('.px-topbar [data-px-shortcut="Ctrl+K"][data-px-open="px-cmdk"]');
        self::assertSelectorExists('dialog#px-cmdk[data-command-palette-src-value="'.$this->url($dossier, '/search').'"]');
    }

    public function testAJournalsCodeOpensItsGridFirst(): void
    {
        [$client, $dossier] = $this->school();

        $options = $this->search($client, $dossier, 'ach');

        // « Ctrl K, ach, Entrée » opens the purchases' grid (design-ux.md § 4.4): it is the first line.
        self::assertSame('Écrans', $this->groupOf($options->first()));
        self::assertSame($this->url($dossier, '/accounting/entry?journal=ACH'), $options->first()->attr('href'));
        self::assertStringContainsString('Achats', $options->first()->text());
        self::assertSame('ACH', $options->first()->filter('mark')->text());
    }

    public function testAccountsAndThirdPartiesOpenTheirConsultationAndComeBack(): void
    {
        [$client, $dossier] = $this->school();
        $here = $this->url($dossier, '/accounting/editions/trial-balance');

        $options = $this->search($client, $dossier, '401', $here);
        $hrefs = $options->each(static fn (Crawler $option): string => rawurldecode((string) $option->attr('href')));

        self::assertContains($this->url($dossier, '/accounting/consultation?account=401000&return='.$here), $hrefs);
        self::assertContains($this->url($dossier, '/accounting/consultation?account=401SCIE&return='.$here), $hrefs);
        self::assertSame(['Comptes', 'Tiers'], array_values(array_unique($options->each(fn (Crawler $option): string => $this->groupOf($option)))));
        $scierie = $options->filter('[href*="account=401SCIE"]');
        self::assertStringContainsString('Scierie du Haut-Forez', $scierie->text());
        self::assertSame('401', $scierie->filter('mark')->text());
        self::assertSame('401', $options->first()->filter('mark')->text());

        // The way back leads to the screen the search was made from.
        $client->request('GET', (string) $scierie->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.px-pagehead a[href="'.$here.'"]');

        // A way back outside the dossier is dropped.
        $options = $this->search($client, $dossier, '401', 'https://example.org/');
        self::assertStringNotContainsString('return', (string) $options->first()->attr('href'));
    }

    public function testEntriesAreFoundByNumberDocumentOrAmount(): void
    {
        [$client, $dossier, $entries] = $this->school();
        $invoice = $this->url($dossier, '/accounting/entries/'.$entries['ACH']->getId());

        foreach (['ACH-2026-00001', 'ach-2026-1', 'FA-2601', '4158,12', '4 158.12'] as $typed) {
            $options = $this->search($client, $dossier, $typed)->filter('[href^="'.$invoice.'"]');
            self::assertCount(1, $options, $typed);
            self::assertSame('Écritures', $this->groupOf($options->first()), $typed);
            self::assertStringContainsString('ACH-2026-00001', $options->first()->text(), $typed);
        }

        // A draft is found by its document or its amount, and says it is in the brouillard.
        $draft = $this->search($client, $dossier, '2400')->filter('[href^="'.$this->url($dossier, '/accounting/entries/'.$entries['BQ']->getId()).'"]');
        self::assertCount(1, $draft);
        self::assertStringContainsString('Brouillard', $draft->text());

        // Another exercise's number, or a number nobody has, finds nothing.
        self::assertCount(0, $this->search($client, $dossier, 'ACH-2025-00001')->filter('[href*="/accounting/entries/"]'));
        self::assertCount(0, $this->search($client, $dossier, 'ACH-2026-00009')->filter('[href*="/accounting/entries/"]'));
    }

    public function testNothingThatAnswersIsSaid(): void
    {
        [$client, $dossier] = $this->school();

        $client->request('GET', $this->url($dossier, '/search?q=zzzz'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#px-cmdk-results', 'Rien ne répond à « zzzz » dans ce dossier.');
    }

    public function testAnEmptyFieldShowsTheScreensOpenedLast(): void
    {
        [$client, $dossier] = $this->school();

        // Nothing opened yet in this dossier: the first screens of the accounting.
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $options = $this->search($client, $dossier, '');
        self::assertSame(['Écrans'], array_values(array_unique($options->each(fn (Crawler $option): string => $this->groupOf($option)))));
        self::assertCount(5, $options);

        $client->request('GET', $this->url($dossier, '/accounting/editions/general-ledger'));
        $client->request('GET', $this->url($dossier, '/accounting/entry?journal=ach'));
        $client->request('GET', $this->url($dossier, '/accounting/brouillard'));
        $client->request('GET', $this->url($dossier, '/accounting/editions/general-ledger'));
        // A fiche, a refused filter and a fragment are not screens of the catalogue.
        $client->request('GET', $this->url($dossier, '/accounting/accounts/601000'));
        $client->request('GET', $this->url($dossier, '/accounting/consultation/peek?account=401SCIE'));

        $options = $this->search($client, $dossier, '');
        $recent = $options->reduce(fn (Crawler $option): bool => 'Récents' === $this->groupOf($option));
        self::assertSame(
            [$this->url($dossier, '/accounting/editions/general-ledger'), $this->url($dossier, '/accounting/brouillard'), $this->url($dossier, '/accounting/entry?journal=ACH')],
            $recent->each(static fn (Crawler $option): string => (string) $option->attr('href')),
        );
        // Completed up to five by the first screens not already there.
        self::assertCount(5, $options);
        self::assertNotContains($this->url($dossier, '/accounting/brouillard'), $options->reduce(fn (Crawler $option): bool => 'Écrans' === $this->groupOf($option))->each(static fn (Crawler $option): string => (string) $option->attr('href')));
    }

    public function testActionsAndTheGuidedEntryFollowTheRights(): void
    {
        [$client, $dossier] = $this->school();

        // The student changes the dossier: « Nouveau compte »; the guided entry is closed at « Avertissements ».
        self::assertSame($this->url($dossier, '/accounting/accounts/new'), $this->search($client, $dossier, 'nouveau compte')->first()->attr('href'));
        self::assertCount(0, $this->search($client, $dossier, 'guidee')->filter('[href="'.$this->url($dossier, '/accounting/entry/guided').'"]'));

        // Their teacher has it.
        $this->signOut($client);
        $this->submitLogin($client, 'prof.martin');
        self::assertCount(1, $this->search($client, $dossier, 'guidee')->filter('[href="'.$this->url($dossier, '/accounting/entry/guided').'"]'));

        // Whoever only reads a dossier is offered no action.
        $screens = $client->getContainer()->get(SearchScreens::class);
        $client->getContainer()->get(DossierContext::class)->enter($dossier);
        self::assertSame([], $screens->actions($dossier, false));
        self::assertNotSame([], $screens->actions($dossier, true));
        $items = [...$screens->screens($dossier, true, true), ...$screens->actions($dossier, true)];
        $keys = array_map(static fn ($item): string => $item->key, $screens->screens($dossier, true, true));
        $client->getContainer()->get(DossierContext::class)->leave();

        // Every pictogram the catalogue names exists, and every screen's route is one a visit records.
        foreach ($items as $item) {
            self::assertFileExists(\dirname(__DIR__, 3).'/assets/icons/'.$item->icon.'.svg', $item->label);
        }
        foreach ($keys as $key) {
            self::assertContains(explode(':', $key)[0], SearchScreens::SCREEN_ROUTES, $key);
        }
    }

    public function testAnotherDossierIsNotSearched(): void
    {
        [$client, $dossier] = $this->school();

        $this->signOut($client);
        $this->submitLogin($client, 'lucas.bernard');
        $client->request('GET', $this->url($dossier, '/search?q=ach'));
        self::assertResponseStatusCodeSame(404);
    }

    private function search(KernelBrowser $client, Dossier $dossier, string $query, ?string $here = null): Crawler
    {
        $crawler = $client->request('GET', $this->url($dossier, '/search'), ['q' => $query] + (null === $here ? [] : ['here' => $here]));
        self::assertResponseIsSuccessful();

        return $crawler->filter('turbo-frame#px-cmdk-results [role="option"]');
    }

    private function groupOf(Crawler $option): string
    {
        return trim($option->ancestors()->filter('[role="group"]')->first()->filter('.px-picker__group')->text());
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
}
