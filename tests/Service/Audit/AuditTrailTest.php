<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Audit\AuditEventRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class AuditTrailTest extends KernelTestCase
{
    public function testALineSaysWhoWhatAndWhenInRealTime(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $martin = new User('uidNumber:901', 'prof.trail', 'Jean-Pierre Martin', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher'], new \DateTimeImmutable('2026-01-01'));
        $em->persist($martin);
        $trail = new AuditTrail($em, new MockClock('2026-11-27 14:02:00'));

        $trail->record(AuditAction::RegulatoryValueAdded, $martin, 'regulatory_key', 'social.smic_hourly', 'SMIC horaire brut : 12,31 €/heure au 01/06/2026', ['correction' => false]);
        $trail->record(AuditAction::RegulatoryFileInstalled, 'app:regulatory:sync', 'regulatory_file', '2026', str_repeat('x', 300));
        $em->flush();

        $lines = self::getContainer()->get(AuditEventRepository::class)->findForSubject('regulatory_key', 'social.smic_hourly');
        self::assertCount(1, $lines);
        self::assertSame('2026-11-27 14:02:00', $lines[0]->getOccurredAt()->format('Y-m-d H:i:s'));
        self::assertSame($martin, $lines[0]->getActor());
        self::assertSame('Jean-Pierre Martin', $lines[0]->getActorName());
        self::assertSame(AuditAction::RegulatoryValueAdded, $lines[0]->getAction());
        self::assertSame('Valeur réglementaire datée ajoutée', $lines[0]->getAction()->label());
        self::assertSame(['correction' => false], $lines[0]->getDetails());

        $system = self::getContainer()->get(AuditEventRepository::class)->findForSubject('regulatory_file', '2026');
        self::assertNull($system[0]->getActor());
        self::assertSame('app:regulatory:sync', $system[0]->getActorName());
        self::assertSame(255, mb_strlen($system[0]->getSummary()), 'A sentence too long is cut, never refused.');
    }

    public function testOnlyTheTrailWritesALine(): void
    {
        $root = \dirname(__DIR__, 3).'/src';
        $writers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && str_contains((string) file_get_contents($file->getPathname()), 'new AuditEvent(')) {
                $writers[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        self::assertSame(['Service/Audit/AuditTrail.php'], $writers);
    }
}
