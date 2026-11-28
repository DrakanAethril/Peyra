<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Plan\PayrollItemSpec;

/**
 * The cells of a payslip checked against the tool's calculation, pure. Where the student establishes the
 * payslip (level 0, `typedByStudent`) every open cell of its lines (PayslipLineCells::open) is theirs to
 * type - one not typed yet is `missing` -, and no comparison is made: the calculation serves the
 * correction. Where the tool calculates (G, B, A; review 4.7) a cell is the calculation's until forced by
 * hand, and a forced cell whose value is not the tool's calculation (the same payslip with nothing forced,
 * `reference`) is a `gap`, never told its value - a fault of the « Contrôles » card (PayslipControls). A
 * line added by hand that the calculation does not carry is a gap in every cell forced. A line is complete
 * once none of its cells is missing: only then are its amounts shown, and the totals once every line is.
 */
final readonly class PayslipEntryCheck
{
    public const string MISSING = 'missing';
    public const string GAP = 'gap';

    /** @param array<string, string> $cells state by « code.field », for the cells missing or in gap only */
    private function __construct(public array $cells, public bool $typedByStudent)
    {
    }

    /** @param list<PayrollItemSpec> $plan */
    public static function of(Payslip $payslip, ?Payslip $reference, array $plan, bool $typedByStudent): self
    {
        $specs = [];
        foreach ($plan as $spec) {
            $specs[$spec->code] = $spec;
        }
        $cells = [];
        foreach ($payslip->rows as $row) {
            $calculated = $reference?->row($row->code);
            foreach (PayslipLineCells::open($row, $specs[$row->code] ?? null) as $field) {
                $key = $row->code.'.'.$field;
                if (!$row->isForced($field)) {
                    if ($typedByStudent) {
                        $cells[$key] = self::MISSING;
                    }
                    continue;
                }
                if (null === $reference) {
                    continue;
                }
                $typed = PayslipLineCells::value($row, $field);
                $expected = null === $calculated ? null : PayslipLineCells::value($calculated, $field);
                if (null === $typed || null === $expected || 0 !== $typed->compareTo($expected)) {
                    $cells[$key] = self::GAP;
                }
            }
        }

        return new self($cells, $typedByStudent);
    }

    public function state(string $key): ?string
    {
        return $this->cells[$key] ?? null;
    }

    public function missing(): int
    {
        return \count(array_filter($this->cells, static fn (string $state): bool => self::MISSING === $state));
    }

    public function gaps(): int
    {
        return \count(array_filter($this->cells, static fn (string $state): bool => self::GAP === $state));
    }

    public function isComplete(): bool
    {
        return 0 === $this->missing();
    }

    public function isLineComplete(string $code): bool
    {
        foreach ($this->cells as $key => $state) {
            if (self::MISSING === $state && str_starts_with($key, $code.'.')) {
                return false;
            }
        }

        return true;
    }

    /** Why a payslip the student establishes may not be validated, in French, or null: a cell not typed. */
    public function validationRefusal(): ?string
    {
        $missing = $this->missing();

        return 0 === $missing ? null : (1 === $missing ? 'Une cellule du bulletin reste à saisir.' : \sprintf('%d cellules du bulletin restent à saisir.', $missing));
    }
}
