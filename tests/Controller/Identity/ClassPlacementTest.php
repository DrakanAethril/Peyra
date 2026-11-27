<?php

declare(strict_types=1);

namespace App\Tests\Controller\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Placement by hand (conception § 3 bis): moving and taking students out of a class, the student's
 * record, the rule that the directory is never read again for the class, and the administration of
 * classes and years with its refusals.
 */
final class ClassPlacementTest extends WebTestCase
{
    use BuildsSchool;
    use SignsIn;

    public function testTheDirectoryIsNeverReadAgainForTheClass(): void
    {
        [$client, , $cg1] = $this->school();
        $this->as($client, 'lucas.bernard');
        self::assertSame($cg1->getId(), $this->classOf($client, 'lucas.bernard')?->getId());

        // The directory now says cg-2: the next sign-in records the groups, never moves the class.
        $this->directory($client)->add('lucas.bernard', 'Lucas Bernard', ['student', 'cg-2'], 'entryUUID:test-40101');
        $this->as($client, 'lucas.bernard');
        self::assertEqualsCanonicalizing(['cg-2', 'student'], $this->account($client, 'lucas.bernard')?->getDirectoryGroups());
        self::assertEqualsCanonicalizing(['cg-1', 'student'], $this->account($client, 'lucas.bernard')?->getGroupsAtCreation());
        self::assertSame($cg1->getId(), $this->classOf($client, 'lucas.bernard')?->getId());
    }

    public function testMovingAndTakingStudentsOutOfAClass(): void
    {
        [$client, , $cg1, $cg2] = $this->school();
        foreach (['lucas.bernard', 'manon.chevalier', 'theo.garnier'] as $login) {
            $this->as($client, $login);
        }
        $this->as($client, 'prof.durand');

        // Move two ticked students to CG2.
        $crawler = $client->request('GET', '/teacher/classes/'.$cg1->getId());
        self::assertCount(3, $crawler->filter('input[name="students[]"]'));
        $client->request('POST', '/teacher/classes/'.$cg1->getId().'/move', [
            '_token' => $crawler->filter('#class-lot input[name="_token"]')->attr('value'),
            'students' => [(string) $this->id($client, 'lucas.bernard'), (string) $this->id($client, 'manon.chevalier')],
            'target' => (string) $cg2->getId(),
        ]);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '2 étudiants changés de classe en BTS CG 2.');
        self::assertSame(['Garnier Théo'], $crawler->filter('tbody td:nth-child(2) a')->each(static fn ($a): string => $a->text()));
        self::assertSame($cg2->getId(), $this->classOf($client, 'manon.chevalier')?->getId());

        // Take Théo out: no class this year, in « À placer », his account untouched.
        $client->request('POST', '/teacher/classes/'.$cg1->getId().'/remove', [
            '_token' => $crawler->filter('#class-lot input[name="_token"]')->attr('value'),
            'students' => [(string) $this->id($client, 'theo.garnier')],
        ]);
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '1 étudiant retiré de BTS CG 1 ; il est dans « À placer ».');
        self::assertSelectorTextContains('.px-empty', 'Aucun étudiant dans cette classe');
        self::assertNull($this->classOf($client, 'theo.garnier'));
        self::assertNotNull($this->account($client, 'theo.garnier'));
        $crawler = $client->request('GET', '/teacher/classes/unplaced');
        self::assertSame(['Garnier Théo'], $crawler->filter('tbody td:nth-child(2) a')->each(static fn ($a): string => $a->text()));
        // His enrolment of the year is gone, so no class of an earlier year is known: « Aucune ».
        self::assertSelectorTextContains('tbody td:nth-child(5)', 'Aucune');
    }

    public function testTheStudentRecordChangesTheClass(): void
    {
        [$client, , $cg1, $cg2] = $this->school();
        $this->as($client, 'ines.morel');
        $this->as($client, 'prof.durand');
        $id = $this->id($client, 'ines.morel');

        $crawler = $client->request('GET', '/teacher/students/'.$id);
        self::assertSelectorTextContains('h1', 'Morel Inès');
        self::assertSelectorTextContains('tbody', 'BTS CG 1');
        $client->submit($crawler->filter('form[action$="/class"]')->form(['class' => (string) $cg2->getId()]));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '1 étudiant changé de classe en BTS CG 2.');
        self::assertSame($cg2->getId(), $this->classOf($client, 'ines.morel')?->getId());

        $crawler = $client->getCrawler();
        $client->submit($crawler->filter('form[action$="/class"]')->form(['class' => 'none']));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Inès Morel n’a plus de classe en 2026-2027 ; ses dossiers restent.');
        self::assertNull($this->classOf($client, 'ines.morel'));

        // Only a student has a record; the search lists students only.
        $client->request('GET', '/teacher/students/'.$this->id($client, 'prof.durand'));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/teacher/students/search?q=mo');
        self::assertSame([['id' => $id, 'name' => 'Morel Inès', 'login' => 'ines.morel']], json_decode((string) $client->getResponse()->getContent(), true));
        unset($cg1);
    }

    public function testTheAdministrationOfClassesRefusesWhatDoesNotHold(): void
    {
        [$client, $year, $cg1, $cg2] = $this->school();
        $this->as($client, 'lucas.bernard');
        $this->as($client, 'prof.martin');

        $crawler = $client->request('GET', '/teacher/admin/classes');
        $client->submit($crawler->filter('form[action="/teacher/admin/classes"][method="post"]')->form(['name' => 'BTS CG 1', 'level' => '1']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-field__error', 'L’année 2026-2027 a déjà une classe « BTS CG 1 ».');
        $client->submit($crawler->filter('form[action="/teacher/admin/classes"][method="post"]')->form(['name' => 'BTS CG 3', 'level' => '']));
        self::assertSelectorTextContains('.px-field__error', 'Choisissez l’année du BTS que prépare la classe.');

        // A next class belongs to a later year; a class with students is never deleted.
        $crawler = $client->request('GET', '/teacher/admin/classes/'.$cg1->getId());
        $form = $crawler->filter('#class-form')->form();
        $client->request('POST', '/teacher/admin/classes/'.$cg1->getId(), ['_token' => $form['_token']->getValue(), 'name' => 'BTS CG 1', 'level' => '1', 'next' => (string) $cg2->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-field__error', 'La classe suivante appartient à une année qui commence après celle-ci.');
        self::assertAnySelectorTextContains('.px-card', 'Une classe qui a des étudiants ne se supprime pas.');
        self::assertSelectorNotExists('form[action$="/delete"]');

        // An empty class goes, in two steps; the teacher of a class finds it in « Mes classes ».
        $crawler = $client->request('GET', '/teacher/admin/classes/'.$cg2->getId());
        $client->submit($crawler->filter('form[action$="/teachers"]')->form(['teacher' => (string) $this->id($client, 'prof.martin')]));
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', 'Jean-Pierre Martin enseigne en BTS CG 2.');
        $client->request('GET', '/teacher');
        self::assertAnySelectorTextContains('.px-card', 'BTS CG 2');
        $crawler = $client->request('GET', '/teacher/admin/classes/'.$cg2->getId());
        $client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/teacher/admin/classes?year='.$year);
        self::assertSame(1, $this->classCount($client));
    }

    public function testTheAdministrationOfYearsRefusesWhatDoesNotHold(): void
    {
        [$client] = $this->school();
        $crawler = $client->request('GET', '/teacher/admin/years');
        $form = $crawler->filter('form[action="/teacher/admin/years"]')->form();

        $client->submit($form, ['starts_on' => '2027-03-01', 'ends_on' => '2028-02-28']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.px-field__error', 'Ces dates chevauchent l’année 2026-2027.');
        $client->submit($form, ['starts_on' => '2028-09-01', 'ends_on' => '2028-08-31']);
        self::assertSelectorTextContains('.px-field__error', 'L’année doit finir après avoir commencé.');
        $client->submit($form, ['starts_on' => '2028-09-01', 'ends_on' => '2030-08-31']);
        self::assertSelectorTextContains('.px-field__error', 'Une année scolaire dure un an au plus.');
        $client->submit($form, ['starts_on' => 'demain', 'ends_on' => '2030-08-31']);
        self::assertSelectorTextContains('.px-field__error', 'Saisissez la date de début et la date de fin de l’année.');
    }

    /**
     * prof.martin signed in, a current year 2026-2027 with its two classes mapped to cg-1 and cg-2.
     *
     * @return array{KernelBrowser, int, SchoolClass, SchoolClass}
     */
    private function school(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [$year, $cg1, $cg2] = $this->buildYear($client, $martin, 2026, true);

        return [$client, (int) $year->getId(), $cg1, $cg2];
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

    private function classOf(KernelBrowser $client, string $login): ?SchoolClass
    {
        $client->getContainer()->get(EntityManagerInterface::class)->clear();
        $student = $this->account($client, $login) ?? throw new \LogicException('No account '.$login);
        $year = $client->getContainer()->get(SchoolYearRepository::class)->findCurrent() ?? throw new \LogicException('No current year.');

        return $client->getContainer()->get(EnrolmentRepository::class)->findOneForStudentAndYear($student, $year)?->getSchoolClass();
    }

    private function classCount(KernelBrowser $client): int
    {
        return $client->getContainer()->get(EntityManagerInterface::class)->getRepository(SchoolClass::class)->count([]);
    }
}
