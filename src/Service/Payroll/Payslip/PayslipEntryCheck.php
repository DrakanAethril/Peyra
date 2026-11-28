<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\Assistance\Assistance;

/**
 * A payslip established by the student (step 4.4c; design-ux.md § 4.7, levels B, A and 0), pure: every
 * open cell of its lines (PayslipLineCells::open) is the student's to type - one not typed yet is
 * `missing` -, and where the level signals gaps (B and A) a typed cell whose value is not the tool's
 * calculation (the same payslip with nothing forced, `reference`) is a `gap`, never told its value. A
 * line added by hand that the calculation does not carry is a gap in every cell typed. A line is
 * complete once none of its cells is missing: only then are its amounts shown, and the totals once
 * every line is.
 */
final readonly class PayslipEntryCheck
{
    public const string MISSING = 'missing';
    public const string GAP = 'gap';

    /** @param array<string, string> $cells state by « code.field », for the cells missing or in gap only */
    private function __construct(public array $cells)
    {
    }

    /** @param list<PayrollItemSpec> $plan */
    public static function of(Payslip $payslip, ?Payslip $reference, array $plan): self
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
                    $cells[$key] = self::MISSING;
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

        return new self($cells);
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

    /** Why the payslip may not be validated at that level, in French, or null: a cell not typed, or (B) a cell in gap. */
    public function validationRefusal(Assistance $assistance): ?string
    {
        $missing = $this->missing();
        if ($missing > 0) {
            return 1 === $missing ? 'Une cellule du bulletin reste à saisir.' : \sprintf('%d cellules du bulletin restent à saisir.', $missing);
        }
        $gaps = $this->gaps();
        if ($assistance->blocksPayslipGaps() && $gaps > 0) {
            return 1 === $gaps ? 'Une cellule diffère du calcul de l’outil : revoyez-la avant de valider.' : \sprintf('%d cellules diffèrent du calcul de l’outil : revoyez-les avant de valider.', $gaps);
        }

        return null;
    }
}
