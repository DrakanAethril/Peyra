<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier;

use App\Entity\Audit\AuditEvent;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Dossier\DossierDateChangeRepository;
use App\Service\Dossier\DossierClock;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\DossierDates;
use App\Tests\Support\SavesDossiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Moving a dossier's date: its history, the audit trail signed by the teacher, the clock that reads it. */
final class DossierDatesTest extends KernelTestCase
{
    use SavesDossiers;

    public function testAMoveIsWrittenToTheHistoryAndTheAuditTrail(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $teacher = new User('uid:durand', 'prof.durand', 'Sophie Durand', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($teacher);
        $dossier = $this->savedDossier(DossierKind::Template, $teacher);
        $dates = $container->get(DossierDates::class);
        $context = $container->get(DossierContext::class);

        $change = $dates->move($dossier, new \DateTimeImmutable('2026-11-30 17:42'), $teacher, new \DateTimeImmutable('2026-11-27 10:15'));
        $entityManager->flush();

        self::assertNotNull($change);
        self::assertSame('2026-11-30', $dossier->getDossierDate()->format('Y-m-d'));
        self::assertSame('2026-11-27 10:15', $dossier->getDateMovedAt()?->format('Y-m-d H:i'));
        $context->enter($dossier);
        self::assertSame('2026-11-30', $container->get(DossierClock::class)->today()->format('Y-m-d'));
        $history = $container->get(DossierDateChangeRepository::class)->findLatest($dossier, 3);
        self::assertCount(1, $history);
        self::assertSame(['2026-03-15', '2026-11-30', 'Sophie Durand'], [$history[0]->getFromDate()->format('Y-m-d'), $history[0]->getToDate()->format('Y-m-d'), $history[0]->getActorName()]);
        $audit = $entityManager->getRepository(AuditEvent::class)->findOneBy(['action' => AuditAction::DossierDateMoved]);
        self::assertInstanceOf(AuditEvent::class, $audit);
        self::assertSame('Date du dossier Menuiserie Vasseur SARL : du 15/03/2026 au 30/11/2026', $audit->getSummary());
    }

    public function testTheSameDayWritesNothing(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $teacher = new User('uid:durand', 'prof.durand', 'Sophie Durand', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $container->get(EntityManagerInterface::class)->persist($teacher);
        $dossier = $this->savedDossier(DossierKind::Template, $teacher);

        self::assertNull($container->get(DossierDates::class)->move($dossier, new \DateTimeImmutable('2026-03-15'), $teacher, new \DateTimeImmutable('2026-11-27')));
        self::assertNull($dossier->getDateMovedAt());
    }
}
