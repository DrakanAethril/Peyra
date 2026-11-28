<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mission;

use App\Entity\Dossier\Distribution;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Entity\Mission\Mission;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\ClassLevel;
use App\Repository\Mission\MissionAssignmentRepository;
use App\Repository\Mission\MissionRepository;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The mission's editor, posted tab by tab (step 2.9a): created on prof.durand's template, its tasks
 * written then the second moved up (an empty row dropped), a refused title kept with its fault (422),
 * its settings, then given to his distribution of the template.
 */
final class MissionEditorTest extends WebTestCase
{
    use SavesDossiers;
    use SignsIn;

    public function testATeacherComposesAndGivesAMission(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, 'prof.durand');
        $durand = $this->account($client, 'prof.durand');
        \assert($durand instanceof User);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $at = new \DateTimeImmutable('2026-11-28 10:00');
        $year = new SchoolYear(new \DateTimeImmutable('2031-09-01'), new \DateTimeImmutable('2032-08-31'), $at);
        $class = new SchoolClass($year, 'BTS CG 1 ter', ClassLevel::First, $at);
        $template = $this->savedDossier(DossierKind::Template, $durand);
        $distribution = new Distribution($template, $durand, $class, DistributionMode::Individual, new \DateTimeImmutable('2026-03-15'), $at);
        foreach ([$year, $class, $distribution] as $one) {
            $entityManager->persist($one);
        }
        $entityManager->flush();

        $crawler = $client->request('GET', '/teacher/library/missions/new?template='.$template->getId());
        $client->submit($crawler->filter('#mission-new')->form(['title' => 'Janvier, de la pièce à l’écriture']));
        self::assertResponseStatusCodeSame(303);
        $mission = $client->getContainer()->get(MissionRepository::class)->findByAuthor($durand)[0] ?? null;
        self::assertInstanceOf(Mission::class, $mission);
        $base = '/teacher/library/missions/'.$mission->getId();
        $token = (string) $client->request('GET', $base.'/tasks')->filter('#mission-form input[name="_token"]')->attr('value');

        $client->request('POST', $base.'/tasks', ['_token' => $token, 'move' => '1:up', 'tasks' => [
            ['title' => 'Contrôler les factures', 'instruction' => 'Mentions et calculs.'],
            ['title' => 'Écarter le doublon', 'instruction' => ''],
            ['title' => '', 'instruction' => ''],
        ]]);
        self::assertResponseStatusCodeSame(303);
        $client->request('POST', $base, ['_token' => $token, 'title' => '', 'statement' => 'x', 'modules' => ['documents']]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $base.'/settings', ['_token' => $token, 'level' => 'guided', 'behaviour' => 'propose', 'guided' => '1']);
        self::assertResponseStatusCodeSame(303);
        $client->request('POST', $base.'/assignments', ['_token' => $token, 'distribution' => (string) $distribution->getId(), 'dueOn' => '2099-12-04', 'dueTime' => '18:00']);
        self::assertResponseStatusCodeSame(303);

        $entityManager->clear();
        $mission = $client->getContainer()->get(MissionRepository::class)->find($mission->getId());
        self::assertInstanceOf(Mission::class, $mission);
        self::assertSame(['Écarter le doublon', 'Contrôler les factures'], array_column($mission->getTasks(), 'title'));
        self::assertSame('Janvier, de la pièce à l’écriture', $mission->getTitle());
        self::assertSame([AssistanceLevel::Guided, AccountingBehaviour::Propose, true], [$mission->getAssistanceLevel(), $mission->getBehaviour(), $mission->isGuidedEntryOpen()]);
        $given = $client->getContainer()->get(MissionAssignmentRepository::class)->findByMission($mission);
        self::assertSame(['2099-12-04 18:00'], array_map(static fn ($one): string => $one->getDueAt()->format('Y-m-d H:i'), $given));
    }
}
