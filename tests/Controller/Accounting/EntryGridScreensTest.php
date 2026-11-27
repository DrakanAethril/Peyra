<?php

declare(strict_types=1);

namespace App\Tests\Controller\Accounting;

use App\Entity\Accounting\PendingEntry;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\DossierContext;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Comptabilité › Saisie, through HTTP as the browser speaks it (step 1.4a): the page and what it
 * hands the grid, the pending work kept at each line left, F10, Ctrl Entrée, Alt Inser, and the
 * fiche of an entry - validate, delete, reverse, reverse and enter again, duplicate. ines.morel's
 * Menuiserie Vasseur (date 15/03/2026); the purchase FA-2601-0012 is 3 465,10 + 693,02 = 4 158,12.
 */
final class EntryGridScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testThePageHandsTheGridTheMonthAndTheLookup(): void
    {
        [$client, $dossier] = $this->school();
        $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true);
        $client->getContainer()->get(DossierContext::class)->leave();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entry?journal=ACH&period=2026-01-01'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Journal ACH · janvier 2026');
        $grid = $this->json($crawler->filter('[data-controller="entry-grid"]')->attr('data-entry-grid-grid-value'));
        self::assertSame('ACH', self::dig($grid, 'journal', 'code'));
        self::assertSame('validated', self::dig($grid, 'groups', 0, 'state'));
        self::assertSame('ACH-2026-00001', self::dig($grid, 'groups', 0, 'number'));
        self::assertSame('401SCIE', self::dig($grid, 'groups', 0, 'lines', 2, 'account'));
        $lookup = $this->json($crawler->filter('[data-controller="entry-grid"]')->attr('data-entry-grid-lookup-value'));
        $parties = self::dig($lookup, 'thirdParties');
        \assert(\is_array($parties));
        $scierie = array_values(array_filter($parties, static fn (mixed $t): bool => 'F0001' === self::dig($t, 'c')))[0] ?? null;
        self::assertSame(['a' => '401SCIE', 'c' => 'F0001', 'n' => 'Scierie du Haut-Forez', 'k' => '401000', 'b' => -415_812], $scierie);
        self::assertSelectorExists('#entry-save[data-px-shortcut="F10 Ctrl+S"]');

        $client->request('GET', $this->url($dossier, '/accounting/entry'));
        self::assertSelectorTextContains('h1', 'Journal ACH · janvier 2026', 'Le journal et le mois de la dernière saisie sont repris.');
    }

    public function testTheGridKeepsSavesAndValidates(): void
    {
        [$client, $dossier] = $this->school();
        $token = $this->token($client, $dossier);
        $group = ['key' => 'k1', 'entry' => null, 'date' => '2026-01-08', 'document' => 'FA-2601-0012', 'lines' => [
            ['account' => '607000', 'thirdParty' => '', 'label' => 'Scierie FA 0012', 'debit' => 346_510, 'credit' => 0, 'due' => null],
            ['account' => '445660', 'thirdParty' => '', 'label' => 'Scierie FA 0012', 'debit' => 69_302, 'credit' => 0, 'due' => null],
            ['account' => '401SCIE', 'thirdParty' => '', 'label' => 'Scierie FA 0012', 'debit' => 0, 'credit' => 400_000, 'due' => null],
        ]];

        $kept = $this->post($client, $dossier, '/keep', $token, ['revision' => 0, 'groups' => [$group]]);
        self::assertSame(1, self::dig($kept, 'revision'));
        $unbalanced = self::dig($kept, 'checks', 'k1', 'errors', 'lines');
        self::assertIsString($unbalanced);
        self::assertStringStartsWith('L’écriture n’est pas équilibrée', $unbalanced);
        self::assertSame([['index' => 2, 'date' => '2026-02-07']], self::dig($kept, 'checks', 'k1', 'dues'));

        $this->post($client, $dossier, '/keep', $token, ['revision' => 0, 'groups' => [$group]]);
        self::assertResponseStatusCodeSame(409, 'Un onglet qui a lu une révision plus ancienne est refusé.');

        $group['lines'][2]['credit'] = 415_812;
        $saved = $this->post($client, $dossier, '/save', $token, ['revision' => 1, 'groups' => [$group]]);
        self::assertSame('1 écriture enregistrée au brouillard.', $saved['message']);
        self::assertSame('draft', self::dig($saved, 'groups', 0, 'state'));
        self::assertSame(1, $saved['draftsCount']);

        $validated = $this->post($client, $dossier, '/validate', $token, ['revision' => self::dig($saved, 'revision'), 'groups' => []]);
        self::assertSame('Écriture ACH-2026-00001 validée.', $validated['message']);
        self::assertSame('validated', self::dig($validated, 'groups', 0, 'state'));
    }

    public function testAPostWithoutTheTokenOrInAClosedMonthIsRefused(): void
    {
        [$client, $dossier] = $this->school();
        $token = $this->token($client, $dossier);
        $client->request('POST', $this->url($dossier, '/accounting/entry/keep'), server: ['CONTENT_TYPE' => 'application/json'], content: '{"journal":"ACH","period":"2026-01-01","groups":[]}');
        self::assertResponseStatusCodeSame(404);

        $client->getContainer()->get(DossierContext::class)->within($dossier, static function () use ($client, $dossier): void {
            $client->getContainer()->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-01-15'))?->close();
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
        });
        $this->post($client, $dossier, '/keep', $token, ['revision' => 0, 'groups' => []]);
        self::assertResponseStatusCodeSame(404);
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entry?journal=ACH&period=2026-01-01'));
        self::assertSelectorTextContains('main', 'Janvier 2026 est clos : ses écritures se lisent, elles ne se saisissent plus.');
        self::assertSame('false', $crawler->filter('[data-controller="entry-grid"]')->attr('data-entry-grid-editable-value'));
        self::assertSelectorNotExists('#entry-save');
    }

    public function testAltInsertCreatesTheAccountTyped(): void
    {
        [$client, $dossier] = $this->school();
        $token = $this->token($client, $dossier);

        $created = $this->post($client, $dossier, '/account', $token, ['number' => '601900', 'label' => 'Achats de bois exotiques']);
        self::assertSame(['n' => '601900', 'l' => 'Achats de bois exotiques', 'c' => false, 't' => false, 'b' => 0], $created['account']);
        self::assertSame('Compte 601900 créé.', $created['message']);

        $refused = $this->post($client, $dossier, '/account', $token, ['number' => '601900', 'label' => 'Encore']);
        self::assertResponseStatusCodeSame(422);
        self::assertNotNull(self::dig($refused, 'errors', 'number'));
    }

    public function testTheFicheOfAnEntryValidatesReversesAndDuplicates(): void
    {
        [$client, $dossier] = $this->school();
        $draft = $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12']);
        $client->getContainer()->get(DossierContext::class)->leave();
        $id = (int) $draft->getId();

        $client->request('GET', $this->url($dossier, '/accounting/entries/'.$id));
        self::assertSelectorTextContains('h1', 'Écriture Brouillard · FA-2601-0012');
        self::assertSelectorTextContains('#entry-validate-text', 'Valider l’écriture FA-2601-0012 ?');
        $client->submitForm('Valider l’écriture');
        self::assertResponseRedirects($this->url($dossier, '/accounting/entries/'.$id));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Écriture ACH-2026-00001 validée.');

        $client->submitForm('Contrepasser et ressaisir', ['date' => '2026-03-15']);
        self::assertResponseRedirects($this->url($dossier, '/accounting/entry?journal=ACH&period=2026-01-01&entry=-1'));
        $entries = $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): array => $client->getContainer()->get(EntryRepository::class)->findAll());
        self::assertCount(2, $entries);
        self::assertSame('ACH-2026-00002', $entries[1]->getNumberLabel());
        self::assertSame('2026-03-15', $entries[1]->getEntryDate()->format('Y-m-d'));
        $pending = $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): array => $client->getContainer()->get(EntityManagerInterface::class)->getRepository(PendingEntry::class)->findAll());
        self::assertCount(1, $pending[0]->getGroups(), 'La copie à ressaisir attend dans la grille de janvier.');
        self::assertSame('401SCIE', $pending[0]->getGroups()[0]->lines[2]->account);

        $client->request('GET', $this->url($dossier, '/accounting/entries/'.$id));
        self::assertSelectorTextContains('main', 'Contrepassée par');
        self::assertSelectorNotExists('form[action$="/reverse"]');
        $client->submitForm('Dupliquer');
        self::assertResponseRedirects($this->url($dossier, '/accounting/entry?journal=ACH&period=2026-01-01&entry=-1'));
    }

    public function testADraftIsDeletedFromItsFiche(): void
    {
        [$client, $dossier] = $this->school();
        $draft = $this->entry($dossier, 'ACH', '2026-01-08', 'FA-1', ['607000 D 10.00', '401000/F0001 C 10.00']);
        $client->getContainer()->get(DossierContext::class)->leave();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entries/'.$draft->getId()));
        $client->submit($crawler->filter('#entry-delete')->form());
        self::assertResponseRedirects($this->url($dossier, '/accounting/entry?journal=ACH&period=2026-01-01'));
        self::assertSame([], $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): array => $client->getContainer()->get(EntryRepository::class)->findAll()));
    }

    /** @return array{KernelBrowser, Dossier} */
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

        return [$client, $dossier];
    }

    private function token(KernelBrowser $client, Dossier $dossier): string
    {
        $client->getContainer()->get(DossierContext::class)->leave();
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/entry?journal=ACH&period=2026-01-01'));

        return (string) $crawler->filter('[data-controller="entry-grid"]')->attr('data-entry-grid-token-value');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, Dossier $dossier, string $path, string $token, array $body): array
    {
        $client->request('POST', $this->url($dossier, '/accounting/entry'.$path), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PEYRA_TOKEN' => $token], content: (string) json_encode(['journal' => 'ACH', 'period' => '2026-01-01', ...$body]));

        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /** @return array<string, mixed> */
    private function json(?string $text): array
    {
        $data = json_decode((string) $text, true);
        \assert(\is_array($data));

        return $data;
    }

    /** A value deep in a decoded answer, or null where the path stops. */
    private static function dig(mixed $data, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
