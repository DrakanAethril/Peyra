<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mission;

use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\DistributionCopy;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\ClassLevel;
use App\Service\Mission\MissionAssignments;
use App\Service\Mission\MissionSheet;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The modules a mission closes do not exist for the student (step 2.9a): Inès's copy of prof.durand's
 * template plays a mission opening Pièces and Comptabilité - the rail draws those, Ventes and Paie et
 * social answer « Page introuvable », the dashboard and the Système d'information stay.
 */
final class MissionModulesTest extends WebTestCase
{
    use SavesDossiers;
    use SignsIn;

    public function testTheRailAndTheAddressesFollowTheMission(): void
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
        $year = new SchoolYear(new \DateTimeImmutable('2030-09-01'), new \DateTimeImmutable('2031-08-31'), $at);
        $class = new SchoolClass($year, 'BTS CG 1 bis', ClassLevel::First, $at);
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
        $mission = $sheet->create($template, 'Janvier', $durand, $at);
        $sheet->describe($mission, 'Janvier', '', ['documents', 'accounting'], $durand);
        $client->getContainer()->get(MissionAssignments::class)->give($mission, $distribution, $at->modify('+1 week'), $durand, $at);
        $id = (int) $copy->getId();

        $crawler = $client->request('GET', '/d/'.$id);
        self::assertResponseIsSuccessful();
        $rail = $crawler->filter('.px-rail')->text();
        self::assertStringContainsString('Comptabilité', $rail);
        self::assertStringContainsString('Pièces', $rail);
        self::assertStringNotContainsString('Paie et social', $rail);
        self::assertStringNotContainsString('Ventes', $rail);
        self::assertStringNotContainsString('Banque', $rail);
        foreach (['/sales/customers' => 404, '/payroll/employees' => 404, '/bank/statements' => 404, '/payments/receipts' => 404, '/documents' => 200, '/company' => 200] as $path => $status) {
            $client->request('GET', '/d/'.$id.$path);
            self::assertResponseStatusCodeSame($status, $path);
        }
    }
}
