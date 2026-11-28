<?php

declare(strict_types=1);

namespace App\Service\Payroll\Employee;

use App\Entity\Payroll\Employee;
use App\Entity\Payroll\EmploymentTerms;
use App\Service\Shared\DateValue;

/**
 * An employee as a person typed them, before EmployeeRules checks them: the civil status, the
 * contract, the terms from a day on (« À compter du »: a new row of the history, or the row of that
 * day corrected) and the withholding rate; the error of each field. Dates are « 2026-04-01 », the
 * base salary whole cents (Px:DateInput, Px:AmountInput).
 */
final class EmployeeDraft
{
    public string $registrationNumber = '';
    public string $lastName = '';
    public string $firstName = '';
    public string $sex = '';
    public string $birthDate = '';
    public string $birthPlace = '';
    public string $nationality = 'Française';
    public string $nir = '';
    public string $street = '';
    public string $postalCode = '';
    public string $city = '';
    /** In percent; empty: no rate sent, the neutral rate applies. */
    public string $withholdingRate = '';
    public string $contractType = '';
    public string $hiredOn = '';
    public string $contractEndsOn = '';
    public string $fixedTermReason = '';
    public string $diploma = '';
    public string $trainingCentre = '';
    /** The day the terms below start; a new employee's start on the day of hire. */
    public string $termsFrom = '';
    public string $jobTitle = '';
    public string $classification = '';
    /** « executive » or « non_executive ». */
    public string $status = '';
    public string $workingTime = '';
    /** Read for a part-time contract only: a full-time one works the establishment's collective time. */
    public string $monthlyHours = '';
    public ?int $baseSalary = null;

    /** @var array<string, string> by field */
    public array $errors = [];

    /** The employee and the terms shown: those in force at the dossier's date, or those of the day asked for. */
    public static function of(Employee $employee, EmploymentTerms $terms): self
    {
        $draft = new self();
        $draft->registrationNumber = $employee->getRegistrationNumber();
        $draft->lastName = $employee->getLastName();
        $draft->firstName = $employee->getFirstName();
        $draft->sex = $employee->getSex()->value;
        $draft->birthDate = $employee->getBirthDate()->format('Y-m-d');
        $draft->birthPlace = $employee->getBirthPlace() ?? '';
        $draft->nationality = $employee->getNationality();
        $draft->nir = $employee->getNir() ?? '';
        $draft->street = $employee->getStreet() ?? '';
        $draft->postalCode = $employee->getPostalCode() ?? '';
        $draft->city = $employee->getCity() ?? '';
        $draft->withholdingRate = $employee->getWithholdingRate() ?? '';
        $draft->contractType = $employee->getContractType()->value;
        $draft->hiredOn = $employee->getHiredOn()->format('Y-m-d');
        $draft->contractEndsOn = $employee->getContractEndsOn()?->format('Y-m-d') ?? '';
        $draft->fixedTermReason = $employee->getFixedTermReason() ?? '';
        $draft->diploma = $employee->getDiploma() ?? '';
        $draft->trainingCentre = $employee->getTrainingCentre() ?? '';
        $draft->termsFrom = $terms->getEffectiveFrom()->format('Y-m-d');
        $draft->jobTitle = $terms->getJobTitle();
        $draft->classification = $terms->getClassification();
        $draft->status = $terms->isExecutive() ? 'executive' : 'non_executive';
        $draft->workingTime = $terms->getWorkingTime()->value;
        $draft->monthlyHours = $terms->getMonthlyHours();
        $draft->baseSalary = $terms->getBaseSalary()->cents;

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /** The NIR in its groups, « 1 94 04 42 147 118 00 », as typed when it is not 15 characters long. */
    public function nirShown(): string
    {
        return Nir::format($this->nir);
    }

    /** A date field as the date input shows it, or null when empty or unreadable. */
    public function date(string $field): ?\DateTimeImmutable
    {
        $value = match ($field) {
            'birthDate' => $this->birthDate,
            'hiredOn' => $this->hiredOn,
            'contractEndsOn' => $this->contractEndsOn,
            'termsFrom' => $this->termsFrom,
            default => throw new \InvalidArgumentException('Not a date of the employee: '.$field),
        };

        return DateValue::parse($value);
    }
}
