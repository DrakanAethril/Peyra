<?php

declare(strict_types=1);

namespace App\Tests\Controller\Identity;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\AdministrationEvent;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AdministrationAction;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Identity\SchoolClasses;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A student's own assistance level (step 2.8; design-ux.md §§ 3.3 and 3.4): set by a teacher from
 * the student's record, written to the administration journal, shown in the class's list, and
 * applied at the student's next screen - here what the plan of accounts, the guided entry and the
 * brouillard do at levels B and 0 rather than at the default « Avertissements ».
 */
final class StudentAssistanceTest extends WebTestCase
{
    use SavesDossiers;
    use BuildsSchool;
    use SignsIn;

    public function testATeacherSetsAStudentsLevelAndHerDossierFollowsIt(): void
    {
        [$client, $dossier, $ines, $classId] = $this->school();
        $this->as($client, 'ines.morel');
        $client->request('GET', $this->url($dossier, '/accounting/entry/guided'));
        self::assertResponseStatusCodeSame(404, 'Saisie guidée masquée en avertissements.');

        $this->as($client, 'prof.durand');
        $crawler = $client->request('GET', '/teacher/students/'.$ines->getId());
        self::assertSelectorTextContains('main', 'Aménagements');
        self::assertSelectorExists('input[name="level"][value="mission"][checked]');
        $form = $crawler->filter('form[action$="/assistance"]')->form();
        $form['level'] = 'blocking';
        $client->submit($form);
        self::assertResponseRedirects('/teacher/students/'.$ines->getId());
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Niveau d’assistance de Inès Morel : contrôles bloquants. Il s’applique dès son prochain écran.');
        self::assertSelectorExists('input[name="level"][value="blocking"][checked]');
        $client->request('GET', '/teacher/classes/'.$classId);
        self::assertSelectorTextContains('main table', 'Assistance : contrôles bloquants');

        $event = $this->entityManager($client)->getRepository(AdministrationEvent::class)->findOneBy(['action' => AdministrationAction::StudentAssistanceChanged]);
        self::assertInstanceOf(AdministrationEvent::class, $event);
        self::assertSame('Celui de la mission → Contrôles bloquants', $event->getDetail());
        self::assertSame('ines.morel', $event->getSubjectLogin());

        // At B: the guided entry opens, a number outside the PCG is refused, the brouillard says so.
        $this->as($client, 'ines.morel');
        $client->request('GET', $this->url($dossier, '/accounting/entry/guided'));
        self::assertResponseIsSuccessful();
        $client->request('GET', $this->url($dossier, '/accounting/brouillard'));
        self::assertSelectorTextContains('.px-statusbar', 'Niveau d’assistance · Contrôles bloquants');
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts/new'));
        $form = $crawler->filter('#account-form')->form();
        $form['number'] = '190000';
        $form['label'] = 'Compte inventé';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#number_error', 'Le compte 190000 ne subdivise aucun compte du PCG 2026 : choisissez un numéro du plan.');

        // At 0: nothing is said of it.
        $this->setLevel($client, $ines, 'none');
        $this->as($client, 'ines.morel');
        $crawler = $client->request('GET', $this->url($dossier, '/accounting/accounts/new'));
        $form = $crawler->filter('#account-form')->form();
        $form['number'] = '190000';
        $form['label'] = 'Compte inventé';
        $client->submit($form);
        self::assertResponseRedirects($this->url($dossier, '/accounting/accounts/190000'));
        $client->followRedirect();
        self::assertSelectorTextNotContains('.px-statusbar', 'ne subdivise aucun compte');

        // Given back to the mission's: the default, « Avertissements ».
        $this->setLevel($client, $ines, 'mission');
        $fresh = $this->entityManager($client)->find(User::class, $ines->getId());
        self::assertNull($fresh?->getAssistanceLevel());
    }

    public function testOnlyATeacherSetsItAndOnlyToALevel(): void
    {
        [$client, , $ines] = $this->school();
        $this->as($client, 'ines.morel');
        $client->request('POST', '/teacher/students/'.$ines->getId().'/assistance', ['level' => 'none']);
        self::assertResponseStatusCodeSame(404);

        $this->as($client, 'prof.durand');
        $crawler = $client->request('GET', '/teacher/students/'.$ines->getId());
        $token = $crawler->filter('form[action$="/assistance"] input[name="_token"]')->attr('value');
        $client->request('POST', '/teacher/students/'.$ines->getId().'/assistance', ['level' => 'expert', '_token' => $token]);
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Choisissez un niveau d’assistance.');
        $fresh = $this->entityManager($client)->find(User::class, $ines->getId());
        self::assertNull($fresh?->getAssistanceLevel());

        $client->request('POST', '/teacher/students/'.$ines->getId().'/assistance', ['level' => 'mission', '_token' => $token]);
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Rien à enregistrer : le niveau est inchangé.');
    }

    private function setLevel(KernelBrowser $client, User $student, string $level): void
    {
        $this->as($client, 'prof.durand');
        $crawler = $client->request('GET', '/teacher/students/'.$student->getId());
        $form = $crawler->filter('form[action$="/assistance"]')->form();
        $form['level'] = $level;
        $client->submit($form);
        self::assertResponseRedirects('/teacher/students/'.$student->getId());
    }

    /** @return array{KernelBrowser, Dossier, User, int} */
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
        $this->entityManager($client)->flush();
        $this->as($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel');
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent();
        \assert($ines instanceof User && $year instanceof SchoolYear);
        $dossier = $this->savedDossier(DossierKind::Student, $ines, $year);
        $this->installPlan($dossier);

        return [$client, $dossier, $ines, (int) $cg1->getId()];
    }

    private function as(KernelBrowser $client, string $login): void
    {
        $this->signOut($client);
        $this->submitLogin($client, $login);
    }

    private function url(Dossier $dossier, string $path): string
    {
        return '/d/'.$dossier->getId().$path;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
