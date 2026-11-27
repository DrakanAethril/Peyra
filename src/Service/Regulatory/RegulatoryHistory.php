<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Entity\Regulatory\RegulatoryValue;

/**
 * The dated values of one key, read: one version per effective date, the last row written for a
 * date replacing the earlier ones, each version applying until the eve of the next date. Pure, over
 * rows given in the repository's reading order (date, then writing order).
 */
final readonly class RegulatoryHistory
{
    /** @param list<RegulatoryVersion> $versions oldest date first */
    private function __construct(public string $key, public array $versions)
    {
    }

    /** @param non-empty-list<RegulatoryValue> $rows of one key, in reading order */
    public static function of(array $rows): self
    {
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row->getEffectiveFrom()->format('Y-m-d')][] = $row;
        }
        ksort($byDate);
        $dates = array_keys($byDate);
        $versions = [];
        foreach ($dates as $index => $date) {
            $rowsOfDate = $byDate[$date];
            $current = array_pop($rowsOfDate);
            $next = $dates[$index + 1] ?? null;
            $until = null === $next ? null : (new \DateTimeImmutable((string) $next))->modify('-1 day');
            $versions[] = new RegulatoryVersion($current, array_reverse($rowsOfDate), $until);
        }

        return new self($rows[0]->getKey(), $versions);
    }

    /**
     * Rows of several keys, in reading order, as one history per key.
     *
     * @param list<RegulatoryValue> $rows
     *
     * @return array<string, self> by key, in key order
     */
    public static function byKey(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->getKey()][] = $row;
        }
        $histories = [];
        foreach ($grouped as $key => $rowsOfKey) {
            $histories[(string) $key] = self::of($rowsOfKey);
        }

        return $histories;
    }

    public function inForceOn(\DateTimeImmutable $day): ?RegulatoryValue
    {
        foreach (array_reverse($this->versions) as $version) {
            if ($version->value->getEffectiveFrom() <= $day) {
                return $version->value;
            }
        }

        return null;
    }

    /** The version of the latest effective date: what a new dated value starts from. */
    public function latest(): RegulatoryValue
    {
        return $this->versions[\count($this->versions) - 1]->value;
    }

    public function versionOf(\DateTimeImmutable $day): ?RegulatoryVersion
    {
        foreach ($this->versions as $version) {
            if ($version->value->getEffectiveFrom()->format('Y-m-d') === $day->format('Y-m-d')) {
                return $version;
            }
        }

        return null;
    }

    /** @return list<RegulatoryVersion> the versions applying on at least one day of the year */
    public function during(int $year): array
    {
        return array_values(array_filter($this->versions, static fn (RegulatoryVersion $v): bool => $v->appliesDuring($year)));
    }

    public function correctionCount(): int
    {
        return array_sum(array_map(static fn (RegulatoryVersion $v): int => \count($v->corrected), $this->versions));
    }
}
