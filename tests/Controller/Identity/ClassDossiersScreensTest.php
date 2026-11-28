<?php

declare(strict_types=1);

namespace App\Tests\Controller\Identity;

use App\Entity\Dossier\DossierRestoration;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\RestorationState;
use App\Repository\Dossier\DistributionRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Classe › Dossiers through HTTP (lot 1, step 1.8d; design-ux.md § 4.9, step 4): prof.durand
 * distributes the common library's template to two students of BTS CG 1, teaches the class, then
 * from the class's Dossiers tab poses a point on both and takes both back to their « État de
 * départ » through the récapitulatif; and does it again from the distribution's page.
 */
final class ClassDossiersScreensTest extends WebTestCase
{
    use BuildsSchool;
    use SavesDossiers;
    use SignsIn;

    public function testATeacherPosesPointsAndResetsTheClassesDossiers(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $this->signOut($client);
        $students = [];
        foreach (['lucas.bernard', 'manon.chevalier'] as $login) {
            $this->submitLogin($client, $login);
            $students[] = (int) $this->account($client, $login)?->getId();
            $this->signOut($client);
        }
        $this->submitLogin($client, 'prof.durand');
        $durand = $this->account($client, 'prof.durand') ?? throw new \LogicException('Signed in.');
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TeacherClass($durand, $entityManager->find(SchoolClass::class, $cg1->getId()) ?? throw new \LogicException('Built.'), new \DateTimeImmutable('2026-11-28'), null));
        $entityManager->flush();
        $template = (int) $this->savedDossier(DossierKind::Template, null)->getId();
        $base = '/teacher/library/'.$template.'/distribute';
        $crawler = $client->request('GET', $base.'/summary?'.http_build_query(['class' => $cg1->getId(), 'step' => 1, 'pick' => $students, 'mode' => 'individual']));
        $token = (string) $crawler->filter('form[action="'.$base.'"] input[name="_token"]')->attr('value');
        $client->request('POST', $base, ['_token' => $token, 'class' => $cg1->getId(), 'pick' => $students, 'mode' => 'individual']);
        self::assertResponseStatusCodeSame(303);

        // The Dossiers tab: both dossiers, each with its « État de départ ».
        $page = '/teacher/classes/'.$cg1->getId().'/dossiers';
        $crawler = $client->request('GET', $page);
        self::assertResponseIsSuccessful();
        $boxes = $crawler->filter('input[name="dossiers[]"]');
        self::assertCount(2, $boxes);
        $ids = $boxes->each(static fn ($box): int => (int) $box->attr('value'));
        self::assertSelectorTextContains('main', 'Bernard Lucas');
        self::assertSelectorTextContains('main', 'État de départ');
        $token = (string) $crawler->filter('#class-dossiers-lot input[name="_token"]')->attr('value');

        // « Poser un point » on both, under one name.
        $client->request('POST', $page.'/points', ['_token' => $token, 'name' => 'Avant la séance', 'dossiers' => $ids]);
        self::assertResponseRedirects($page);
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '2 points posés : leurs copies se préparent.');
        self::assertSelectorTextContains('main', 'Avant la séance');

        // The récapitulatif, then the reset: done after the answer, each dossier keeping its state as a point.
        $crawler = $client->request('GET', $page.'/reset?'.http_build_query(['dossiers' => $ids]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '2 dossiers reprennent l’état où leur distribution les a donnés');
        $token = (string) $crawler->filter('#start-reset input[name="_token"]')->attr('value');
        $client->request('POST', $page.'/reset', ['_token' => 'forged', 'dossiers' => $ids, 'keep' => '1']);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', $page.'/reset', ['_token' => $token, 'dossiers' => $ids, 'keep' => '1']);
        self::assertResponseRedirects($page);
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '2 dossiers remis à l’état de départ.');
        $returns = $client->getContainer()->get(EntityManagerInterface::class)->getRepository(DossierRestoration::class)->findAll();
        self::assertCount(2, $returns);
        foreach ($returns as $return) {
            self::assertSame(RestorationState::Done, $return->getState());
            self::assertNotNull($return->getSafetyPoint(), 'L’état quitté est gardé en point.');
        }

        // From the distribution's page, the same récapitulatif and the same gesture.
        $distribution = $client->getContainer()->get(DistributionRepository::class)->findOneBy([], ['id' => 'DESC']) ?? throw new \LogicException('Distributed.');
        $reset = '/teacher/library/distributions/'.$distribution->getId().'/reset';
        $crawler = $client->request('GET', $reset);
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('#start-reset input[name="_token"]')->attr('value');
        $client->request('POST', $reset, ['_token' => $token, 'dossiers' => $ids]);
        self::assertResponseRedirects('/teacher/library/distributions/'.$distribution->getId());
        $client->followRedirect();
        self::assertSelectorTextContains('.px-statusbar', '2 dossiers remis à l’état de départ.');
    }

    public function testATeacherOutsideTheClassSeesNoDossierAndTicksNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.martin');
        $martin = $this->account($client, 'prof.martin');
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel') ?? throw new \LogicException('Signed in.');
        $this->savedDossier(DossierKind::Student, $ines, $client->getContainer()->get(SchoolYearRepository::class)->findCurrent());
        $this->signOut($client);

        // prof.durand teaches no class: the class reads, its dossiers do not.
        $this->submitLogin($client, 'prof.durand');
        $client->request('GET', '/teacher/classes/'.$cg1->getId().'/dossiers');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucun dossier');
        $client->request('GET', '/teacher/classes/'.$cg1->getId().'/dossiers/reset?dossiers[]=1');
        self::assertResponseRedirects('/teacher/classes/'.$cg1->getId().'/dossiers');
    }
}
