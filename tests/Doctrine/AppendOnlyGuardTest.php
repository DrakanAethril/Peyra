<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\AppendOnlyRefused;
use App\Entity\Audit\AuditEvent;
use App\Entity\Regulatory\RegulatoryValue;
use App\Enum\Audit\AuditAction;
use App\Service\Audit\AuditTrail;
use App\Tests\Support\RegulatoryFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An append-only row is never changed nor deleted (README of the plan, § 8): the flush that tries
 * fails whole, before anything reaches the database.
 */
final class AppendOnlyGuardTest extends KernelTestCase
{
    public function testARegulatoryValueIsNeverChanged(): void
    {
        $value = $this->stored();
        new \ReflectionProperty(RegulatoryValue::class, 'value')->setValue($value, '"12.50"');

        $this->expectException(AppendOnlyRefused::class);
        $this->em()->flush();
    }

    public function testARegulatoryValueIsNeverDeleted(): void
    {
        $value = $this->stored();
        $this->em()->remove($value);

        $this->expectException(AppendOnlyRefused::class);
        $this->em()->flush();
    }

    public function testAnAuditLineIsNeverChangedNorDeleted(): void
    {
        $event = self::getContainer()->get(AuditTrail::class)->record(AuditAction::RegulatoryValueAdded, 'test', 'regulatory_key', 'social.smic_hourly', 'SMIC');
        $this->em()->flush();
        $this->em()->remove($event);

        $this->expectException(AppendOnlyRefused::class);
        $this->em()->flush();
    }

    public function testAddingIsAllowed(): void
    {
        $this->stored();
        $this->em()->persist(RegulatoryFixtures::fromFile('social.smic_hourly', '2026-06-01', '12.31'));
        $this->em()->flush();

        self::assertCount(2, $this->em()->getRepository(RegulatoryValue::class)->findAll());
        self::assertCount(0, $this->em()->getRepository(AuditEvent::class)->findAll());
    }

    private function stored(): RegulatoryValue
    {
        $value = RegulatoryFixtures::fromFile('social.smic_hourly', '2026-01-01', '12.02');
        $this->em()->persist($value);
        $this->em()->flush();

        return $value;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
