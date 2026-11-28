<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\EntryState;
use App\Enum\Document\DocumentState;
use App\Enum\Dossier\DossierKind;
use App\Repository\Document\DocumentRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Service\Shared\Storage\DossierFiles;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The saisie par pièce (step 2.2a) through HTTP, on ines.morel's dossier, the Scierie's last
 * purchase entered on 607000: the read data saved make the proposal, F10 refuses an unbalanced
 * entry and writes nothing, then writes the corrected one in the brouillard and opens the next
 * pièce; an anomaly needs its comment, and « Rejeter la pièce » sets the pièce aside.
 */
final class DocumentProcessingTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    private const string PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    public function testTheProposedEntryIsCheckedThenWrittenInTheBrouillard(): void
    {
        [$client, $dossier] = $this->school();
        $first = $this->deposit($client, $dossier);
        $second = $this->deposit($client, $dossier);
        $sheet = $this->url($dossier, '/documents/'.$first);

        // The read data saved without an entry: the pièce is « en cours », its entry proposed.
        $crawler = $client->request('GET', $sheet);
        $token = (string) $crawler->filter('#document-reading input[name="_token"]')->attr('value');
        $client->request('POST', $sheet, $this->reading($client, $dossier, $token));
        self::assertResponseRedirects($sheet, 303);
        self::assertSame(DocumentState::InProgress, $this->document($client, $dossier, $first)->getState());
        $crawler = $client->request('GET', $sheet);
        self::assertSelectorTextContains('#piece-entry-title', 'Écriture proposée');
        self::assertSame(['607000', '445660', '401SCIE', ''], $crawler->filter('.px-piece__lines > tbody input[name$="[account]"]')->each(static fn ($input): string => (string) $input->attr('value')));
        self::assertCount(3, $crawler->filter('.px-piece__lines > tbody.is-proposed'));
        self::assertSelectorTextContains('.px-checks', 'TVA cohérente avec la base');

        // F10 on an entry that does not balance: refused, nothing written.
        $entry = ['entry_journal' => 'ACH', 'entry_date' => '2026-03-12', 'entry_document' => 'FA-2603-0112', 'entry_label' => 'Scierie FA 0112'];
        $client->request('POST', $sheet, $this->reading($client, $dossier, $token) + $entry + ['lines' => [
            ['account' => '601000', 'debit' => '270700'],
            ['account' => '401SCIE', 'credit' => '324840', 'due' => '2026-04-11'],
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-alert', 'n’est pas équilibrée');
        self::assertSame(DocumentState::InProgress, $this->document($client, $dossier, $first)->getState());

        // Corrected (601000 for 607000) and balanced: in the brouillard, and the next pièce opens.
        $client->request('POST', $sheet, $this->reading($client, $dossier, $token) + $entry + ['lines' => [
            ['account' => '601000', 'debit' => '270700'],
            ['account' => '445660', 'debit' => '54140'],
            ['account' => '401SCIE', 'credit' => '324840', 'due' => '2026-04-11'],
        ]]);
        self::assertResponseRedirects($this->url($dossier, '/documents/'.$second), 303);
        $posted = $this->document($client, $dossier, $first);
        self::assertSame(DocumentState::Posted, $posted->getState());
        $written = $posted->getEntry();
        self::assertNotNull($written);
        self::assertSame([EntryState::Draft, EntryOrigin::Proposal, 'FA-2603-0112', 324_840], [$written->getState(), $written->getOrigin(), $written->getDocument(), $written->totalDebit()->cents]);
        self::assertSame(['601000', '445660', '401000'], array_map(static fn ($line): string => $line->getAccount()->getNumber(), $written->getLines()));

        // Posted: the read data are read only, the entry offers its correction in the grid.
        $client->request('GET', $sheet);
        self::assertSelectorNotExists('#document-reading');
        self::assertSelectorTextContains('#piece-written-title', 'Écriture');
        self::assertSelectorExists('a.px-btn[href*="/accounting/entry?"]');

        $client->getContainer()->get(DossierFiles::class)->deleteAll((int) $dossier->getId());
    }

    public function testAChangedReadingOffersToTakeTheEntryAgain(): void
    {
        [$client, $dossier] = $this->school();
        $id = $this->deposit($client, $dossier);
        $sheet = $this->url($dossier, '/documents/'.$id);
        $token = (string) $client->request('GET', $sheet)->filter('#document-reading input[name="_token"]')->attr('value');
        $client->request('POST', $sheet, $this->reading($client, $dossier, $token));

        // The TTC corrected: the proposal stays as it was, the banner shows.
        $changed = ['totalIncludingTax' => '324841'] + $this->reading($client, $dossier, $token);
        $client->request('POST', $sheet, $changed);
        $crawler = $client->request('GET', $sheet);
        self::assertNull($crawler->filter('.px-piece__stale')->attr('hidden'));
        self::assertSame('3 248,40', str_replace(["\u{202F}", "\u{00A0}"], ' ', (string) $crawler->filter('#line-2-credit')->attr('value')));

        $client->request('POST', $sheet, ['_action' => 'rebuild'] + $changed);
        self::assertResponseRedirects($sheet, 303);
        $crawler = $client->request('GET', $sheet);
        self::assertNotNull($crawler->filter('.px-piece__stale')->attr('hidden'));
        self::assertSame('3 248,41', str_replace(["\u{202F}", "\u{00A0}"], ' ', (string) $crawler->filter('#line-2-credit')->attr('value')));

        $client->getContainer()->get(DossierFiles::class)->deleteAll((int) $dossier->getId());
    }

    public function testARejectedPieceNeedsItsJustification(): void
    {
        [$client, $dossier] = $this->school();
        $id = $this->deposit($client, $dossier);
        $sheet = $this->url($dossier, '/documents/'.$id);
        $token = (string) $client->request('GET', $sheet)->filter('#document-anomaly-form input[name="_token"]')->attr('value');

        $client->request('POST', $sheet.'/anomaly', ['_token' => $token, 'nature' => 'duplicate', 'outcome' => 'reject', 'comment' => '']);
        self::assertResponseRedirects($sheet, 303);
        self::assertSame(DocumentState::ToProcess, $this->document($client, $dossier, $id)->getState(), 'Sans justification, rien n’est signalé.');

        $client->request('POST', $sheet.'/anomaly', ['_token' => $token, 'nature' => 'duplicate', 'outcome' => 'reject', 'comment' => 'Déjà reçue le 12/03.']);
        $rejected = $this->document($client, $dossier, $id);
        self::assertSame(DocumentState::Discarded, $rejected->getState());
        self::assertSame('Déjà reçue le 12/03.', $rejected->getRejection()?->comment);
        $client->request('GET', $sheet);
        self::assertSelectorTextContains('.px-piece__anomalies', 'Doublon');
        self::assertSelectorNotExists('#document-anomaly');

        // A rejected pièce takes no other anomaly.
        $client->request('POST', $sheet.'/anomaly', ['_token' => $token, 'nature' => 'other', 'outcome' => 'reject', 'comment' => 'Encore.']);
        self::assertResponseStatusCodeSame(404);

        $client->getContainer()->get(DossierFiles::class)->deleteAll((int) $dossier->getId());
    }

    /**
     * The Scierie's invoice FA-2603-0112 as read: 2 707,00 HT, 541,40 VAT at 20 %, 3 248,40 TTC.
     *
     * @return array<string, string>
     */
    private function reading(KernelBrowser $client, Dossier $dossier, string $token): array
    {
        $scierie = (int) $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): ?int => $client->getContainer()->get(ThirdPartyRepository::class)->findOneByCode($dossier, 'F0001')?->getId());

        return ['_token' => $token, 'type' => 'purchase_invoice', 'thirdParty' => (string) $scierie, 'number' => 'FA-2603-0112', 'date' => '2026-03-12', 'dueDate' => '2026-04-11',
            'vat_rate_0' => '20', 'vat_base_0' => '270700', 'vat_amount_0' => '54140', 'totalExcludingTax' => '270700', 'totalVat' => '54140', 'totalIncludingTax' => '324840'];
    }

    private function deposit(KernelBrowser $client, Dossier $dossier): int
    {
        $crawler = $client->request('GET', $this->url($dossier, '/documents/new'));
        $path = tempnam(sys_get_temp_dir(), 'piece');
        \assert(\is_string($path));
        file_put_contents($path, self::PDF);
        $client->request('POST', $this->url($dossier, '/documents/new'), ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'type' => 'purchase_invoice'], ['file' => new UploadedFile($path, 'scan.pdf', null, null, true)]);
        self::assertResponseStatusCodeSame(303);
        $location = (string) $client->getResponse()->headers->get('Location');

        return (int) substr($location, (int) strrpos($location, '/') + 1);
    }

    private function document(KernelBrowser $client, Dossier $dossier, int $id): Document
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        $document = $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): ?Document => $client->getContainer()->get(DocumentRepository::class)->findOneInDossier($dossier, $id));
        \assert($document instanceof Document);

        return $document;
    }

    /** @return array{KernelBrowser, Dossier} */
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
        $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true, 'Facture Scierie');
        $client->getContainer()->get(DossierContext::class)->leave();

        return [$client, $dossier];
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
