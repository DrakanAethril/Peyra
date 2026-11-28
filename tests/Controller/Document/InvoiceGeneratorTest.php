<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Entity\Audit\AuditEvent;
use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Document\DocumentState;
use App\Enum\Document\DocumentType;
use App\Enum\Dossier\DossierKind;
use App\Repository\Document\DocumentRepository;
use App\Service\Dossier\DossierContext;
use App\Service\Shared\Storage\DossierFiles;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pièces › Générateur (step 2.1b) through HTTP, on prof.durand's own template (the Scierie, F0001,
 * thirty days net): the form, the preview's PDF, a refusal named under its field, the generation
 * into the template's inbox with what the invoice prints as read data - its faults included -,
 * a « doublon » arriving twice, and a template or a dossier not his answering 404.
 */
final class InvoiceGeneratorTest extends WebTestCase
{
    use BuildsLedger;
    use SignsIn;

    public function testTheInvoiceIsPreviewedThenGeneratedIntoTheTemplateInbox(): void
    {
        [$client, $template] = $this->template();

        $client->request('GET', '/teacher/documents/generator');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.px-rail a[href="/teacher/documents/generator"][aria-current="page"]');
        $client->request('GET', '/teacher/documents/generator?dossier='.$template->getId());
        self::assertResponseRedirects('/teacher/documents/generator/'.$template->getId());

        $crawler = $client->request('GET', $this->url($template));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-empty__title', 'Aperçu de la facture');
        self::assertSame('2026-03-15', $crawler->filter('input[name="date"]')->attr('value'), 'Dated on the dossier’s date.');
        self::assertSelectorExists('#supplier option[value="F0001"]');
        self::assertSelectorNotExists('#supplier option[value="C0019"]', 'Suppliers only.');

        // A refusal is named under its field, and no preview is drawn.
        $client->request('GET', $this->url($template).'?'.http_build_query(['supplier' => 'F0001', 'date' => '2026-03-12']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#number_error', 'Indiquez le numéro de la facture.');
        self::assertSelectorTextContains('.px-card .px-alert', 'Décrivez au moins une ligne de la facture.');
        self::assertSelectorNotExists('iframe.px-viewer__frame');

        // The preview: its address is the form's, its PDF Gotenberg's.
        $crawler = $client->request('GET', $this->url($template).'?'.http_build_query($this->scierie() + ['faults' => ['vat_miscalculated']]));
        self::assertResponseIsSuccessful();
        $preview = (string) $crawler->filter('iframe.px-viewer__frame')->attr('src');
        self::assertStringStartsWith($this->url($template).'/preview.pdf?', $preview);
        $client->request('GET', $preview);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $client->getInternalResponse()->getContent());
        self::assertSame([], $this->documents($client, $template), 'A preview deposits nothing.');

        // « Générer »: a POST whose token is the button's value.
        $token = (string) $crawler->filter('button[name="_token"]')->attr('value');
        $client->request('POST', $this->url($template), ['_token' => 'forged'] + $this->scierie());
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $this->url($template), ['_token' => $token] + $this->scierie() + ['faults' => ['vat_miscalculated']]);
        self::assertResponseStatusCodeSame(303);
        [$document] = $this->documents($client, $template);
        self::assertSame('/d/'.$template->getId().'/documents/'.$document->getId().'/prepare', $client->getResponse()->headers->get('Location'));
        self::assertSame(DocumentType::PurchaseInvoice, $document->getType());
        self::assertSame(DocumentState::ToProcess, $document->getState());
        self::assertSame('2026-03-15', $document->getReceivedOn()->format('Y-m-d'));
        self::assertSame('FA-2603-0112.pdf', $document->getFileName());
        self::assertSame('Scierie du Haut-Forez', $document->getThirdParty()?->getName());
        self::assertSame('FA-2603-0112', $document->getNumber());
        self::assertSame('2026-03-12', $document->getDocumentDate()?->format('Y-m-d'));
        self::assertSame('2026-04-11', $document->getDueDate()?->format('Y-m-d'), 'Thirty days net, the supplier’s terms.');
        self::assertSame('CF-0098', $document->getOrderReference());
        // 2 760,00 − 138,00 + 85,00 = 2 707,00; VAT 541,40 printed 541,04; TTC 3 248,04.
        self::assertSame([['rate' => '20', 'base' => 270700, 'vat' => 54104]], array_map(static fn ($line): array => $line->toArray(), $document->getVatLines()));
        self::assertSame([270700, 54104, 324804], [$document->getTotalExcludingTax()?->cents, $document->getTotalVat()?->cents, $document->getTotalIncludingTax()?->cents]);
        self::assertSame(['vat_miscalculated'], $document->getGeneration()['faults'] ?? null);
        self::assertStringStartsWith('%PDF', $client->getContainer()->get(DossierFiles::class)->read((int) $template->getId(), $document->getFileKey()));
        self::assertSame(1, $this->trailCount($client, $template));

        $client->request('GET', '/d/'.$template->getId().'/documents/'.$document->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-card small', 'Lecture automatique : à contrôler.');
    }

    public function testADuplicateArrivesTwiceAndAMissingNumberIsReadAsNone(): void
    {
        [$client, $template] = $this->template();
        $crawler = $client->request('GET', $this->url($template));
        $token = (string) $crawler->filter('button[name="_token"]')->attr('value');

        $client->request('POST', $this->url($template), ['_token' => $token, 'dueDate' => '2026-04-30', 'missingMention' => 'number', 'faults' => ['duplicate', 'wrong_recipient']] + $this->scierie());
        self::assertResponseStatusCodeSame(303);
        $documents = $this->documents($client, $template);
        self::assertCount(2, $documents);
        foreach ($documents as $document) {
            self::assertNull($document->getNumber());
            self::assertSame('2026-04-30', $document->getDueDate()?->format('Y-m-d'));
            self::assertSame(324840, $document->getTotalIncludingTax()?->cents);
            self::assertSame('Facture Scierie du Haut-Forez.pdf', $document->getFileName());
        }
        self::assertNotSame($documents[0]->getFileKey(), $documents[1]->getFileKey(), 'Each arrival has its own file.');
        self::assertEqualsCanonicalizing([1, 2], array_map(static fn (Document $d): mixed => $d->getGeneration()['copy'] ?? null, $documents));

        // A refused generation writes nothing.
        $client->request('POST', $this->url($template), ['_token' => $token, 'supplier' => 'F0001']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(2, $this->documents($client, $template));
    }

    public function testATemplateNotHisOrAStudentDossierIsNotThere(): void
    {
        [$client, $template] = $this->template();
        $common = $this->savedDossier(DossierKind::Template, null);
        $student = $this->savedDossier(DossierKind::Student, $this->account($client, 'ines.morel'));
        foreach ([$common, $student] as $dossier) {
            $client->request('GET', $this->url($dossier));
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', $this->url($dossier).'/preview.pdf?'.http_build_query($this->scierie()));
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('GET', '/teacher/documents/generator?dossier='.$common->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#template option[value="'.$common->getId().'"]', 'Only the templates he may change.');
        self::assertSelectorExists('#template option[value="'.$template->getId().'"]');
    }

    /** @return array<string, string> FA-2603-0112 of the Scierie: 2,40 m³ at 1 150,00, a discount of 138,00, carriage 85,00. */
    private function scierie(): array
    {
        return [
            'supplier' => 'F0001',
            'number' => 'FA-2603-0112',
            'date' => '2026-03-12',
            'orderReference' => 'CF-0098',
            'line_designation_0' => 'Chêne avivé 27 mm, m³',
            'line_quantity_0' => '2,40',
            'line_price_0' => '115000',
            'line_rate_0' => '20',
            'line_designation_1' => 'Remise 5 %',
            'line_quantity_1' => '1',
            'line_price_1' => '-13800',
            'line_rate_1' => '20',
            'line_designation_2' => 'Port',
            'line_quantity_2' => '1',
            'line_price_2' => '8500',
            'line_rate_2' => '20',
        ];
    }

    /** @return array{KernelBrowser, Dossier} prof.durand signed in, his template with its plan, journals and the Scierie */
    private function template(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'ines.morel');
        $this->signOut($client);
        $this->submitLogin($client, 'prof.durand');
        $durand = $this->account($client, 'prof.durand');
        \assert($durand instanceof User);
        $template = $this->savedDossier(DossierKind::Template, $durand);
        $this->installPlan($template);
        $this->prepareLedger($template);
        $client->getContainer()->get(DossierContext::class)->leave();

        return [$client, $template];
    }

    private function url(Dossier $dossier): string
    {
        return '/teacher/documents/generator/'.$dossier->getId();
    }

    /** @return list<Document> */
    private function documents(KernelBrowser $client, Dossier $dossier): array
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();

        return $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): array => $client->getContainer()->get(DocumentRepository::class)->findByDossier($dossier));
    }

    private function trailCount(KernelBrowser $client, Dossier $dossier): int
    {
        return $client->getContainer()->get(EntityManagerInterface::class)->getRepository(AuditEvent::class)->count(['action' => AuditAction::DocumentGenerated, 'subjectId' => (string) $dossier->getId()]);
    }
}
