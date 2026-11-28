<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Entity\Payroll\PayrollBody;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipLine;
use App\Service\Shared\Money;

/**
 * The payroll states of one month (step 4.5; design-ux.md § 3.2 « Journal de paie, Livre de paie, État
 * des charges »), read on its validated payslips alone - never on a calculation again -, pure:
 *
 * - the journal de paie: each rubrique summed over the month's payslips, by family;
 * - the livre de paie: each payslip's figures, one line per employee;
 * - the cotisations by body: each body's rubriques and what is owed to it (the general reduction cut
 *   in its two shares);
 * - the état des charges: the contributions by line of the clarified payslip (by nature);
 * - the détail of a total: the payslips a rubrique, a body or a line comes from.
 *
 * Every total is the sum of the payslips it shows (`numbers`).
 */
final readonly class PayrollMonthStatements
{
    /**
     * @param list<PayrollMonthPayslip> $payslips
     * @param list<PayrollAllocation>   $allocations
     */
    private function __construct(
        public \DateTimeImmutable $month,
        public array $payslips,
        public array $allocations,
    ) {
    }

    /** @param list<PayrollMonthPayslip> $payslips the month's validated payslips */
    public static function of(\DateTimeImmutable $month, array $payslips, PayrollChart $chart): self
    {
        return new self($month, $payslips, PayrollAllocations::of($payslips, $chart));
    }

    public function isEmpty(): bool
    {
        return [] === $this->payslips;
    }

    /**
     * The figures under the payslips, summed: gross, contributions, net social, net taxable, tax, net paid, employer's cost.
     *
     * @return array{gross: Money, employee: Money, employer: Money, netSocial: Money, netTaxable: Money, withholding: Money, netPay: Money, cost: Money}
     */
    public function totals(): array
    {
        $payslips = $this->payslips;
        $sum = static fn (callable $figure): Money => Money::sum(array_map(static fn (PayrollMonthPayslip $p): Money => $figure($p), $payslips));

        return [
            'gross' => $sum(static fn (PayrollMonthPayslip $p): Money => $p->payslip->gross),
            'employee' => $sum(static fn (PayrollMonthPayslip $p): Money => $p->payslip->totals->employeeContributions),
            'employer' => $sum(static fn (PayrollMonthPayslip $p): Money => $p->payslip->totals->employerContributions),
            'netSocial' => $sum(static fn (PayrollMonthPayslip $p): Money => $p->payslip->totals->netSocial),
            'netTaxable' => $sum(static fn (PayrollMonthPayslip $p): Money => $p->payslip->totals->netTaxable),
            'withholding' => $sum(static fn (PayrollMonthPayslip $p): Money => $p->payslip->totals->withholdingTax),
            'netPay' => $sum(static fn (PayrollMonthPayslip $p): Money => $p->payslip->totals->netPay),
            'cost' => $sum(static fn (PayrollMonthPayslip $p): Money => $p->payslip->totals->employerCost),
        ];
    }

    /**
     * The journal de paie: each rubrique summed, family by family (gross, contributions, not subject, deductions), by code.
     *
     * @return array<string, list<PayrollStatementRow>> by PayrollItemKind value, in the payslip's order
     */
    public function journal(): array
    {
        $families = [];
        foreach (PayrollItemKind::cases() as $kind) {
            $rows = [];
            foreach ($this->allocations as $allocation) {
                if ($allocation->kind !== $kind) {
                    continue;
                }
                $rows[$allocation->code] ??= new PayrollStatementRow($allocation->code, $allocation->label);
                $rows[$allocation->code]->add($allocation);
            }
            ksort($rows, \SORT_STRING);
            if ([] !== $rows) {
                $families[$kind->value] = array_values($rows);
            }
        }

        return $families;
    }

    /**
     * The contributions by body: its rubriques (by code; a share of the general reduction is its own row) and their total, what is owed to it.
     *
     * @return list<array{key: string, body: ?PayrollBody, rows: list<PayrollStatementRow>, employee: Money, employer: Money, total: Money}>
     */
    public function bodies(): array
    {
        /** @var array<string, array{key: string, body: ?PayrollBody, rows: array<string, PayrollStatementRow>}> $bodies */
        $bodies = [];
        foreach ($this->allocations as $allocation) {
            if (PayrollItemKind::Contribution !== $allocation->kind) {
                continue;
            }
            $key = null === $allocation->body ? '' : PayrollAllocation::bodyKey($allocation->body);
            $bodies[$key] ??= ['key' => $key, 'body' => $allocation->body, 'rows' => []];
            $row = $allocation->code.'|'.$allocation->label;
            $bodies[$key]['rows'][$row] ??= new PayrollStatementRow($allocation->code, $allocation->label);
            $bodies[$key]['rows'][$row]->add($allocation);
        }
        $states = [];
        foreach ($bodies as $state) {
            $rows = $state['rows'];
            ksort($rows, \SORT_STRING);
            $rows = array_values($rows);
            $employee = Money::sum(array_map(static fn (PayrollStatementRow $r): Money => $r->employee, $rows));
            $employer = Money::sum(array_map(static fn (PayrollStatementRow $r): Money => $r->employer, $rows));
            $states[] = ['key' => $state['key'], 'body' => $state['body'], 'rows' => $rows, 'employee' => $employee, 'employer' => $employer, 'total' => $employee->plus($employer)];
        }
        usort($states, static fn (array $a, array $b): int => [null === $a['body'], $a['body']?->getKind()->value ?? '', $a['body']?->getName() ?? ''] <=> [null === $b['body'], $b['body']?->getKind()->value ?? '', $b['body']?->getName() ?? '']);

        return $states;
    }

    /**
     * The état des charges: the contributions by line of the clarified payslip, in its order.
     *
     * @return list<PayrollStatementRow> keyed by the PayslipLine value
     */
    public function charges(): array
    {
        $rows = [];
        foreach (PayslipLine::cases() as $line) {
            foreach ($this->allocations as $allocation) {
                if (PayrollItemKind::Contribution === $allocation->kind && $allocation->line === $line) {
                    $rows[$line->value] ??= new PayrollStatementRow($line->value, $line->label());
                    $rows[$line->value]->add($allocation);
                }
            }
        }

        return array_values($rows);
    }

    /**
     * The détail of a total: each payslip's share of what the filter keeps - a rubrique (`item`), a body
     * (`body`, its key; '' for none) or a line of the clarified payslip (`line`) -, in the payslips' order.
     *
     * @param array{item?: string, body?: string, line?: string} $filter
     *
     * @return list<PayrollStatementRow> keyed by matricule, labelled with the employee's name
     */
    public function detail(array $filter): array
    {
        $rows = [];
        foreach ($this->payslips as $payslip) {
            foreach ($this->allocations as $allocation) {
                if ($allocation->number !== $payslip->number || !self::keeps($allocation, $filter)) {
                    continue;
                }
                $rows[$payslip->number] ??= new PayrollStatementRow($payslip->number, $payslip->name);
                $rows[$payslip->number]->add($allocation);
            }
        }

        return array_values($rows);
    }

    /** @param array{item?: string, body?: string, line?: string} $filter */
    private static function keeps(PayrollAllocation $allocation, array $filter): bool
    {
        if (isset($filter['item']) && $allocation->code !== $filter['item']) {
            return false;
        }
        if (isset($filter['body']) && (PayrollItemKind::Contribution !== $allocation->kind || (null === $allocation->body ? '' : PayrollAllocation::bodyKey($allocation->body)) !== $filter['body'])) {
            return false;
        }

        return !isset($filter['line']) || (PayrollItemKind::Contribution === $allocation->kind && $allocation->line?->value === $filter['line']);
    }
}
