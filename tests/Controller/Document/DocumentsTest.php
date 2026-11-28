<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Entity\Audit\AuditEvent;
use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Document\DocumentState;
use App\Enum\Document\DocumentType;
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
 * Pièces (step 2.1a) through HTTP, on ines.morel's dossier (the Scierie, F0001, and an invoice
 * entered under FA-2601-0012): a deposit, the inbox and the rail's counter, the read data typed
 * on the sheet, the file served to whoever opens the dossier, Ctrl K.
 */
final class DocumentsTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    /** A one-page PDF, as small as a reader accepts. */
    private const string PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    public function testADepositArrivesToProcessAndItsReadDataAreTyped(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/documents'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-empty__title', 'Aucune pièce à traiter');

        $crawler = $client->request('GET', $this->url($dossier, '/documents/new'));
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        // Bytes that are not a PDF are refused under the field, and nothing is kept.
        $client->request('POST', $this->url($dossier, '/documents/new'), ['_token' => $token, 'type' => 'purchase_invoice'], ['file' => $this->file('<html>facture</html>', 'FA-2601-0012.pdf')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#file_error', 'ce que son extension .pdf annonce');
        self::assertSame([], $this->documents($client, $dossier));

        $client->request('POST', $this->url($dossier, '/documents/new'), ['_token' => $token, 'type' => 'purchase_invoice'], ['file' => $this->file(self::PDF, 'FA-2601-0012.pdf')]);
        self::assertResponseStatusCodeSame(303);
        [$document] = $this->documents($client, $dossier);
        self::assertSame(DocumentState::ToProcess, $document->getState());
        self::assertSame(DocumentType::PurchaseInvoice, $document->getType());
        self::assertSame('application/pdf', $document->getMediaType());
        self::assertSame('2026-03-15', $document->getReceivedOn()->format('Y-m-d'), 'The dossier’s date, never the computer’s.');
        self::assertStringStartsWith('dossiers/'.$dossier->getId().'/pieces/', $document->getFileKey());
        $sheet = (string) $client->getResponse()->headers->get('Location');
        self::assertSame($this->url($dossier, '/documents/'.$document->getId()), $sheet);

        // The rail counts it; the inbox lists it.
        $client->request('GET', $this->url($dossier, '/documents'));
        self::assertSelectorTextContains('.px-rail a[href="'.$this->url($dossier, '/documents').'"] .px-rail__count', '1');
        self::assertSelectorTextContains('.px-table tbody', 'Facture d’achat FA-2601-0012.pdf');

        // The read data: refused whole at a fault, then saved.
        $crawler = $client->request('GET', $sheet);
        self::assertSelectorExists('iframe.px-viewer__frame[src="'.$sheet.'/file"]');
        $token = (string) $crawler->filter('#document-reading input[name="_token"]')->attr('value');
        $scierie = (int) $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): ?int => $client->getContainer()->get(ThirdPartyRepository::class)->findOneByCode($dossier, 'F0001')?->getId());
        $typed = ['_token' => $token, 'type' => 'purchase_invoice', 'thirdParty' => (string) $scierie, 'number' => 'FA-2601-0012', 'date' => '2026-01-08', 'dueDate' => '2026-02-28',
            'vat_rate_0' => '20', 'vat_base_0' => '346510', 'vat_amount_0' => '69302', 'totalExcludingTax' => '346510', 'totalVat' => '69302', 'totalIncludingTax' => '415812'];
        $client->request('POST', $sheet, ['vat_rate_1' => '33'] + $typed);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#vat_base_1_error');
        $client->request('POST', $sheet, $typed);
        self::assertResponseStatusCodeSame(303);

        [$document] = $this->documents($client, $dossier);
        self::assertSame('Scierie du Haut-Forez', $document->getThirdParty()?->getName());
        self::assertSame('FA-2601-0012', $document->getNumber());
        self::assertSame(415_812, $document->getTotalIncludingTax()?->cents);
        self::assertSame(['20'], array_map(static fn ($line): string => $line->rate, $document->getVatLines()));

        // Its entries are those carrying its number.
        $client->request('GET', $sheet);
        self::assertSelectorTextContains('.px-stack', 'ACH-2026-00001');

        $events = $client->getContainer()->get(EntityManagerInterface::class)->getRepository(AuditEvent::class)->findBy(['subjectId' => (string) $dossier->getId()]);
        $actions = array_map(static fn (AuditEvent $event): AuditAction => $event->getAction(), $events);
        self::assertContains(AuditAction::DocumentDeposited, $actions);
        self::assertContains(AuditAction::DocumentReadingChanged, $actions);
        $client->getContainer()->get(DossierFiles::class)->deleteAll((int) $dossier->getId());
    }

    public function testTheFileIsServedToWhoeverOpensTheDossier(): void
    {
        [$client, $dossier] = $this->school();
        $document = $this->deposit($client, $dossier);
        $file = $this->url($dossier, '/documents/'.$document->getId().'/file');

        $client->request('GET', $file);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringStartsWith('inline;', (string) $client->getResponse()->headers->get('Content-Disposition'));
        $client->request('GET', $file.'?download=1');
        self::assertStringStartsWith('attachment;', (string) $client->getResponse()->headers->get('Content-Disposition'));

        // Her teacher reads it; another student gets nothing.
        $this->signOut($client);
        $this->submitLogin($client, 'prof.martin');
        $client->request('GET', $file);
        self::assertResponseIsSuccessful();
        $this->signOut($client);
        $this->submitLogin($client, 'lucas.bernard');
        $client->request('GET', $file);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $this->url($dossier, '/documents'));
        self::assertResponseStatusCodeSame(404);

        $client->getContainer()->get(DossierFiles::class)->deleteAll((int) $dossier->getId());
    }

    public function testCtrlKFindsAPieceBetweenTheThirdPartiesAndTheEntries(): void
    {
        [$client, $dossier] = $this->school();
        $document = $this->deposit($client, $dossier);
        $sheet = $this->url($dossier, '/documents/'.$document->getId());

        foreach (['FA-2601', 'fa-2601-0012', 'scierie', '4158,12'] as $typed) {
            $crawler = $client->request('GET', $this->url($dossier, '/search'), ['q' => $typed]);
            $option = $crawler->filter('turbo-frame#px-cmdk-results [role="option"][href="'.$sheet.'"]');
            self::assertCount(1, $option, $typed);
            self::assertSame('Pièces', trim($option->ancestors()->filter('[role="group"]')->first()->filter('.px-picker__group')->text()), $typed);
        }
        $groups = $client->request('GET', $this->url($dossier, '/search'), ['q' => 'FA-2601'])->filter('.px-picker__group')->each(static fn ($group): string => trim($group->text()));
        self::assertSame(['Pièces', 'Écritures'], $groups);
        $groups = $client->request('GET', $this->url($dossier, '/search'), ['q' => 'scierie'])->filter('.px-picker__group')->each(static fn ($group): string => trim($group->text()));
        self::assertSame(['Tiers', 'Pièces'], $groups);

        $screens = $client->request('GET', $this->url($dossier, '/search'), ['q' => 'réception'])->filter('[role="option"][href="'.$this->url($dossier, '/documents').'"]');
        self::assertCount(1, $screens);
        $client->getContainer()->get(DossierFiles::class)->deleteAll((int) $dossier->getId());
    }

    /** A pièce deposited and read: the Scierie's invoice FA-2601-0012, 4 158,12 TTC. */
    private function deposit(KernelBrowser $client, Dossier $dossier): Document
    {
        $crawler = $client->request('GET', $this->url($dossier, '/documents/new'));
        $client->request('POST', $this->url($dossier, '/documents/new'), ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'type' => 'purchase_invoice'], ['file' => $this->file(self::PDF, 'scan.pdf')]);
        self::assertResponseStatusCodeSame(303);
        $sheet = (string) $client->getResponse()->headers->get('Location');
        $crawler = $client->request('GET', $sheet);
        $scierie = (int) $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): ?int => $client->getContainer()->get(ThirdPartyRepository::class)->findOneByCode($dossier, 'F0001')?->getId());
        $client->request('POST', $sheet, ['_token' => (string) $crawler->filter('#document-reading input[name="_token"]')->attr('value'), 'type' => 'purchase_invoice', 'thirdParty' => (string) $scierie, 'number' => 'FA-2601-0012', 'totalIncludingTax' => '415812']);
        self::assertResponseStatusCodeSame(303);

        return $this->documents($client, $dossier)[0];
    }

    private function file(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'piece');
        \assert(\is_string($path));
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return list<Document> */
    private function documents(KernelBrowser $client, Dossier $dossier): array
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();

        return $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): array => $client->getContainer()->get(DocumentRepository::class)->findByDossier($dossier));
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
