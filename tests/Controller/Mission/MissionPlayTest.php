<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mission;

use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\DistributionCopy;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Entity\Mission\MissionProgress;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\RestorePointOrigin;
use App\Enum\Identity\ClassLevel;
use App\Repository\Mission\MissionProgressRepository;
use App\Service\Mission\MissionAssignments;
use App\Service\Mission\MissionSheet;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The mission on the student's side (step 2.9b): Inès's copy plays prof.durand's mission - the banner
 * says « Mission 1 · tâche 1 sur 2 », the brouillard task is ticked by the dossier (it holds no draft),
 * « Tâche terminée » ticks the declared one, « Rendre la mission » poses the « Rendu » point outside
 * the limit and closes the ticking; the teacher reads who handed it in. A teacher's post on the
 * student's gestures answers 404.
 */
final class MissionPlayTest extends WebTestCase
{
    use SavesDossiers;
    use SignsIn;

    public function testAStudentTicksATaskAndHandsTheMissionIn(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.durand');
        $durand = $this->account($client, 'prof.durand');
        $this->signOut($client);
        $this->submitLogin($client, 'ines.morel');
        $ines = $this->account($client, 'ines.morel');
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $durand = $entityManager->find(User::class, $durand?->getId());
        \assert($durand instanceof User && $ines instanceof User);

        $at = new \DateTimeImmutable('2026-11-28 10:00');
        $year = new SchoolYear(new \DateTimeImmutable('2033-09-01'), new \DateTimeImmutable('2034-08-31'), $at);
        $class = new SchoolClass($year, 'BTS CG 1 quater', ClassLevel::First, $at);
        $entityManager->persist($year);
        $entityManager->persist($class);
        $template = $this->savedDossier(DossierKind::Template, $durand);
        $copy = $this->savedDossier(DossierKind::Student, $ines);
        $distribution = new Distribution($template, $durand, $class, DistributionMode::Individual, new \DateTimeImmutable('2026-03-15'), $at);
        $line = DistributionCopy::forStudent($distribution, $ines);
        $line->markReady($copy, $at);
        $entityManager->persist($distribution);
        $entityManager->persist($line);
        $entityManager->flush();
        $sheet = $client->getContainer()->get(MissionSheet::class);
        $mission = $sheet->create($template, 'Janvier, de la pièce à l’écriture', $durand, $at);
        $sheet->setTasks($mission, [['title' => 'Contrôler les factures', 'instruction' => 'Mentions et calculs.'], ['title' => 'Valider le brouillard', 'instruction' => '', 'check' => 'drafts']], $durand);
        $client->getContainer()->get(MissionAssignments::class)->give($mission, $distribution, $at->modify('+1 week'), $durand, $at);
        $key = $mission->getTasks()[0]['key'];
        $id = (int) $copy->getId();

        $crawler = $client->request('GET', '/d/'.$id.'/company');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mission 1 · tâche 1 sur 2', $crawler->filter('.px-mission')->text());
        self::assertSame(['is-current', 'is-done'], $crawler->filter('.px-mission__steps li')->each(static fn ($li): string => (string) $li->attr('class')));
        $token = (string) $crawler->filter('#mission-panel input[name="_token"]')->attr('value');

        $client->request('POST', '/d/'.$id.'/mission/tasks/'.$key, ['_token' => $token, 'done' => '1', 'return' => '/d/'.$id.'/company']);
        self::assertResponseRedirects('/d/'.$id.'/company', 303);
        $crawler = $client->request('GET', '/d/'.$id.'/company');
        self::assertStringContainsString('Mission 1 · 2 tâches sur 2 faites', $crawler->filter('.px-mission')->text());

        $client->request('POST', '/d/'.$id.'/mission/submit', ['_token' => $token, 'return' => 'https://ailleurs.example/']);
        self::assertResponseRedirects('/d/'.$id, 303);
        $client->request('POST', '/d/'.$id.'/mission/tasks/'.$key, ['_token' => $token, 'done' => '0']);
        $entityManager->clear();
        $progress = $client->getContainer()->get(MissionProgressRepository::class)->findBy(['dossier' => $id]);
        self::assertCount(1, $progress);
        self::assertInstanceOf(MissionProgress::class, $progress[0]);
        self::assertTrue($progress[0]->isSubmitted());
        self::assertSame([$key], $progress[0]->getDeclaredTasks(), 'Une mission rendue ne se décoche plus.');
        $point = $progress[0]->getRestorePoint();
        self::assertInstanceOf(RestorePoint::class, $point);
        self::assertSame(RestorePointOrigin::Submitted, $point->getOrigin());
        self::assertSame('Rendu · Janvier, de la pièce à l’écriture', $point->getName());
        self::assertFalse($point->getOrigin()->countsAgainstLimit());
        $crawler = $client->request('GET', '/d/'.$id.'/company');
        self::assertStringContainsString('Mission 1 · rendue le', $crawler->filter('.px-mission')->text());
        self::assertCount(0, $crawler->filter('#mission-panel form'));

        $this->signOut($client);
        $this->submitLogin($client, 'prof.durand');
        $crawler = $client->request('GET', '/teacher/library/missions/'.$mission->getId().'/assignments');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 sur 1', $crawler->filter('main')->text());
        self::assertStringContainsString('Rendue', $crawler->filter('main')->text());
        self::assertSame(404, $this->post($client, $copy, $key, $token));
    }

    private function post(KernelBrowser $client, Dossier $copy, string $key, string $token): int
    {
        $client->request('POST', '/d/'.$copy->getId().'/mission/tasks/'.$key, ['_token' => $token, 'done' => '1']);

        return $client->getResponse()->getStatusCode();
    }
}
