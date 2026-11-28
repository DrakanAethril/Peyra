<?php

declare(strict_types=1);

namespace App\Tests\Controller\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\DossierContext;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The fiche of a commercial document through its screens (step 2.3): ines.morel types a quote for
 * Girard (C0019) in her Menuiserie Vasseur (date 15/03/2026) - a line refused, then corrected -,
 * validates it (DE-2026-0001), turns it into an order, sends the quote; a validated document's
 * form is gone and its POST answers 404.
 */
final class SalesDocumentScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testAQuoteFromItsDraftToItsOrder(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/sales/quote/new'));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#sales-form')->form();
        $values = $form->getPhpValues();
        $values['customer'] = 'C0019';
        $values['lines'] = [['article' => '', 'designation' => 'Escalier chêne', 'unit' => 'pièce', 'quantity' => '0', 'unitPrice' => '7 950,00', 'discount' => '', 'rate' => '20']];
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Ligne 1 : Une quantité au-dessus de zéro');

        $values['lines'][0]['quantity'] = '1';
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', "9\u{202F}540,00");

        $client->submit($crawler->filter('form.px-confirmbar')->form());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Devis DE-2026-0001');
        self::assertSelectorNotExists('#sales-form');
        $fiche = $client->getRequest()->getUri();

        $client->request('POST', $fiche, ['_token' => 'x', 'customer' => 'C0019']);
        self::assertResponseStatusCodeSame(404);

        $crawler = $client->request('GET', $fiche);
        $client->submit($crawler->filter('form[action$="/send"]')->form());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Envoi à Girard, Jean et Anne');

        $client->submit($crawler->selectButton('Transformer en commande')->form());
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Commande (brouillon)');
        self::assertSelectorTextContains('main', 'Brouillon ouvert depuis DE-2026-0001');
    }

    /**
     * Step 2.3b, under « Proposer » (no mission yet): an invoice with a cash discount of 2 % and its
     * VAT on receipt - 10 × 100,00, escompte 20,00, VAT 196,00, TTC 1 176,00 - is validated, its
     * fiche proposes the entry, « Passer l'écriture au brouillard » writes it; an order's deposit of
     * 25 % is drafted from its fiche.
     */
    public function testAnInvoiceIsProposedThenPassedToTheBrouillard(): void
    {
        [$client, $dossier] = $this->school();

        $crawler = $client->request('GET', $this->url($dossier, '/sales/invoice/new'));
        $form = $crawler->filter('#sales-form')->form();
        $values = $form->getPhpValues();
        $values['customer'] = 'C0019';
        $values['cashDiscount'] = '2';
        $values['vatOnReceipts'] = '1';
        $values['lines'] = [['article' => '', 'designation' => 'Pose', 'unit' => 'heure', 'quantity' => '10', 'unitPrice' => '100,00', 'discount' => '', 'rate' => '20']];
        $client->request('POST', $form->getUri(), $values);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('main', 'Net financier');
        self::assertSelectorTextContains('main', "1\u{202F}176,00");

        $client->submit($crawler->filter('form.px-confirmbar')->form());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Facture FV-2026-0001');
        self::assertSelectorTextContains('#sales-proposal-title', 'Écriture proposée');
        self::assertSelectorTextContains('main', '665000');
        self::assertSelectorTextContains('main', '445800');

        $client->submit($crawler->selectButton('Passer l’écriture au brouillard')->form());
        $client->followRedirect();
        self::assertSelectorNotExists('#sales-proposal-title');
        self::assertSelectorTextContains('#piece-written-title', 'Écriture');

        $crawler = $client->request('GET', $this->url($dossier, '/sales/order/new'));
        $form = $crawler->filter('#sales-form')->form();
        $values = $form->getPhpValues();
        $values['customer'] = 'C0019';
        $values['lines'] = [['article' => '', 'designation' => 'Fenêtres', 'unit' => 'pièce', 'quantity' => '4', 'unitPrice' => '500,00', 'discount' => '', 'rate' => '20']];
        $client->request('POST', $form->getUri(), $values);
        $crawler = $client->followRedirect();
        $client->submit($crawler->filter('form.px-confirmbar')->form());
        $crawler = $client->followRedirect();
        $form = $crawler->selectButton('% : facturer un acompte')->form();
        $form['percent'] = '25';
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Facture d’acompte (brouillon)');
        self::assertSelectorExists('input[value="Acompte de 25 % sur commande BC-2026-0001"]');
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
        $client->getContainer()->get(DossierContext::class)->leave();

        return [$client, $dossier];
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
