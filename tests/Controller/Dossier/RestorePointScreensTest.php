<?php

declare(strict_types=1);

namespace App\Tests\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Dossier\DossierRestoration;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Repository\Dossier\RestorePointRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Dossier\DossierContext;
use App\Service\Identity\SchoolClasses;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The restore points through HTTP (step 1.8b), in a group's dossier: chloe.fabre (no profile) poses
 * a point - its copy made once the answer has left -, goes back to it through the récapitulatif and
 * deletes one; ines.morel (« Comptable fournisseurs ») poses one but neither goes back nor deletes;
 * while a return runs, every page of the dossier is its waiting page and every post a 409.
 */
final class RestorePointScreensTest extends WebTestCase
{
    use BuildsLedger;
    use BuildsSchool;
    use SignsIn;

    public function testPosingAPointAndGoingBackToIt(): void
    {
        [$client, $dossier] = $this->group();
        $this->as($client, 'chloe.fabre');

        $crawler = $client->request('GET', $this->url($dossier, '/restore-points/new'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#name[autofocus]');
        self::assertSame('Avant validation du 15/03/2026', $crawler->filter('#name')->attr('value'));
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', $this->url($dossier, '/restore-points'), ['_token' => $token, 'name' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-field__error', 'Donnez un nom au point de restauration.');

        $client->request('POST', $this->url($dossier, '/restore-points'), ['_token' => $token, 'name' => 'Avant les règlements']);
        self::assertResponseRedirects($this->url($dossier, '/restore-points'));
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Point « Avant les règlements » posé');
        self::assertSame('Prêt', trim($crawler->filter('#restore-points tbody tr')->first()->filter('td')->eq(4)->text()), 'The copy was made after the answer.');
        $point = $this->points($client, $dossier)[0];
        $dossier = $point->getDossier();

        // The work after the point: a draft more.
        $client->getContainer()->get(Ledger::class)->draft($dossier, new EntryDraft('OD', new \DateTimeImmutable('2026-03-10'), 'OD-1', 'Écriture de trop', [
            EntryLineDraft::debit('607000', Money::euros('10.00')),
            EntryLineDraft::credit('401000', Money::euros('10.00'), '', 'F0001'),
        ]), 'test');
        $client->getContainer()->get(DossierContext::class)->leave();

        $crawler = $client->request('GET', $this->url($dossier, '/restore-points/'.$point->getId().'/return'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="keep"][checked]');
        $drafts = $crawler->filter('.px-restore-summary tbody tr')->eq(1);
        self::assertSame(['Écritures au brouillard (change)', '3', '2'], $drafts->filter('th, td')->each(static fn ($cell): string => trim($cell->text())));
        $token = (string) $crawler->filter('#restore-return input[name="_token"]')->attr('value');

        $client->request('POST', $this->url($dossier, '/restore-points/'.$point->getId().'/return'), ['_token' => $token, 'keep' => '1']);
        self::assertResponseRedirects($this->url($dossier, '/restoring'));
        $client->followRedirect();
        self::assertResponseRedirects($this->url($dossier));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Le dossier est revenu au point « Avant les règlements ».');

        $crawler = $client->request('GET', $this->url($dossier, '/restore-points'));
        self::assertCount(2, $crawler->filter('#restore-points tbody tr'), 'The state left is a point of its own.');
        self::assertStringContainsString('Avant le retour à « Avant les règlements »', $crawler->filter('#restore-points tbody tr')->first()->text());

        // Deleting the point gone back to.
        $row = $crawler->filter('#restore-points tbody tr')->eq(1);
        $client->request('POST', $this->url($dossier, '/restore-points/'.$point->getId().'/delete'), ['_token' => (string) $row->filter('input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects($this->url($dossier, '/restore-points'));
        self::assertCount(1, $this->points($client, $dossier));
    }

    public function testTheSupplierAccountantPosesButNeitherGoesBackNorDeletes(): void
    {
        [$client, $dossier] = $this->group();
        $this->as($client, 'ines.morel');
        $crawler = $client->request('GET', $this->url($dossier, '/restore-points'));
        $client->request('POST', $this->url($dossier, '/restore-points'), ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'name' => 'Le mien']);
        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter('#restore-points a[href$="/return"]'));
        self::assertCount(0, $crawler->filter('#restore-points form'));
        $point = $this->points($client, $dossier)[0];

        $client->request('GET', $this->url($dossier, '/restore-points/'.$point->getId().'/return'));
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $this->url($dossier, '/restore-points/'.$point->getId().'/delete'), ['_token' => 'any']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWhileAReturnRunsTheDossierWaits(): void
    {
        [$client, $dossier] = $this->group();
        $this->as($client, 'chloe.fabre');
        $crawler = $client->request('GET', $this->url($dossier, '/restore-points'));
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', $this->url($dossier, '/restore-points'), ['_token' => $token, 'name' => 'Point']);
        $point = $this->points($client, $dossier)[0];
        $chloe = $this->account($client, 'chloe.fabre');
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new DossierRestoration($point, null, $chloe, new \DateTimeImmutable('2026-11-28 10:00')));
        $entityManager->flush();

        $client->request('GET', $this->url($dossier, '/accounting/entry'));
        self::assertResponseRedirects($this->url($dossier, '/restoring'));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-restoring__title', 'Retour au point « Point »');
        $client->request('GET', $this->url($dossier, '/restoring/state'));
        self::assertSame('{"running":true}', $client->getResponse()->getContent());
        $client->request('POST', $this->url($dossier, '/restore-points'), ['_token' => $token, 'name' => 'Encore']);
        self::assertResponseStatusCodeSame(409);
        $client->xmlHttpRequest('GET', $this->url($dossier, '/search?q=ach'));
        self::assertResponseStatusCodeSame(409);
    }

    /** @return list<RestorePoint> */
    private function points(KernelBrowser $client, Dossier $dossier): array
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        $fresh = $client->getContainer()->get(EntityManagerInterface::class)->find(Dossier::class, $dossier->getId());
        \assert($fresh instanceof Dossier);

        return $client->getContainer()->get(RestorePointRepository::class)->findByDossier($fresh);
    }

    /** @return array{KernelBrowser, Dossier} */
    private function group(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $client->getContainer()->get(SchoolClasses::class)->addTeacher($cg1, $martin, $martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $profiles = ['ines.morel' => DossierProfile::SupplierAccountant, 'chloe.fabre' => null];
        foreach (array_keys($profiles) as $login) {
            $this->signOut($client);
            $this->submitLogin($client, $login);
        }
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($year instanceof SchoolYear);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $dossier = $this->savedDossier(DossierKind::Group, null, $year);
        foreach ($profiles as $login => $profile) {
            $student = $this->account($client, $login);
            \assert($student instanceof User);
            $member = new DossierMember($dossier, $student, new \DateTimeImmutable('2026-11-27 10:00'));
            $member->assignProfile($profile);
            $entityManager->persist($member);
        }
        $entityManager->flush();
        $this->installPlan($dossier);
        $this->prepareLedger($dossier);
        $ledger = $client->getContainer()->get(Ledger::class);
        foreach (['FA-0001', 'FA-0002'] as $document) {
            $ledger->draft($dossier, new EntryDraft('ACH', new \DateTimeImmutable('2026-03-02'), $document, 'Facture Scierie', [
                EntryLineDraft::debit('607000', Money::euros('1000.00')),
                EntryLineDraft::credit('401000', Money::euros('1000.00'), '', 'F0001'),
            ]), 'test');
        }
        $client->getContainer()->get(DossierContext::class)->leave();

        return [$client, $dossier];
    }

    private function as(KernelBrowser $client, string $login): void
    {
        $this->signOut($client);
        $this->submitLogin($client, $login);
    }

    private function url(Dossier $dossier, string $path = ''): string
    {
        return '/d/'.$dossier->getId().$path;
    }
}
