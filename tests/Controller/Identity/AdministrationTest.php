<?php

declare(strict_types=1);

namespace App\Tests\Controller\Identity;

use App\Entity\Identity\AdministrationEvent;
use App\Enum\Identity\AdministrationAction;
use App\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Paramètres › Administration (design-ux.md § 3.6): « Enseignants de la filière », « Administrateurs »
 * and the two journals, as prof.martin (administrator of the bootstrap list).
 */
final class AdministrationTest extends WebTestCase
{
    use SignsIn;

    public function testTheFieldTeachersListSaysWhyEachOneEnters(): void
    {
        $client = $this->asMartin();
        $this->signInElsewhere($client, 'prof.durand');
        $crawler = $client->request('GET', '/teacher/admin/teachers');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('Jean-Pierre Martin', $rows->eq(0)->text());
        self::assertStringContainsString('Liste d’amorçage', $rows->eq(0)->text());
        self::assertStringContainsString('Groupe de l’annuaire', $rows->eq(1)->text());
        self::assertStringContainsString('cg-1', $rows->eq(1)->text());
        self::assertCount(0, $crawler->filter('tbody form'), 'Only a named authorisation can be removed here.');
    }

    public function testAddingATeacherChecksTheDirectory(): void
    {
        $client = $this->asMartin();

        $this->add($client, 'inconnu.total');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-field__error', 'Identifiant inconnu de l’annuaire.');

        $this->add($client, 'enzo.petit');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-field__error', 'Enzo Petit n’est pas enseignant dans l’annuaire.');

        $this->add($client, 'prof.leroy');
        self::assertResponseRedirects('/teacher/admin/teachers');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '« prof.leroy » fait désormais partie des enseignants de la filière.');
        $row = $crawler->filter('tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), 'Marc Leroy'));
        self::assertStringContainsString('Autorisé nommément', $row->text());
        self::assertStringContainsString('par Jean-Pierre Martin', $row->text());

        $this->add($client, 'prof.leroy');
        self::assertSelectorTextContains('.px-field__error', 'Marc Leroy figure déjà dans la liste.');
    }

    public function testRemovingATeacherIsJournaled(): void
    {
        $client = $this->asMartin();
        $this->add($client, 'prof.leroy');
        $crawler = $client->followRedirect();
        $form = $crawler->filter('tbody form')->form();
        $client->submit($form);

        self::assertResponseRedirects('/teacher/admin/teachers');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Marc Leroy ne fait plus partie des enseignants de la filière.');
        self::assertStringNotContainsString('Marc Leroy', $crawler->filter('tbody')->text());

        $crawler = $client->request('GET', '/teacher/admin/journal');
        $lines = $crawler->filter('tbody tr');
        self::assertCount(2, $lines);
        self::assertStringContainsString('Enseignant retiré de la filière', $lines->eq(0)->text());
        self::assertStringContainsString('Enseignant ajouté à la filière', $lines->eq(1)->text());
        self::assertStringContainsString('Marc Leroy', $lines->eq(1)->text());
    }

    public function testDesignatingAndRemovingAnAdministrator(): void
    {
        $client = $this->asMartin();
        $this->signInElsewhere($client, 'prof.durand');

        $client->request('GET', '/teacher/admin/administrators/candidates?q=dur');
        self::assertResponseIsSuccessful();
        $candidates = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($candidates);
        self::assertCount(1, $candidates);
        self::assertIsArray($candidates[0]);
        self::assertSame(['name' => 'Sophie Durand', 'login' => 'prof.durand'], ['name' => $candidates[0]['name'], 'login' => $candidates[0]['login']]);
        $client->request('GET', '/teacher/admin/administrators/candidates?q=mar');
        self::assertSame('[]', $client->getResponse()->getContent(), 'An administrator is no candidate.');

        $crawler = $client->request('GET', '/teacher/admin/administrators');
        $id = $candidates[0]['id'];
        self::assertIsInt($id);
        $form = $crawler->filter('form[action$="/designate"]')->form(['user' => (string) $id]);
        $client->submit($form);
        self::assertResponseRedirects('/teacher/admin/administrators');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Sophie Durand administre désormais Peyra.');
        $durand = $crawler->filter('tbody tr')->eq(1);
        self::assertStringContainsString('Sophie Durand', $durand->text());
        self::assertStringContainsString('Jean-Pierre Martin', $durand->text());

        // prof.martin, of the bootstrap list, cannot be removed here; prof.durand can.
        self::assertStringContainsString('Se retire dans la configuration du serveur', $crawler->filter('tbody tr')->eq(0)->text());
        $client->submit($durand->filter('form')->form());
        self::assertResponseRedirects('/teacher/admin/administrators');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Sophie Durand n’administre plus Peyra.');
        self::assertCount(1, $crawler->filter('tbody tr'));

        $actions = array_map(static fn (AdministrationEvent $event): AdministrationAction => $event->getAction(), $client->getContainer()->get('doctrine')->getRepository(AdministrationEvent::class)->findBy([], ['id' => 'ASC']));
        self::assertSame([AdministrationAction::AdministratorDesignated, AdministrationAction::AdministratorRevoked], $actions);
    }

    public function testDesignatingNobodyIsRefusedOnTheField(): void
    {
        $client = $this->asMartin();
        $crawler = $client->request('GET', '/teacher/admin/administrators');
        $client->submit($crawler->filter('form[action$="/designate"]')->form(['user_label' => 'Quelqu’un']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-field__error', 'Choisissez un enseignant dans la liste.');
        self::assertSelectorExists('input#designate[value="Quelqu’un"]');
    }

    public function testAForgedRevocationOfTheBootstrapAdministratorIsRefused(): void
    {
        $client = $this->asMartin();
        $martin = $this->account($client, 'prof.martin');
        self::assertNotNull($martin);
        $crawler = $client->request('GET', '/teacher/admin/administrators');
        self::assertCount(0, $crawler->filter('tbody form'), 'No « Retirer » for the bootstrap list.');

        $client->request('POST', '/teacher/admin/administrators/'.$martin->getId().'/revoke', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($this->account($client, 'prof.martin')?->isAdministrator());
    }

    public function testTheLoginJournalFiltersRefusals(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, 'enzo.petit');
        $this->submitLogin($client, 'prof.martin');

        $crawler = $client->request('GET', '/teacher/admin/logins?outcome=refused');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Refusée : hors BTS CG', $crawler->filter('tbody tr')->text());
        self::assertSelectorTextContains('.px-filters__chip[aria-current="true"]', 'Refusées');

        $crawler = $client->request('GET', '/teacher/admin/logins?outcome=&q=martin&page=');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Réussie', $crawler->filter('tbody tr')->text());
    }

    private function asMartin(): KernelBrowser
    {
        $client = static::createClient();
        $this->submitLogin($client, 'prof.martin');
        self::assertResponseRedirects('/');

        return $client;
    }

    /** Gives another teacher an account (a sign-in of their own), then comes back as prof.martin. */
    private function signInElsewhere(KernelBrowser $client, string $login): void
    {
        $this->signOut($client);
        $this->submitLogin($client, $login);
        $this->signOut($client);
        $this->submitLogin($client, 'prof.martin');
    }

    private function add(KernelBrowser $client, string $login): void
    {
        $crawler = $client->request('GET', '/teacher/admin/teachers');
        $client->submit($crawler->filter('form[action$="/add"]')->form(['login' => $login]));
    }
}
