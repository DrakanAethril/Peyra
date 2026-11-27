<?php

declare(strict_types=1);

namespace App\Tests\Service\Regulatory;

use App\Entity\Audit\AuditEvent;
use App\Entity\Identity\User;
use App\Entity\Regulatory\RegulatoryValue;
use App\Enum\Audit\AuditAction;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Regulatory\RegulatoryValueRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Regulatory\InvalidRegulatoryFile;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Regulatory\RegulatorySync;
use App\Tests\Support\RegulatoryFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * app:regulatory:sync on a directory of its own: it only ever adds rows, is harmless to run again,
 * replaces a value the file changed without losing the older one, and never overwrites a person.
 */
final class RegulatorySyncTest extends KernelTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->directory = sys_get_temp_dir().'/peyra-regulatory-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testInstallsThenLeavesAloneThenReplacesWhatTheFileChanged(): void
    {
        $this->write(2025, [$this->entry('social.smic_hourly', '11.88', '2024-11-01'), $this->entry('fiscal.late_payment.recovery_indemnity', '40', '2013-01-01')]);
        $this->write(2026, [$this->entry('social.smic_hourly', '12.02', '2026-01-01'), $this->entry('social.smic_hourly', '12.31', '2026-06-01'), $this->entry('fiscal.late_payment.recovery_indemnity', '40', '2013-01-01')]);

        $reports = $this->sync()->run(new \DateTimeImmutable('2026-11-27 10:00'));
        self::assertSame([2025, 2026], array_map(static fn ($r) => $r->millesime, $reports));
        self::assertSame(['social.smic_hourly au 01/11/2024', 'fiscal.late_payment.recovery_indemnity au 01/01/2013'], $reports[0]->added);
        self::assertSame(['social.smic_hourly au 01/01/2026', 'social.smic_hourly au 01/06/2026'], $reports[1]->added);
        self::assertSame(1, $reports[1]->unchanged, 'A rule both files carry, word for word, is written once.');
        self::assertCount(4, $this->rows());

        $again = $this->sync()->run(new \DateTimeImmutable('2026-11-28 10:00'));
        self::assertSame('2 inchangées', $again[0]->summary());
        self::assertSame('3 inchangées', $again[1]->summary());
        self::assertCount(4, $this->rows());

        $this->write(2026, [$this->entry('social.smic_hourly', '12.02', '2026-01-01'), $this->entry('social.smic_hourly', '12.35', '2026-06-01'), $this->entry('fiscal.late_payment.recovery_indemnity', '40', '2013-01-01')]);
        $changed = $this->sync()->run(new \DateTimeImmutable('2026-11-29 10:00'));
        self::assertSame(['social.smic_hourly au 01/06/2026'], $changed[1]->updated);
        self::assertCount(5, $this->rows(), 'The older row stays in the history.');
        self::assertSame('12.35', $this->reader()->at(new \DateTimeImmutable('2026-07-01'))->decimal('social.smic_hourly')->toString());
        self::assertSame('12.02', $this->reader()->at(new \DateTimeImmutable('2026-05-31'))->decimal('social.smic_hourly')->toString());
        self::assertSame('11.88', $this->reader()->at(new \DateTimeImmutable('2025-12-31'))->decimal('social.smic_hourly')->toString());
        self::assertFalse($this->reader()->at(new \DateTimeImmutable('2024-10-31'))->has('social.smic_hourly'));

        $events = $this->events();
        self::assertSame(['Millésime 2026 : 1 mise à jour, 2 inchangées', 'Millésime 2026 : 2 ajoutées, 1 inchangée', 'Millésime 2025 : 2 ajoutées'], array_map(static fn (AuditEvent $e) => $e->getSummary(), $events));
        self::assertSame(AuditAction::RegulatoryFileInstalled, $events[0]->getAction());
        self::assertSame(RegulatorySync::ACTOR, $events[0]->getActorName());
        self::assertNull($events[0]->getActor());
    }

    public function testNeverOverwritesAnAdministrator(): void
    {
        $this->write(2026, [$this->entry('social.pss_monthly', '4000', '2026-01-01')]);
        $this->sync()->run(new \DateTimeImmutable('2026-11-27 10:00'));
        $file = $this->rows()[0];
        $this->em()->persist(RegulatoryFixtures::byAdministrator($file, '2026-01-01', '4005', $this->administrator()));
        $this->em()->flush();

        $this->write(2026, [$this->entry('social.pss_monthly', '4010', '2026-01-01')]);
        $reports = $this->sync()->run(new \DateTimeImmutable('2026-11-28 10:00'));

        self::assertSame(['social.pss_monthly au 01/01/2026'], $reports[0]->kept);
        self::assertCount(2, $this->rows());
        self::assertSame('4005', $this->reader()->at(new \DateTimeImmutable('2026-03-01'))->decimal('social.pss_monthly')->toString());
    }

    public function testAFaultAnywhereInstallsNothing(): void
    {
        $this->write(2025, [$this->entry('social.smic_hourly', '11.88', '2024-11-01')]);
        file_put_contents($this->directory.'/2026.yaml', "millesime: 2026\nentries:\n  - key: social.smic\n    verified: true\n");

        try {
            $this->sync()->run(new \DateTimeImmutable('2026-11-27 10:00'));
            self::fail('The 2026 file has faults.');
        } catch (InvalidRegulatoryFile $error) {
            self::assertSame('2026.yaml', $error->fileName);
        }
        self::assertSame([], $this->rows(), 'Not even the valid 2025 file.');
    }

    public function testADryRunWritesNothing(): void
    {
        $this->write(2026, [$this->entry('social.smic_hourly', '12.02', '2026-01-01')]);
        $reports = $this->sync()->run(new \DateTimeImmutable('2026-11-27 10:00'), dryRun: true);

        self::assertSame(['social.smic_hourly au 01/01/2026'], $reports[0]->added);
        self::assertSame([], $this->rows());
        self::assertSame([], $this->events());
    }

    public function testOnlyYearFilesAreRead(): void
    {
        $this->write(2026, [$this->entry('social.smic_hourly', '12.02', '2026-01-01')]);
        file_put_contents($this->directory.'/brouillon.yaml', 'ceci: "n’est pas un millésime"');

        self::assertSame([2026], array_map(static fn ($r) => $r->millesime, $this->sync()->run(new \DateTimeImmutable('2026-11-27 10:00'))));
    }

    /** @return array<string, string|bool> */
    private function entry(string $key, string $value, string $effectiveFrom): array
    {
        return ['key' => $key, 'label' => 'Libellé de '.$key, 'value' => $value, 'unit' => 'EUR', 'effective_from' => $effectiveFrom, 'source_url' => 'https://www.urssaf.fr/', 'consulted_on' => '2026-11-27', 'verified' => true];
    }

    /** @param list<array<string, string|bool>> $entries values as YAML numbers */
    private function write(int $millesime, array $entries): void
    {
        $yaml = "millesime: {$millesime}\nentries:\n";
        foreach ($entries as $entry) {
            $yaml .= '  - key: '.(string) $entry['key']."\n";
            foreach ($entry as $field => $value) {
                if ('key' !== $field) {
                    $yaml .= '    '.$field.': '.(\is_bool($value) ? ($value ? 'true' : 'false') : $value)."\n";
                }
            }
        }
        file_put_contents($this->directory.'/'.$millesime.'.yaml', $yaml);
    }

    private function sync(): RegulatorySync
    {
        $container = self::getContainer();

        return new RegulatorySync($container->get(RegulatoryValueRepository::class), $this->em(), $container->get(AuditTrail::class), $this->directory);
    }

    private function reader(): RegulatoryReader
    {
        return new RegulatoryReader(self::getContainer()->get(RegulatoryValueRepository::class));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return list<RegulatoryValue> */
    private function rows(): array
    {
        return self::getContainer()->get(RegulatoryValueRepository::class)->findAllInReadingOrder();
    }

    /** @return list<AuditEvent> newest first */
    private function events(): array
    {
        return $this->em()->getRepository(AuditEvent::class)->findBy([], ['id' => 'DESC']);
    }

    private function administrator(): User
    {
        $user = new User('uidNumber:900', 'prof.test', 'Prof Test', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher'], new \DateTimeImmutable('2026-01-01'));
        $this->em()->persist($user);

        return $user;
    }
}
