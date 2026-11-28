<?php

declare(strict_types=1);

namespace App\Tests\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Dossier\CopyKind;
use App\Enum\Dossier\DossierKind;
use App\Repository\Document\DocumentRepository;
use App\Service\Dossier\Copy\DossierCopier;
use App\Service\Dossier\DossierContext;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\DossierFixtures;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « Mode préparation » (step 2.2b) through HTTP, on prof.durand's template: the generator opens the
 * pièce there; a value opens « Glisser une erreur » with its suggestions; a refused error comes back
 * with its reason, a slipped one shows struck through and is taken back; a doublon arrives twice.
 * Then the template is copied to ines.morel: her sheet shows the wrong total and never the right
 * one, and « mode préparation » is not there for her - nor on a pièce of her dossier for anybody.
 */
final class PiecePreparationScreenTest extends WebTestCase
{
    use BuildsLedger;
    use SignsIn;

    public function testTheTeacherSlipsAnErrorAndTakesItBack(): void
    {
        [$client, $template, $document] = $this->generated();
        $url = $this->url($template, $document);

        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-pagehead', 'Erreurs à repérer · 0');
        self::assertSelectorExists('a[href="'.$url.'?slip=totalIncludingTax"]');
        self::assertSelectorExists('a[href="/d/'.$template->getId().'/documents/'.$document->getId().'"]', '« Voir comme l’étudiant ».');

        // The panel of the TTC, 3 248,40: the HT, then the euros' units and tens swapped, then the cents' digits.
        $crawler = $client->request('GET', $url.'?slip=totalIncludingTax');
        self::assertResponseIsSuccessful();
        self::assertSame(['270700', '328440', '324804', ''], $crawler->filter('#slip-form input[name="wrong"]')->each(static fn ($input): string => (string) $input->attr('value')));
        self::assertSame('both', $crawler->filter('#slip-form input[name="place"][checked]')->attr('value'));
        $token = (string) $crawler->filter('#slip-form input[name="_token"]')->attr('value');

        // The right value again: refused, said in the panel.
        $client->request('POST', $url, ['_token' => $token, '_action' => 'slip', 'target' => 'totalIncludingTax', 'place' => 'both', 'wrong' => '', 'free_amount' => '324840']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-slip__panel .px-alert', 'La valeur fausse doit différer de la valeur juste.');

        $client->request('POST', $url, ['_token' => 'forged', '_action' => 'slip', 'target' => 'totalIncludingTax', 'place' => 'both', 'wrong' => '328440']);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $url, ['_token' => $token, '_action' => 'slip', 'target' => 'totalIncludingTax', 'place' => 'both', 'wrong' => '328440']);
        self::assertResponseRedirects($url, 303);
        $client->request('GET', $url);
        self::assertSelectorTextContains('.px-pagehead', 'Erreurs à repérer · 1');
        self::assertSelectorTextContains('.px-slip__errors', 'Total TTC');
        self::assertSame(FrenchFormat::amount(Money::cents(328_440)), trim((string) $client->getCrawler()->filter('.px-slip__errors .px-slip__wrong')->text()));
        self::assertSame(328_440, $this->reload($client, $template, $document)->getTotalIncludingTax()?->cents);

        $client->request('POST', $url, ['_token' => $token, '_action' => 'remove', 'target' => 'totalIncludingTax']);
        self::assertResponseRedirects($url, 303);
        self::assertSame(324_840, $this->reload($client, $template, $document)->getTotalIncludingTax()?->cents);

        // A doublon: the pièce arrives twice.
        $client->request('POST', $url, ['_token' => $token, '_action' => 'duplicate']);
        self::assertResponseRedirects($url, 303);
        self::assertCount(2, $this->documents($client, $template));
        $client->request('POST', $url, ['_token' => $token, '_action' => 'unduplicate']);
        self::assertCount(1, $this->documents($client, $template));
    }

    public function testTheStudentNeverReceivesTheAnswerKey(): void
    {
        [$client, $template, $document] = $this->generated();
        $url = $this->url($template, $document);
        $crawler = $client->request('GET', $url.'?slip=totalIncludingTax');
        $token = (string) $crawler->filter('#slip-form input[name="_token"]')->attr('value');
        $client->request('POST', $url, ['_token' => $token, '_action' => 'slip', 'target' => 'totalIncludingTax', 'place' => 'both', 'wrong' => '328440']);
        self::assertResponseRedirects($url, 303);

        // The template copied as a distribution makes ines.morel's dossier.
        $ines = $this->account($client, 'ines.morel');
        \assert($ines instanceof User);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $theirs = new Dossier(DossierKind::Student, DossierFixtures::company('Menuiserie Vasseur'), new \DateTimeImmutable('2026-03-15'), $ines, null, new \DateTimeImmutable('2026-11-28 09:30'), null);
        $entityManager->persist($theirs);
        $entityManager->flush();
        $client->getContainer()->get(DossierCopier::class)->copy($template, $theirs, CopyKind::Template);
        [$copy] = $this->documents($client, $theirs);
        self::assertNotNull($copy->getAnswerKey(), 'The copy carries the answer key, for the correction.');

        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $client->request('GET', '/d/'.$theirs->getId().'/documents/'.$copy->getId());
        self::assertResponseIsSuccessful();
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('328440', $page, 'The wrong total is what she reads.');
        self::assertStringNotContainsString('324840', $page, 'Never the right one, not even in a hidden field.');
        // (The controls may still say that HT + TVA make 3 248,40: they read the data, not the answer key.)
        self::assertStringNotContainsString('Erreurs à repérer', $page);
        self::assertStringNotContainsString('Mode préparation', $page);
        $client->request('GET', '/d/'.$theirs->getId().'/documents/'.$copy->getId().'/prepare');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);

        // Her teacher neither: « mode préparation » belongs to a template.
        $this->signOut($client);
        $this->submitLogin($client, 'prof.durand');
        $client->request('GET', '/d/'.$theirs->getId().'/documents/'.$copy->getId().'/prepare');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{KernelBrowser, Dossier, Document} prof.durand signed in, his template and the Scierie's FA-2603-0112 generated into it */
    private function generated(): array
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

        $crawler = $client->request('GET', '/teacher/documents/generator/'.$template->getId());
        $token = (string) $crawler->filter('button[name="_token"]')->attr('value');
        $client->request('POST', '/teacher/documents/generator/'.$template->getId(), ['_token' => $token, 'supplier' => 'F0001', 'number' => 'FA-2603-0112', 'date' => '2026-03-12',
            'line_designation_0' => 'Chêne avivé 27 mm, m³', 'line_quantity_0' => '2,40', 'line_price_0' => '115000', 'line_rate_0' => '20',
            'line_designation_1' => 'Remise 5 %', 'line_quantity_1' => '1', 'line_price_1' => '-13800', 'line_rate_1' => '20',
            'line_designation_2' => 'Port', 'line_quantity_2' => '1', 'line_price_2' => '8500', 'line_rate_2' => '20',
        ]);
        [$document] = $this->documents($client, $template);
        self::assertResponseRedirects($this->url($template, $document), 303);

        return [$client, $template, $document];
    }

    private function url(Dossier $dossier, Document $document): string
    {
        return '/d/'.$dossier->getId().'/documents/'.$document->getId().'/prepare';
    }

    private function reload(KernelBrowser $client, Dossier $dossier, Document $document): Document
    {
        foreach ($this->documents($client, $dossier) as $one) {
            if ($one->getId() === $document->getId()) {
                return $one;
            }
        }
        self::fail('The pièce is gone.');
    }

    /**
     * @return list<Document>
     *
     * @phpstan-impure
     */
    private function documents(KernelBrowser $client, Dossier $dossier): array
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();

        return $client->getContainer()->get(DossierContext::class)->within($dossier, static fn (): array => $client->getContainer()->get(DocumentRepository::class)->findByDossier($dossier));
    }
}
