<?php

declare(strict_types=1);

namespace App\Tests\Controller\Identity;

use App\Entity\Identity\AdministrationEvent;
use App\Entity\Identity\Enrolment;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Enum\Identity\AdministrationAction;
use App\Enum\Identity\EnrolmentOrigin;
use App\Enum\Identity\PlacementOutcome;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The rentrée of design-ux.md §§ 4.1 and 4.12, through the screens, from an empty Peyra: the
 * administrator prepares a year, students are placed by their directory group when their account
 * is created, the next year takes over the classes, the passage de classe, « À placer ».
 */
final class RentreeTest extends WebTestCase
{
    use SignsIn;

    public function testTheRentreeFromAnEmptyPeyraToThePassageDeClasse(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        // No year yet: the administrator is offered « Préparer l'année », another teacher is told whom to ask.
        $this->submitLogin($client, 'prof.martin');
        $this->as($client, 'prof.durand');
        $client->request('GET', '/teacher');
        self::assertSelectorTextContains('.px-empty', 'Demandez à un administrateur de Peyra de créer les classes de l’année : Jean-Pierre Martin.');
        $this->as($client, 'prof.martin');
        $client->request('GET', '/teacher');
        self::assertSelectorExists('a[href="/teacher/admin/years"]:contains("Préparer l’année")');

        // The first year, its classes, its mapping.
        $this->createYear($client, '2025-09-01', '2026-08-31', false);
        self::assertSelectorTextContains('.px-statusbar', 'Année 2025-2026 créée ; c’est l’année courante.');
        $this->createClass($client, 'BTS CG 1', '1');
        $this->createClass($client, 'BTS CG 2', '2');
        [$cg1, $cg2] = $this->classesOf($client, '2025-2026');
        $crawler = $client->request('GET', '/teacher/admin/directory');
        $client->submit($crawler->filter('#directory-form')->form(['group[cg-1]' => (string) $cg1->getId(), 'group[cg-2]' => (string) $cg2->getId()]));
        self::assertResponseRedirects();

        // First sign-ins: placed by their group, or « à placer » with two contradictory groups.
        foreach (['lucas.bernard', 'yasmine.haddad', 'jules.martel'] as $login) {
            $this->as($client, $login);
        }
        $this->as($client, 'lucas.bernard');
        $client->request('GET', '/dossiers');
        self::assertSelectorTextContains('.px-pagehead', 'BTS CG 1 · 2025-2026');
        self::assertSelectorNotExists('.px-alert');
        $this->as($client, 'jules.martel');
        $client->request('GET', '/dossiers');
        self::assertSelectorTextContains('.px-alert', 'Vous n’êtes dans aucune classe pour le moment. Un enseignant va vous placer ; vous n’avez rien à faire.');
        self::assertSame(PlacementOutcome::ContradictoryGroups, $this->account($client, 'jules.martel')?->getInitialPlacement());
        self::assertSame($cg1->getId(), $this->classOf($client, 'lucas.bernard', '2025-2026')?->getId());
        self::assertSame($cg2->getId(), $this->classOf($client, 'yasmine.haddad', '2025-2026')?->getId());

        // The teacher's home counts the student to place; the class lists its student.
        $this->as($client, 'prof.durand');
        $client->request('GET', '/teacher');
        self::assertSelectorTextContains('.px-alert', '1 étudiant attend une classe en 2025-2026.');
        $crawler = $client->request('GET', '/teacher/classes/'.$cg1->getId());
        self::assertSame(['Bernard Lucas'], $crawler->filter('tbody td:nth-child(2) a')->each(static fn ($a): string => $a->text()));

        // The next year takes over the classes and the mapping, and names CG2 as CG1's next class.
        $this->as($client, 'prof.martin');
        $this->createYear($client, '2026-09-01', '2027-08-31', true);
        self::assertSelectorTextContains('.px-statusbar', 'Année 2026-2027 créée.');
        [$newCg1, $newCg2] = $this->classesOf($client, '2026-2027');
        self::assertSame($newCg2->getId(), $this->reload($client, $cg1)->getNextClass()?->getId());
        self::assertNull($this->reload($client, $cg2)->getNextClass());
        $crawler = $client->request('GET', '/teacher/admin/years');
        $client->submit($crawler->filter('form[action$="/current"]')->form());
        self::assertResponseRedirects('/teacher/admin/years');
        self::assertTrue($this->year($client, '2026-2027')->isCurrent());
        self::assertFalse($this->year($client, '2025-2026')->isCurrent());

        // Nobody has a class in the new year yet: three to place.
        $crawler = $client->request('GET', '/teacher/classes/unplaced');
        self::assertSame(['Bernard Lucas', 'Haddad Yasmine', 'Martel Jules'], $crawler->filter('tbody td:nth-child(2) a')->each(static fn ($a): string => $a->text()));

        // The passage: CG1 2025-2026 → CG2 2026-2027, everybody ticked.
        $crawler = $client->request('GET', '/teacher/classes/promotion');
        self::assertSelectorTextContains('tbody', 'BTS CG 1 · 2025-2026');
        $client->click($crawler->filter('a:contains("Choisir les étudiants")')->link());
        $crawler = $client->getCrawler();
        self::assertSame([true], $crawler->filter('input[name="pass[]"]')->each(static fn ($i): bool => null !== $i->attr('checked')));
        $crawler = $client->submit($crawler->filter('#promotion-form')->form());
        self::assertSelectorTextContains('.px-card', '1 passe en BTS CG 2 · 2026-2027. Les dossiers ne sont pas touchés.');
        $client->submit($crawler->filter('form[action$="/promotion/'.$cg1->getId().'"]')->form());
        self::assertResponseRedirects('/teacher/classes/'.$newCg2->getId());
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '1 passe en BTS CG 2 · 2026-2027. Les dossiers ne sont pas touchés.');
        $enrolment = $this->enrolment($client, 'lucas.bernard', '2026-2027');
        self::assertSame([$newCg2->getId(), EnrolmentOrigin::Promotion], [$enrolment?->getSchoolClass()->getId(), $enrolment?->getOrigin()]);

        // Made again without harm: the student already enrolled is greyed with his class.
        $client->request('GET', '/teacher/classes/promotion/'.$cg1->getId());
        self::assertSelectorTextContains('tr.is-dim', 'Déjà en BTS CG 2');
        self::assertSelectorNotExists('input[name="pass[]"]');

        // « À placer »: Jules placed in CG1, Yasmine (end of the BTS) out of the field, then back.
        $crawler = $client->request('GET', '/teacher/classes/unplaced');
        $form = $crawler->filter('form[action$="/unplaced/place"]')->form();
        $form['class']->setValue((string) $newCg1->getId());
        $this->tick($form, $crawler, 'Martel Jules');
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '1 étudiant placé en BTS CG 1.');
        $crawler = $client->request('GET', '/teacher/classes/unplaced');
        $form = $crawler->filter('form[action$="/unplaced/place"]')->form();
        $this->tick($form, $crawler, 'Haddad Yasmine');
        $client->request('POST', '/teacher/classes/unplaced/depart', $form->getPhpValues());
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '1 étudiant marqué sorti de la filière ; son compte et ses dossiers restent.');
        self::assertSelectorTextContains('.px-empty', 'Personne à placer');
        $crawler = $client->request('GET', '/teacher/classes/unplaced?show=departed');
        self::assertSame(['Haddad Yasmine'], $crawler->filter('tbody td:nth-child(2) a')->each(static fn ($a): string => $a->text()));

        // A student whose account is created now is placed in the new year by the mapping taken over.
        $this->as($client, 'theo.garnier');
        self::assertSame($newCg1->getId(), $this->classOf($client, 'theo.garnier', '2026-2027')?->getId());

        // Yasmine signs in again: she is back in « À placer », her class of last year untouched.
        $this->as($client, 'yasmine.haddad');
        self::assertFalse((bool) $this->account($client, 'yasmine.haddad')?->isDeparted());
        self::assertSame($cg2->getId(), $this->classOf($client, 'yasmine.haddad', '2025-2026')?->getId());

        // Last year's classes are history: read, never changed.
        $this->as($client, 'prof.durand');
        $client->request('GET', '/teacher/classes/'.$cg1->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.px-pagehead', 'En lecture');
        self::assertSelectorNotExists('form[action$="/add"]');
        self::assertSelectorNotExists('input[name="students[]"]');
        $client->request('POST', '/teacher/classes/'.$cg1->getId().'/add', ['student' => '1']);
        self::assertResponseStatusCodeSame(404);

        // Every placement went to the administration journal.
        $actions = array_map(
            static fn (AdministrationEvent $e): AdministrationAction => $e->getAction(),
            $client->getContainer()->get(EntityManagerInterface::class)->getRepository(AdministrationEvent::class)->findAll(),
        );
        foreach ([AdministrationAction::SchoolYearCreated, AdministrationAction::SchoolYearMadeCurrent, AdministrationAction::ClassCreated, AdministrationAction::ClassUpdated, AdministrationAction::DirectoryMappingSaved, AdministrationAction::StudentPromoted, AdministrationAction::StudentPlaced, AdministrationAction::StudentDeparted] as $expected) {
            self::assertContains($expected, $actions, $expected->label());
        }
    }

    private function as(KernelBrowser $client, string $login): void
    {
        $this->signOut($client);
        $this->submitLogin($client, $login);
    }

    private function createYear(KernelBrowser $client, string $start, string $end, bool $takeOver): void
    {
        $crawler = $client->request('GET', '/teacher/admin/years');
        $form = $crawler->filter('form[action="/teacher/admin/years"]')->form(['starts_on' => $start, 'ends_on' => $end]);
        if (isset($form['take_over'])) {
            $box = $form['take_over'];
            \assert($box instanceof \Symfony\Component\DomCrawler\Field\ChoiceFormField);
            $takeOver ? $box->tick() : $box->untick();
        }
        $client->submit($form);
        self::assertResponseRedirects('/teacher/admin/years');
        $client->followRedirect();
    }

    private function createClass(KernelBrowser $client, string $name, string $level): void
    {
        $crawler = $client->request('GET', '/teacher/admin/classes');
        $client->submit($crawler->filter('form[action="/teacher/admin/classes"][method="post"]')->form(['name' => $name, 'level' => $level]));
        self::assertResponseRedirects();
    }

    private function tick(\Symfony\Component\DomCrawler\Form $form, \Symfony\Component\DomCrawler\Crawler $crawler, string $name): void
    {
        $value = $crawler->filter('tbody tr')->reduce(static fn ($tr): bool => str_contains($tr->text(), $name))->filter('input[name="students[]"]')->attr('value');
        foreach ($form['students'] as $box) {
            \assert($box instanceof \Symfony\Component\DomCrawler\Field\ChoiceFormField);
            if ($box->availableOptionValues() === [$value]) {
                $box->tick();
            }
        }
    }

    /** @return list<SchoolClass> */
    private function classesOf(KernelBrowser $client, string $year): array
    {
        return $client->getContainer()->get(SchoolClassRepository::class)->findByYear($this->year($client, $year));
    }

    private function year(KernelBrowser $client, string $label): SchoolYear
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        foreach ($client->getContainer()->get(SchoolYearRepository::class)->findAllNewestFirst() as $year) {
            if ($year->getLabel() === $label) {
                return $year;
            }
        }

        throw new \LogicException('No year '.$label);
    }

    private function reload(KernelBrowser $client, SchoolClass $class): SchoolClass
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();

        return $client->getContainer()->get(SchoolClassRepository::class)->find($class->getId()) ?? throw new \LogicException('Gone.');
    }

    private function enrolment(KernelBrowser $client, string $login, string $year): ?Enrolment
    {
        $student = $this->account($client, $login) ?? throw new \LogicException('No account '.$login);

        return $client->getContainer()->get(EnrolmentRepository::class)->findOneForStudentAndYear($student, $this->year($client, $year));
    }

    private function classOf(KernelBrowser $client, string $login, string $year): ?SchoolClass
    {
        return $this->enrolment($client, $login, $year)?->getSchoolClass();
    }
}
