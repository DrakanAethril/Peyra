<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Enum\Regulatory\RegulatoryDomain;

/**
 * The list of Paramètres réglementaires for one millésime (design-ux.md § 3.3: « filtre par
 * millésime et domaine »): every version that applies on at least one day of the year - the SMIC of
 * January and the SMIC of June are two lines -, by key. Pure.
 */
final readonly class RegulatoryListing
{
    /** @param list<RegulatoryVersion> $rows */
    private function __construct(public array $rows, public int $total, public int $unverified)
    {
    }

    /**
     * @param array<string, RegulatoryHistory> $histories by key, in key order
     * @param string                           $search    part of a key or of a label, any case
     */
    public static function of(array $histories, int $year, ?RegulatoryDomain $domain, string $search): self
    {
        $needle = mb_strtolower(trim($search));
        $rows = [];
        foreach ($histories as $key => $history) {
            if (null !== $domain && RegulatoryDomain::ofKey($key) !== $domain) {
                continue;
            }
            foreach ($history->during($year) as $version) {
                if ('' !== $needle && !str_contains(mb_strtolower($key.' '.$version->value->getLabel()), $needle)) {
                    continue;
                }
                $rows[] = $version;
            }
        }
        $unverified = \count(array_filter($rows, static fn (RegulatoryVersion $v): bool => !$v->value->isVerified()));

        return new self($rows, \count($rows), $unverified);
    }

    /**
     * The millésimes the list offers, newest first: from the oldest file installed to the latest
     * year a value takes effect in (a change already known for next year has its millésime). A
     * rule stable since 2019 does not open 2019: it is read in each year it still applies.
     *
     * @param array<string, RegulatoryHistory> $histories
     *
     * @return list<int>
     */
    public static function years(array $histories): array
    {
        $first = null;
        $last = null;
        foreach ($histories as $history) {
            foreach ($history->versions as $version) {
                $millesime = $version->value->getMillesime();
                $effective = (int) $version->value->getEffectiveFrom()->format('Y');
                $first = null === $first ? $millesime : min($first, $millesime);
                $last = max($last ?? $millesime, $millesime, $effective);
            }
        }

        return null === $first || null === $last ? [] : range($last, $first);
    }

    /**
     * How many lines each domain chip would show for that year and search.
     *
     * @param array<string, RegulatoryHistory> $histories
     *
     * @return array<string, int> '' (all) and each domain's value
     */
    public static function counts(array $histories, int $year, string $search): array
    {
        $counts = ['' => self::of($histories, $year, null, $search)->total];
        foreach (RegulatoryDomain::cases() as $domain) {
            $counts[$domain->value] = self::of($histories, $year, $domain, $search)->total;
        }

        return $counts;
    }
}
