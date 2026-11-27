<?php

declare(strict_types=1);

namespace App\Tests\Controller\Regulatory;

use App\Entity\Audit\AuditEvent;
use App\Entity\Regulatory\RegulatoryValue;
use App\Enum\Audit\AuditAction;
use App\Enum\Regulatory\RegulatoryOrigin;
use App\Repository\Regulatory\RegulatoryValueRepository;
use App\Tests\Support\RegulatoryFixtures;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Paramètres › Paramètres réglementaires (design-ux.md § 3.3): every teacher reads the millésimes,
 * an administrator adds a dated value - never changes one -, a student meets a page that is not
 * there.
 */
final class RegulatoryValuesTest extends WebTestCase
{
    use SignsIn;

    public function testAMillesimeListsItsValuesFilteredByDomain(): void
    {
        $client = $this->signedIn('prof.durand');
        $this->seed($client);

        $crawler = $client->request('GET', '/teacher/regulatory?year=2026');
        self::assertResponseIsSuccessful();
        self::assertSame(['Millésime 2026', 'Millésime 2025'], $crawler->filter('nav[aria-label="Millésimes"] a')->each(static fn ($a): string => trim($a->text())));
        $rows = $crawler->filter('tbody tr');
        self::assertCount(3, $rows, 'The SMIC of January and of June, and the VAT rates.');
        self::assertStringContainsString('Taux de TVA', $rows->eq(0)->text());
        self::assertStringContainsString('4 valeurs', $rows->eq(0)->text());
        self::assertStringContainsString('À vérifier', $rows->eq(0)->text());
        self::assertStringContainsString("12,02\u{00A0}€/heure", $rows->eq(1)->text());
        self::assertStringContainsString('du 01/01/2026 au 31/05/2026', preg_replace('/\s+/', ' ', $rows->eq(1)->text()) ?? '');
        self::assertStringContainsString('depuis le 01/06/2026', $rows->eq(2)->text());
        self::assertStringContainsString('Vérifiée', $rows->eq(2)->text());
        self::assertSelectorTextContains('.px-statusbar', 'À vérifier · 1');

        $crawler = $client->request('GET', '/teacher/regulatory?year=2026&domain=fiscal');
        self::assertCount(1, $crawler->filter('tbody tr'));
        $crawler = $client->request('GET', '/teacher/regulatory?year=2026&q=smic');
        self::assertCount(2, $crawler->filter('tbody tr'));
        $crawler = $client->request('GET', '/teacher/regulatory?year=2025&domain=');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString("11,88\u{00A0}€/heure", $crawler->filter('tbody')->text());
        $client->request('GET', '/teacher/regulatory?year=abc&domain=nope&page=');
        self::assertResponseIsSuccessful();
    }

    public function testWithoutValuesThePageSaysWhereTheyComeFrom(): void
    {
        $client = $this->signedIn('prof.durand');
        $client->request('GET', '/teacher/regulatory');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-empty__title', 'Aucune valeur réglementaire installée');
    }

    public function testTheSheetShowsEveryDateAndOffersANewValueToAdministratorsOnly(): void
    {
        $client = $this->signedIn('prof.durand');
        $this->seed($client);

        $crawler = $client->request('GET', '/teacher/regulatory/social.smic_hourly');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'SMIC horaire brut');
        self::assertCount(3, $crawler->filter('tbody tr'));
        self::assertSelectorNotExists('a[href$="/new"]');
        $client->request('GET', '/teacher/regulatory/social.smic_hourly/new');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/teacher/regulatory/social.absent');
        self::assertResponseStatusCodeSame(404);

        $crawler = $client->request('GET', '/teacher/regulatory/fiscal.vat.rates');
        self::assertSame(['normal', 'intermediate', 'reduced', 'super_reduced'], $crawler->filter('.px-split dl dt')->reduce(static fn ($dt): bool => 'px-u-code' === $dt->attr('class'))->each(static fn ($dt): string => $dt->text()));

        $this->signOut($client);
        $this->submitLogin($client, 'prof.martin');
        $crawler = $client->request('GET', '/teacher/regulatory/social.smic_hourly');
        self::assertCount(1, $crawler->filter('a[href="/teacher/regulatory/social.smic_hourly/new"]'));
    }

    public function testAnAdministratorAddsADatedValueThenCorrectsIt(): void
    {
        $client = $this->signedIn('prof.martin');
        $this->seed($client);

        $crawler = $client->request('GET', '/teacher/regulatory/social.smic_hourly/new');
        self::assertResponseIsSuccessful();
        self::assertSame("12,31", $crawler->filter('#leaf_0')->attr('value'), 'The form starts from the latest value.');
        $client->submit($crawler->filter('#regulatory-form')->form(), [
            'effective_from' => '2027-01-01',
            'leaf' => ['0' => '12,55'],
            'source_url' => 'https://www.urssaf.fr/accueil/outils-documentation/taux-baremes/montant-smic.html',
            'consulted_on' => '2027-01-02',
            'verified' => '1',
            'note' => 'Revalorisation du 1er janvier 2027.',
        ]);
        self::assertResponseRedirects('/teacher/regulatory/social.smic_hourly');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Valeur du 01/01/2027 enregistrée.');
        self::assertCount(4, $crawler->filter('table')->first()->filter('tbody tr'));

        $crawler = $client->request('GET', '/teacher/regulatory/social.smic_hourly/new');
        $client->submit($crawler->filter('#regulatory-form')->form(), ['effective_from' => '2027-01-01', 'leaf' => ['0' => '12,56']]);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertContains('Valeurs corrigées', $crawler->filter('h2')->each(static fn ($h): string => trim($h->text())), 'The corrected value stays in the history.');

        $rows = array_values(array_filter($this->em($client)->getRepository(RegulatoryValue::class)->findAll(), static fn (RegulatoryValue $v): bool => '2027-01-01' === $v->getEffectiveFrom()->format('Y-m-d')));
        self::assertCount(2, $rows);
        self::assertSame(['12.55', '12.56'], array_map(static fn (RegulatoryValue $v): mixed => $v->getTree(), $rows));
        self::assertSame(RegulatoryOrigin::Administrator, $rows[1]->getOrigin());
        self::assertSame('Jean-Pierre Martin', $rows[1]->getRecordedBy()?->getDisplayName());
        self::assertSame(2027, $rows[1]->getMillesime());

        $events = $this->em($client)->getRepository(AuditEvent::class)->findBy(['subjectId' => 'social.smic_hourly'], ['id' => 'ASC']);
        self::assertSame(["SMIC horaire brut : 12,55\u{00A0}€/heure au 01/01/2027", "SMIC horaire brut : 12,56\u{00A0}€/heure au 01/01/2027 (correction)"], array_map(static fn (AuditEvent $e): string => $e->getSummary(), $events));
        self::assertSame(AuditAction::RegulatoryValueAdded, $events[0]->getAction());
    }

    public function testAStructureKeepsItsShape(): void
    {
        $client = $this->signedIn('prof.martin');
        $this->seed($client);

        $crawler = $client->request('GET', '/teacher/regulatory/fiscal.vat.rates/new');
        self::assertCount(4, $crawler->filter('input[name^="leaf["]'));
        $client->submit($crawler->filter('#regulatory-form')->form(), ['effective_from' => '2027-01-01', 'leaf' => ['0' => '21', '1' => '10', '2' => '5,5', '3' => '2,1']]);
        self::assertResponseRedirects();

        $value = self::getContainer()->get(RegulatoryValueRepository::class)->findForKey('fiscal.vat.rates');
        self::assertSame(['normal' => '21', 'intermediate' => '10', 'reduced' => '5.5', 'super_reduced' => '2.1'], end($value) ? end($value)->getTree() : null);
    }

    public function testARefusedFormComesBackAsItWasSent(): void
    {
        $client = $this->signedIn('prof.martin');
        $this->seed($client);

        $crawler = $client->request('GET', '/teacher/regulatory/social.smic_hourly/new');
        $client->submit($crawler->filter('#regulatory-form')->form(), ['effective_from' => '', 'leaf' => ['0' => 'douze'], 'source_url' => 'urssaf.fr', 'consulted_on' => '2027-02-31', 'verified' => '1']);
        self::assertResponseStatusCodeSame(422);
        $errors = $client->getCrawler()->filter('.px-field__error')->each(static fn ($p): string => trim($p->text()));
        self::assertContains('Saisissez la date d’effet.', $errors);
        self::assertContains('Ce nombre n’est pas lisible. Écrivez par exemple 6,90.', $errors);
        self::assertContains('Saisissez une adresse qui commence par https://.', $errors);
        self::assertContains('Cette date n’existe pas. Écrivez-la JJ/MM/AAAA.', $errors);
        self::assertContains('Une valeur vérifiée dit où et quand elle a été lue : renseignez la source et la date de consultation.', $errors);
        self::assertSame('douze', $client->getCrawler()->filter('#leaf_0')->attr('value'));

        $crawler = $client->request('GET', '/teacher/regulatory/social.smic_hourly/new');
        $client->submit($crawler->filter('#regulatory-form')->form(), ['effective_from' => '2026-06-01', 'leaf' => ['0' => '12,31'], 'source_url' => 'https://www.urssaf.fr/accueil/outils-documentation/taux-baremes.html', 'consulted_on' => '2026-11-27', 'verified' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-alert', 'Rien ne change : c’est déjà la valeur du 01/06/2026.');
        self::assertSame(0, $this->em($client)->getRepository(AuditEvent::class)->count([]));
    }

    public function testAForgedPostIsRefused(): void
    {
        $client = $this->signedIn('prof.martin');
        $this->seed($client);

        $client->request('POST', '/teacher/regulatory/social.smic_hourly/new', ['_token' => 'forged', 'effective_from' => '2027-01-01', 'leaf' => ['0' => '13']]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAStudentFindsNothing(): void
    {
        $client = $this->signedIn('ines.morel');
        $this->seed($client);

        foreach (['/teacher/regulatory', '/teacher/regulatory/social.smic_hourly', '/teacher/regulatory/social.smic_hourly/new'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    private function signedIn(string $login): KernelBrowser
    {
        $client = static::createClient();
        $this->submitLogin($client, $login);
        self::assertResponseRedirects();

        return $client;
    }

    private function seed(KernelBrowser $client): void
    {
        $em = $this->em($client);
        foreach ([
            RegulatoryFixtures::fromFile('social.smic_hourly', '2024-11-01', '11.88', 2025, unit: 'EUR/heure', label: 'SMIC horaire brut'),
            RegulatoryFixtures::fromFile('social.smic_hourly', '2026-01-01', '12.02', unit: 'EUR/heure', label: 'SMIC horaire brut'),
            RegulatoryFixtures::fromFile('social.smic_hourly', '2026-06-01', '12.31', unit: 'EUR/heure', label: 'SMIC horaire brut'),
            RegulatoryFixtures::fromFile('fiscal.vat.rates', '2026-01-01', ['normal' => '20', 'intermediate' => '10', 'reduced' => '5.5', 'super_reduced' => '2.1'], unit: 'percent', verified: false, label: 'Taux de TVA'),
        ] as $value) {
            $em->persist($value);
        }
        $em->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
