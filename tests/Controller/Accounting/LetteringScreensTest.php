<?php

declare(strict_types=1);

namespace App\Tests\Controller\Accounting;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
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

/**
 * Lettrage and À-nouveaux through HTTP (step 1.6), on ines.morel's Menuiserie Vasseur: the opening
 * (AN 01/01: 401SCIE C 2 400,00), the Scierie's invoice 4 158,12 and two transfers, 2 400,00 and
 * 1 000,00. The list, the face-à-face, lettering a pair, Ctrl Z, the automatic lettering, the
 * refusals; the opening balance and its controls.
 */
final class LetteringScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testAPairIsLetteredUndoneAndLetteredAutomatically(): void
    {
        [$client, $dossier, $lines] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/lettering'));
        self::assertResponseIsSuccessful();
        self::assertSame('401SCIE', trim($crawler->filter('main tbody tr td')->eq(1)->text()));
        self::assertSame('4', trim($crawler->filter('main tbody tr td')->eq(3)->text()), 'Four lines to letter.');
        self::assertSelectorTextContains('.px-statusbar', '1 compte à lettrer');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/lettering?account=401000'));
        self::assertSelectorTextContains('main', 'Le compte 401000 est collectif');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/lettering?account=401scie'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Lettrage · 401SCIE · Scierie du Haut-Forez');
        self::assertCount(2, $crawler->filter('main .px-split .px-card')->first()->filter('tbody tr'), 'Two transfers on the debit side.');
        $token = (string) $crawler->filter('form.px-lettering input[name="_token"]')->attr('value');

        $client->request('POST', $this->url($dossier, '/accounting/lettering/letter'), ['_token' => 'forged', 'account' => '401SCIE', 'lines' => [(string) $lines['opening']->getId(), (string) $lines['transfer']->getId()]]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', $this->url($dossier, '/accounting/lettering/letter'), ['_token' => $token, 'account' => '401SCIE', 'lines' => [(string) $lines['opening']->getId(), (string) $lines['transfer']->getId()]]);
        self::assertResponseRedirects($this->url($dossier, '/accounting/lettering?account=401SCIE&last=A'));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Lettrage A : 2 lignes soldées, 2 400,00.', self::spaces($crawler->filter('.px-statusbar')->text()));
        self::assertSame('Ctrl+Z', $crawler->filter('button[name="letter"][value="A"]')->attr('data-px-shortcut'));

        // Lettered lines leave the face-à-face; « Afficher les lettrées » brings them back with their letter.
        self::assertCount(1, $crawler->filter('main .px-split .px-card')->first()->filter('tbody tr'));
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/lettering?account=401SCIE&all=1'));
        self::assertCount(1, $crawler->filter('button[data-lettering-unletter][value="A"]'), 'One « Délettrer » per group.');

        // A partial lettering is refused nowhere at « Avertissements »; a pair across two accounts is.
        $client->request('POST', $this->url($dossier, '/accounting/lettering/letter'), ['_token' => $token, 'account' => '401SCIE', 'lines' => [(string) $lines['bank']->getId(), (string) $lines['invoice']->getId()]]);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main .px-alert', 'un lettrage tient sur un seul compte');

        $client->request('POST', $this->url($dossier, '/accounting/lettering/unletter'), ['_token' => $token, 'account' => '401SCIE', 'letter' => 'A']);
        self::assertResponseRedirects($this->url($dossier, '/accounting/lettering?account=401SCIE'));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Lettrage A défait : 2 lignes à lettrer de nouveau.');

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/lettering?account=401SCIE&auto=amount'));
        self::assertSelectorTextContains('main', 'Lettrage automatique par montant');
        self::assertCount(3, $crawler->filter('main .px-badge:contains("Proposé A")'), 'Once in the preview, on both lines in the tables.');
        $client->request('POST', $this->url($dossier, '/accounting/lettering/auto'), ['_token' => $token, 'account' => '401SCIE', 'mode' => 'amount']);
        self::assertResponseRedirects($this->url($dossier, '/accounting/lettering?account=401SCIE&auto=none'));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Lettrage automatique par montant : 1 groupe lettré (A).');

        // The consultation shows the letter and leads to the lettering.
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/consultation?account=401SCIE'));
        self::assertStringContainsString('/accounting/lettering?account=401SCIE', (string) $crawler->filter('main a:contains("Lettrer")')->attr('href'));
    }

    public function testTheOpeningBalanceAndItsControls(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/accounting/opening'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'À-nouveaux de l’exercice 2026');
        $checks = self::spaces($crawler->filter('main .px-checks')->text());
        self::assertStringContainsString('Les écritures d’à-nouveaux sont équilibrées : 10 000,00 au débit et au crédit.', $checks);
        self::assertStringContainsString('Seuls des comptes de bilan (classes 1 à 5) sont repris.', $checks);
        self::assertSame(['101300', '401000', '512000'], $crawler->filter('main table')->last()->filter('tbody tr td:first-child')->each(static fn ($cell): string => trim($cell->text())));
        self::assertStringContainsString('journal=AN&period=2026-01-01', (string) $crawler->filter('main a:contains("Saisir les à-nouveaux")')->attr('href'));

        // A tab of Saisie, and Ctrl K finds it.
        self::assertCount(1, $crawler->filter('.px-tabs a:contains("À-nouveaux")'));
        $crawler = $client->request('GET', $this->url($dossier, '/search?q=nouveaux'));
        self::assertContains($this->url($dossier, '/accounting/opening'), $crawler->filter('a')->each(static fn ($link): string => (string) $link->attr('href')));
    }

    /** @return array{KernelBrowser, Dossier, array<string, EntryLine>} */
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
        $opening = $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026', ['512000 D 10000.00', '401000/F0001 C 2400.00', '101300 C 7600.00'], true);
        $invoice = $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true, 'Facture Scierie');
        $transfer = $this->entry($dossier, 'BQ', '2026-01-20', 'VIR-SCIE', ['401000/F0001 D 2400.00', '512000 C 2400.00'], true, 'Règlement Scierie');
        $this->entry($dossier, 'BQ', '2026-01-25', 'VIR-SCIE-2', ['401000/F0001 D 1000.00', '512000 C 1000.00'], true, 'Acompte Scierie');
        $client->getContainer()->get(DossierContext::class)->leave();
        $line = static fn (Entry $entry, string $account): EntryLine => array_values(array_filter($entry->getLines(), static fn (EntryLine $line): bool => $line->getAccount()->getNumber() === $account))[0];

        return [$client, $dossier, [
            'opening' => $line($opening, '401000'),
            'invoice' => $line($invoice, '401000'),
            'transfer' => $line($transfer, '401000'),
            'bank' => $line($transfer, '512000'),
        ]];
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
