<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Entity\Regulatory\RegulatoryValue;
use App\Enum\Audit\AuditAction;
use App\Enum\Regulatory\RegulatoryOrigin;
use App\Repository\Regulatory\RegulatoryValueRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Installs the millésime files of the repository (config/regulatory/<année>.yaml) in the
 * database, the oldest first (app:regulatory:sync, run by every deployment). Only ever adds rows:
 *
 * - an entry the database does not hold is added;
 * - an entry already installed from that file as it is written, or that says word for word what
 *   is in force for its date (a stable rule both millésimes carry), is left alone;
 * - an entry the file changed is added again, replacing the earlier row of that date for every
 *   reading, which stays in the history;
 * - an entry whose date an administrator has given a value since is left to the administrator,
 *   and reported: the file never overwrites a person.
 *
 * Every file is read and checked before anything is written: one fault anywhere, nothing installed.
 */
final readonly class RegulatorySync
{
    public const string ACTOR = 'app:regulatory:sync';

    public function __construct(
        private RegulatoryValueRepository $values,
        private EntityManagerInterface $entityManager,
        private AuditTrail $auditTrail,
        #[Autowire('%kernel.project_dir%/config/regulatory')]
        private string $directory,
    ) {
    }

    /**
     * @return list<RegulatorySyncReport> one per file, the oldest millésime first
     *
     * @throws InvalidRegulatoryFile
     */
    public function run(\DateTimeImmutable $now, bool $dryRun = false): array
    {
        $files = [];
        foreach ($this->files() as $millesime => $path) {
            $files[$millesime] = RegulatoryFile::parse((string) file_get_contents($path), basename($path), $millesime);
        }

        /** @var array<string, list<RegulatoryValue>> $rowsByIdentity key@date => rows, oldest first */
        $rowsByIdentity = [];
        foreach ($this->values->findAllInReadingOrder() as $row) {
            $rowsByIdentity[self::identity($row->getKey(), $row->getEffectiveFrom())][] = $row;
        }

        $reports = [];
        foreach ($files as $millesime => $entries) {
            $report = new RegulatorySyncReport($millesime, $millesime.'.yaml');
            foreach ($entries as $entry) {
                $identity = self::identity($entry->key, $entry->effectiveFrom);
                $rows = $rowsByIdentity[$identity] ?? [];
                $line = $entry->key.' au '.$entry->effectiveFrom->format('d/m/Y');
                $current = [] === $rows ? null : $rows[\count($rows) - 1];
                if (self::installed($entry, $millesime, $rows) || (null !== $current && $entry->sameAs($current))) {
                    ++$report->unchanged;
                    continue;
                }
                if (null !== $current && RegulatoryOrigin::Administrator === $current->getOrigin()) {
                    if ($entry->sameValueAs($current)) {
                        ++$report->unchanged;
                    } else {
                        $report->kept[] = $line;
                    }
                    continue;
                }
                $row = RegulatoryValue::fromFile($entry, $millesime, $now);
                if (!$dryRun) {
                    $this->entityManager->persist($row);
                }
                $rowsByIdentity[$identity][] = $row;
                if (null === $current) {
                    $report->added[] = $line;
                } else {
                    $report->updated[] = $line;
                }
            }
            if (!$dryRun && $report->changed()) {
                $this->auditTrail->record(
                    AuditAction::RegulatoryFileInstalled,
                    self::ACTOR,
                    'regulatory_file',
                    (string) $millesime,
                    \sprintf('Millésime %d : %s', $millesime, $report->summary()),
                    ['added' => $report->added, 'updated' => $report->updated, 'kept' => $report->kept],
                );
            }
            $reports[] = $report;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $reports;
    }

    /** @return array<int, string> millésime => path, the oldest first */
    private function files(): array
    {
        $files = [];
        foreach (glob($this->directory.'/*.yaml') ?: [] as $path) {
            if (1 === preg_match('/^(\d{4})\.yaml$/', basename($path), $match)) {
                $files[(int) $match[1]] = $path;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Whether the last row this file wrote for that date says what the entry says.
     *
     * @param list<RegulatoryValue> $rows
     */
    private static function installed(RegulatoryFileEntry $entry, int $millesime, array $rows): bool
    {
        $fromFile = array_values(array_filter($rows, static fn (RegulatoryValue $row): bool => RegulatoryOrigin::File === $row->getOrigin() && $row->getMillesime() === $millesime));

        return [] !== $fromFile && $entry->sameAs($fromFile[\count($fromFile) - 1]);
    }

    private static function identity(string $key, \DateTimeImmutable $effectiveFrom): string
    {
        return $key.'@'.$effectiveFrom->format('Y-m-d');
    }
}
