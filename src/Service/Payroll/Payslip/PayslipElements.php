<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Service\Payroll\Engine\PaidLeave;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\Severance;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The elements of the month of one payslip (step 4.4a) as they are kept on MonthlyPayslip: hours or
 * numbers, amounts and percents by code of rubrique, the paid leave taken, the whole days of absence
 * without any pay - and on the last payslip of a contract (step 4.4c) the paid leave acquired and not
 * taken and what a dismissal's or a conventional termination's indemnity is calculated on. Built by PayslipElementsRules from what was typed, or read back from the column;
 * handed to the engine as PayslipVariables.
 */
final readonly class PayslipElements
{
    /**
     * @param array<array-key, BigDecimal> $quantities
     * @param array<array-key, Money>      $amounts
     * @param array<array-key, BigDecimal> $rates
     */
    public function __construct(
        public array $quantities = [],
        public array $amounts = [],
        public array $rates = [],
        public ?PaidLeave $paidLeave = null,
        public int $unpaidDays = 0,
        public ?PaidLeave $compensatoryLeave = null,
        public ?Severance $severance = null,
    ) {
    }

    /** The engine's input; what only a last payslip takes is left out when the month is not the contract's last (`$last`). */
    public function toVariables(?LastPayslipEntries $last = null): PayslipVariables
    {
        $last ??= LastPayslipEntries::none();

        return new PayslipVariables(
            $this->quantities,
            $this->amounts,
            $this->rates,
            $this->paidLeave,
            $this->unpaidDays,
            $last->compensatoryLeave ? $this->compensatoryLeave : null,
            $last->severance && null !== $this->severance ? new Severance($this->severance->referenceSalary, $last->agreed ? $this->severance->agreed : null, $this->severance->previousYearGross) : null,
        );
    }

    /** @return array<string, mixed> what the JSON column keeps: decimals as text, amounts in cents */
    public function toArray(): array
    {
        return [
            'quantities' => array_map(static fn (BigDecimal $value): string => (string) $value, $this->quantities),
            'amounts' => array_map(static fn (Money $value): int => $value->cents, $this->amounts),
            'rates' => array_map(static fn (BigDecimal $value): string => (string) $value, $this->rates),
            'paidLeave' => self::leaveToArray($this->paidLeave),
            'unpaidDays' => $this->unpaidDays,
            'compensatoryLeave' => self::leaveToArray($this->compensatoryLeave),
            'severance' => null === $this->severance ? null : [
                'referenceSalary' => $this->severance->referenceSalary->cents,
                'agreed' => $this->severance->agreed?->cents,
                'previousYearGross' => $this->severance->previousYearGross?->cents,
            ],
        ];
    }

    /** @param array<array-key, mixed> $stored the column, as toArray() wrote it; anything unreadable is left out */
    public static function fromArray(array $stored): self
    {
        $decimals = static function (mixed $list): array {
            $read = [];
            foreach (\is_array($list) ? $list : [] as $code => $value) {
                if (\is_string($value) && is_numeric($value)) {
                    $read[(string) $code] = BigDecimal::of($value);
                }
            }

            return $read;
        };
        $amounts = [];
        foreach (\is_array($stored['amounts'] ?? null) ? $stored['amounts'] : [] as $code => $cents) {
            if (\is_int($cents)) {
                $amounts[(string) $code] = Money::cents($cents);
            }
        }
        $unpaid = $stored['unpaidDays'] ?? 0;
        $severance = $stored['severance'] ?? null;
        $cents = static fn (mixed $value): ?Money => \is_int($value) ? Money::cents($value) : null;
        $reference = \is_array($severance) ? $cents($severance['referenceSalary'] ?? null) : null;

        return new self(
            $decimals($stored['quantities'] ?? null),
            $amounts,
            $decimals($stored['rates'] ?? null),
            self::leaveFromArray($stored['paidLeave'] ?? null),
            \is_int($unpaid) ? $unpaid : 0,
            self::leaveFromArray($stored['compensatoryLeave'] ?? null),
            null === $reference || !$reference->isPositive() || !\is_array($severance) ? null : new Severance($reference, $cents($severance['agreed'] ?? null), $cents($severance['previousYearGross'] ?? null)),
        );
    }

    /** @return array<string, string|int>|null */
    private static function leaveToArray(?PaidLeave $leave): ?array
    {
        return null === $leave ? null : [
            'hours' => (string) $leave->hours,
            'daysTaken' => (string) $leave->daysTaken,
            'daysAcquired' => (string) $leave->daysAcquired,
            'referenceGross' => $leave->referenceGross->cents,
        ];
    }

    private static function leaveFromArray(mixed $leave): ?PaidLeave
    {
        if (\is_array($leave) && \is_string($leave['hours'] ?? null) && \is_string($leave['daysTaken'] ?? null)
            && \is_string($leave['daysAcquired'] ?? null) && \is_int($leave['referenceGross'] ?? null)) {
            return new PaidLeave($leave['hours'], $leave['daysTaken'], $leave['daysAcquired'], Money::cents($leave['referenceGross']));
        }

        return null;
    }

    /** The value a field of the card shows, in French (« 4,5 », « 150,00 »), or '' when nothing is entered. */
    public function shown(PayslipElementField $field): string
    {
        return match ($field->kind) {
            PayslipElementField::QUANTITY => isset($this->quantities[$field->code]) ? self::french($this->quantities[$field->code]) : '',
            PayslipElementField::AMOUNT => isset($this->amounts[$field->code]) ? self::french($this->amounts[$field->code]->toDecimal()) : '',
            default => isset($this->rates[$field->code]) ? self::french($this->rates[$field->code]) : '',
        };
    }

    public static function french(BigDecimal $value): string
    {
        return str_replace('.', ',', (string) $value);
    }
}
