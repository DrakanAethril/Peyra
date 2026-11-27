<?php

declare(strict_types=1);

namespace App\Tests\Controller\Dossier;

use App\Entity\Audit\AuditEvent;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Identity\SchoolClasses;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A dossier's screens (lot 1, step 1.1): « Mes dossiers », the dashboard and the fiche société,
 * the date chip; who enters (DossierVoter, a refusal reads as a 404); the teacher's ribbon and the
 * date they move; the flash the student sees once.
 *
 * The school: prof.durand teaches BTS CG 1 2026-2027, where inès.morel and lucas.bernard are
 * placed; prof.martin, administrator, teaches no class; Inès holds Menuiserie Vasseur.
 */
final class DossierScreensTest extends WebTestCase
{
    use SavesDossiers;
    use BuildsSchool;
    use SignsIn;

    public function testAStudentFindsTheirDossierAndOpensItsDashboard(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');

        $crawler = $client->request('GET', '/dossiers');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-dossiers__year', '2026-2027');
        self::assertSelectorTextContains('.px-dossiers .px-card', 'Menuiserie Vasseur SARL');
        $open = $crawler->filter('.px-dossiers a.px-btn')->attr('href');
        self::assertSame('/d/'.$dossier->getId(), $open);

        $client->request('GET', $open);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertSelectorTextContains('.px-switcher', 'Menuiserie Vasseur SARL');
        self::assertSelectorTextContains('.px-switcher', 'Exercice 2026');
        self::assertSelectorTextContains('.px-datechip .px-chip', 'Date du dossier · 15 mars 2026');
        self::assertSelectorTextContains('.px-datechip__panel', 'Mars 2026, ouverte');
        self::assertSelectorTextContains('.px-datechip__panel', 'La date du dossier est avancée par votre enseignant.');
        self::assertSelectorNotExists('.px-datechip__form');
        self::assertSelectorNotExists('.px-contextbar');
        self::assertNotNull($this->reload($client, $dossier)->getLastVisitedAt(), 'Le passage de l’étudiant est noté.');
    }

    public function testNobodyElseEntersAndAnUnknownDossierIsNotThere(): void
    {
        [$client, $dossier] = $this->school();
        foreach (['lucas.bernard', 'prof.martin'] as $login) {
            $this->as($client, $login);
            $client->request('GET', '/d/'.$dossier->getId());
            self::assertResponseStatusCodeSame(404, $login);
            $client->request('GET', '/d/'.$dossier->getId().'/company');
            self::assertResponseStatusCodeSame(404, $login);
        }
        $this->as($client, 'ines.morel');
        $client->request('GET', '/d/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTeacherOfTheClassWorksInItUnderTheRibbon(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'prof.durand');

        $crawler = $client->request('GET', '/teacher/students/'.$this->id($client, 'ines.morel'));
        $open = $crawler->filter('a.px-btn[target="_blank"]');
        self::assertSame('Ouvrir le dossier', trim($open->text()));

        $client->request('GET', (string) $open->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-contextbar', 'Dossier de Inès Morel · vos actions sont signées de votre nom');
        self::assertSelectorExists('.px-datechip__form');
    }

    public function testTheFicheSocieteIsSavedOrSaysWhatIsWrong(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');
        $crawler = $client->request('GET', '/d/'.$dossier->getId().'/company');
        self::assertSelectorExists('button[data-px-shortcut~="F10"]');
        $form = $crawler->filter('#company-form')->form();

        $form['siret'] = '1234';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#siret_error', 'Le SIRET compte 14 chiffres.');
        self::assertSelectorTextContains('.px-alert', 'un champ est à corriger');

        $form['siret'] = '999 412 736 00018';
        $form['city'] = 'Saint-Étienne';
        $form['postalCode'] = '42000';
        $client->submit($form);
        self::assertResponseRedirects('/d/'.$dossier->getId().'/company');
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Fiche société enregistrée.');
        self::assertSame('Saint-Étienne', $this->reload($client, $dossier)->getCompany()->getCity());
        $audit = $client->getContainer()->get(EntityManagerInterface::class)->getRepository(AuditEvent::class)->findOneBy(['action' => AuditAction::DossierCompanyChanged]);
        self::assertInstanceOf(AuditEvent::class, $audit);
        self::assertSame(['postalCode', 'city'], $audit->getDetails()['fields'] ?? null);
    }

    public function testOnlyTheTeacherMovesTheDateAndTheStudentSeesItOnce(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'ines.morel');
        $crawler = $client->request('GET', '/d/'.$dossier->getId());
        $client->request('POST', '/d/'.$dossier->getId().'/date', ['date' => '2026-11-30', '_token' => 'forged', 'return' => '/d/'.$dossier->getId()]);
        self::assertResponseStatusCodeSame(404);

        $this->as($client, 'prof.durand');
        $crawler = $client->request('GET', '/d/'.$dossier->getId().'/company');
        $form = $crawler->filter('.px-datechip__form')->form();
        $form['date'] = '2026-11-30';
        $client->submit($form);
        self::assertResponseRedirects('/d/'.$dossier->getId().'/company');
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Date du dossier : 30/11/2026.');
        self::assertSelectorTextContains('.px-datechip__changes', 'Du 15/03/2026 au 30/11/2026 · Sophie Durand');

        $this->as($client, 'ines.morel');
        $client->request('GET', '/d/'.$dossier->getId());
        self::assertSelectorExists('.px-datechip .px-chip.is-flash');
        self::assertSelectorTextContains('.px-statusbar', 'Votre enseignant a fixé la date du dossier au 30/11/2026');
        self::assertSelectorTextContains('.px-datechip .px-chip', '30 nov. 2026');
        $client->request('GET', '/d/'.$dossier->getId());
        self::assertSelectorNotExists('.px-datechip .px-chip.is-flash');
    }

    public function testTheReturnAddressStaysInTheDossier(): void
    {
        [$client, $dossier] = $this->school();
        $this->as($client, 'prof.durand');
        $crawler = $client->request('GET', '/d/'.$dossier->getId());
        $form = $crawler->filter('.px-datechip__form')->form();
        $form['date'] = '2026-04-01';
        $form['return'] = 'https://example.org/';
        $client->submit($form);

        self::assertResponseRedirects('/d/'.$dossier->getId());
    }

    /**
     * prof.durand teaches BTS CG 1; ines.morel and lucas.bernard are placed in it by their first
     * sign-in; Inès holds Menuiserie Vasseur. Ends signed in as prof.martin.
     *
     * @return array{KernelBrowser, Dossier}
     */
    private function school(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.durand');
        $this->as($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $durand = $this->account($client, 'prof.durand');
        \assert($durand instanceof User);
        $client->getContainer()->get(SchoolClasses::class)->addTeacher($cg1, $durand, $martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $this->as($client, 'lucas.bernard');
        $this->as($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel');
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($ines instanceof User && $year instanceof SchoolYear);
        $dossier = $this->savedDossier(DossierKind::Student, $ines, $year);
        $this->as($client, 'prof.martin');

        return [$client, $dossier];
    }

    private function as(KernelBrowser $client, string $login): void
    {
        $this->signOut($client);
        $this->submitLogin($client, $login);
    }

    private function id(KernelBrowser $client, string $login): int
    {
        return (int) $this->account($client, $login)?->getId();
    }

    private function reload(KernelBrowser $client, Dossier $dossier): Dossier
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Dossier::class, $dossier->getId());
        \assert($reloaded instanceof Dossier);

        return $reloaded;
    }
}
