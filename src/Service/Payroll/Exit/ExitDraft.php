<?php

declare(strict_types=1);

namespace App\Service\Payroll\Exit;

use App\Entity\Payroll\Employee;
use App\Service\Shared\DateValue;

/**
 * An employee's exit as a person typed it (step 4.2c), before ExitRules checks it: the reason, the
 * day it was notified (a letter, a convention) and the last day of the contract. Dates are
 * « 2026-05-31 » (Px:DateInput).
 */
final class ExitDraft
{
    public string $reason = '';
    public string $notifiedOn = '';
    public string $exitOn = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    /** The exit recorded, or a blank draft whose last day is a fixed-term contract's or an apprenticeship's term. */
    public static function of(Employee $employee): self
    {
        $draft = new self();
        $draft->reason = (string) $employee->getExitReason()?->value;
        $draft->notifiedOn = (string) $employee->getExitNotifiedOn()?->format('Y-m-d');
        $draft->exitOn = (string) ($employee->getExitOn() ?? $employee->getContractEndsOn())?->format('Y-m-d');

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /** A date field as the date input shows it, or null when empty or unreadable. */
    public function date(string $field): ?\DateTimeImmutable
    {
        return DateValue::parse('notifiedOn' === $field ? $this->notifiedOn : $this->exitOn);
    }
}
