<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The reference charts Peyra ships (`fixtures/pcg/charts.yaml` and its CSV files): the PCG 2026,
 * the PCG 2025 and the earlier one. Read once and checked whole - a fault names its file and its
 * line, and no chart is served. The files never change while the application runs, so the charts
 * are kept for the life of the worker.
 */
final class ReferenceCharts
{
    /** @var list<ReferenceChart>|null the latest first */
    private ?array $charts = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/pcg')]
        private readonly string $directory,
    ) {
    }

    /** @return list<ReferenceChart> the latest first */
    public function all(): array
    {
        return $this->charts ??= $this->load();
    }

    public function get(string $code): ?ReferenceChart
    {
        foreach ($this->all() as $chart) {
            if ($chart->code === $code) {
                return $chart;
            }
        }

        return null;
    }

    /** The chart for an exercise starting that day: the latest whose date of effect has come. */
    public function forExerciseStartingOn(\DateTimeImmutable $start): ReferenceChart
    {
        foreach ($this->all() as $chart) {
            if ($chart->appliesToExerciseStartingOn($start)) {
                return $chart;
            }
        }

        throw new \LogicException('No reference chart applies before '.$start->format('Y-m-d').'.');
    }

    /** @return list<ReferenceChart> */
    private function load(): array
    {
        $manifest = $this->directory.'/charts.yaml';
        $data = Yaml::parseFile($manifest);
        if (!\is_array($data) || !\is_array($data['classes'] ?? null) || !\is_array($data['charts'] ?? null)) {
            throw new \InvalidArgumentException($manifest.' : « classes » et « charts » sont attendus.');
        }
        $classLabels = [];
        foreach ($data['classes'] as $digit => $label) {
            if (!\is_string($label) || 1 !== preg_match('/^[1-8]$/', (string) $digit)) {
                throw new \InvalidArgumentException($manifest.' : classes : un intitulé par chiffre de 1 à 8.');
            }
            $classLabels[(int) $digit] = $label;
        }
        $charts = [];
        foreach ($data['charts'] as $index => $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException(\sprintf('%s : charts[%s] : un dictionnaire est attendu.', $manifest, $index));
            }
            $where = \sprintf('%s : charts[%s]', $manifest, $index);
            $appliesFrom = $entry['applies_from'] ?? null;
            $charts[] = new ReferenceChart(
                self::text($entry, 'code', $where),
                self::text($entry, 'label', $where),
                self::text($entry, 'regulation', $where),
                null === $appliesFrom ? null : self::date($appliesFrom, $where.'.applies_from'),
                self::text($entry, 'source', $where),
                self::date($entry['consulted'] ?? null, $where.'.consulted'),
                true === ($entry['verified'] ?? null),
                self::text($entry, 'note', $where),
                $this->accounts($this->directory.'/'.self::text($entry, 'file', $where)),
                $classLabels,
            );
        }
        usort($charts, static fn (ReferenceChart $a, ReferenceChart $b): int => ($b->appliesFrom?->format('Y-m-d') ?? '') <=> ($a->appliesFrom?->format('Y-m-d') ?? ''));

        return $charts;
    }

    /** @return list<ReferenceAccount> */
    private function accounts(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new \InvalidArgumentException($path.' : fichier introuvable.');
        }
        $accounts = [];
        $line = 0;
        try {
            while (false !== ($row = fgetcsv($handle, null, ';', '"', ''))) {
                ++$line;
                if (1 === $line) {
                    if (['numero', 'intitule', 'classe', 'niveau', 'verifie'] !== $row) {
                        throw new \InvalidArgumentException($path.' : l’en-tête numero;intitule;classe;niveau;verifie est attendu.');
                    }
                    continue;
                }
                if (5 !== \count($row) || !\is_string($row[0]) || 1 !== preg_match('/^[1-8]\d{1,5}$/', $row[0]) || '' === trim((string) $row[1])) {
                    throw new \InvalidArgumentException(\sprintf('%s, ligne %d : un numéro de 2 à 6 chiffres et un intitulé sont attendus.', $path, $line));
                }
                $accounts[] = new ReferenceAccount($row[0], trim((string) $row[1]));
            }
        } finally {
            fclose($handle);
        }
        $numbers = array_map(static fn (ReferenceAccount $a): string => $a->number, $accounts);
        if (\count($numbers) !== \count(array_unique($numbers))) {
            throw new \InvalidArgumentException($path.' : un numéro y figure deux fois.');
        }
        usort($accounts, static fn (ReferenceAccount $a, ReferenceAccount $b): int => strcmp($a->number, $b->number));

        return $accounts;
    }

    /** @param array<mixed> $entry */
    private static function text(array $entry, string $key, string $where): string
    {
        $value = $entry[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException($where.'.'.$key.' : un texte est attendu.');
        }

        return trim($value);
    }

    private static function date(mixed $value, string $where): \DateTimeImmutable
    {
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($where.' : une date AAAA-MM-JJ entre guillemets est attendue.');
        }

        return $date;
    }
}
