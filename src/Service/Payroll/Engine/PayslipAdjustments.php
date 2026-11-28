<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollRateSource;
use App\Service\Payroll\Plan\PayrollItemSpec;

/**
 * What was changed by hand on one payslip (step 4.4b), à la manière de Sage: rubriques removed from
 * it, rubriques added to it - a rubrique of the plan its conditions leave out, or one removed before -,
 * and values forced on a line (PayslipForcing), by code. The engine still calculates everything else,
 * and what follows a forced value (the gross pay, the bases read from it, the net) follows it.
 * Stored on the payslip as toArray() writes it; codes of digits are integer keys in PHP.
 */
final readonly class PayslipAdjustments
{
    /**
     * @param list<string>                      $removed
     * @param list<string>                      $added
     * @param array<array-key, PayslipForcing>  $forced  by code
     */
    public function __construct(
        public array $removed = [],
        public array $added = [],
        public array $forced = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return [] === $this->removed && [] === $this->added && [] === $this->forced;
    }

    public function removes(string $code): bool
    {
        return \in_array($code, $this->removed, true);
    }

    public function adds(string $code): bool
    {
        return \in_array($code, $this->added, true);
    }

    public function forcing(string $code): ?PayslipForcing
    {
        return $this->forced[$code] ?? null;
    }

    /** The same adjustments, that rubrique removed (and no longer added nor forced). */
    public function withRemoved(string $code): self
    {
        $forced = $this->forced;
        unset($forced[$code]);

        return new self(array_values(array_unique([...$this->removed, $code])), array_values(array_diff($this->added, [$code])), $forced);
    }

    /** The same adjustments, that rubrique put back on the payslip: no longer removed, or added. */
    public function withAdded(string $code): self
    {
        if ($this->removes($code)) {
            return new self(array_values(array_diff($this->removed, [$code])), $this->added, $this->forced);
        }

        return new self($this->removed, $this->adds($code) ? $this->added : [...$this->added, $code], $this->forced);
    }

    /** @param array<array-key, PayslipForcing> $forced */
    public function withForced(array $forced): self
    {
        return new self($this->removed, $this->added, array_filter($forced, static fn (PayslipForcing $f): bool => !$f->isEmpty()));
    }

    /** Whether the payslip takes that rubrique: not removed, and its condition of status met or added by hand. */
    public function takes(PayrollItemSpec $spec, bool $executive): bool
    {
        return !$this->removes($spec->code) && ($spec->status->holds($executive) || $this->adds($spec->code));
    }

    /**
     * A rubrique's line once adjusted by hand: an empty line for a rubrique added that had nothing to
     * calculate, the values forced applied. The general reduction is never forced: it is the year's
     * calculation less what was made (step 4.3c), and a forced month would put the year wrong.
     */
    public function apply(PayrollItemSpec $spec, ?PayslipRow $row): ?PayslipRow
    {
        if (null === $row && $this->adds($spec->code)) {
            $row = PayslipRow::empty($spec);
        }
        $forcing = $this->forcing($spec->code);
        if (null === $row || null === $forcing || PayrollRateSource::GeneralReduction === $spec->employerRate->getSource()) {
            return $row;
        }

        return $forcing->apply($row, PayrollItemKind::Deduction === $spec->kind || $spec->subtracts);
    }

    /** @return array{removed: list<string>, added: list<string>, forced: array<array-key, array<string, string>>} codes of digits are integer keys */
    public function toArray(): array
    {
        $forced = [];
        foreach ($this->forced as $code => $forcing) {
            $forced[(string) $code] = $forcing->toArray();
        }

        return ['removed' => $this->removed, 'added' => $this->added, 'forced' => $forced];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $codes = static fn (mixed $list): array => \is_array($list) ? array_values(array_map(static fn (mixed $c): string => \is_scalar($c) ? (string) $c : '', $list)) : [];
        $forced = [];
        foreach (\is_array($data['forced'] ?? null) ? $data['forced'] : [] as $code => $values) {
            if (\is_array($values)) {
                $forced[(string) $code] = PayslipForcing::fromArray($values);
            }
        }

        return new self($codes($data['removed'] ?? []), $codes($data['added'] ?? []), $forced);
    }
}
