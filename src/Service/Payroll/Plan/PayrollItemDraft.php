<?php

declare(strict_types=1);

namespace App\Service\Payroll\Plan;

use App\Entity\Payroll\PayrollItem;
use App\Entity\Payroll\PayrollRate;
use App\Service\Shared\FrenchFormat;

/** An item of the plan de paie as a person typed it, before PayrollItemRules checks it, with the error of each field. */
final class PayrollItemDraft
{
    public string $code = '';
    public string $label = '';
    public string $kind = '';
    /** Its place in the payslip; empty: the code's number. */
    public string $sortOrder = '';
    public bool $subtracts = false;
    /** An element of gross pay received in kind, taken back off the net (« Avantages en nature », 4000). */
    public bool $benefitInKind = false;
    public string $base = '';
    public string $ceiling = 'none';
    public string $employeeSource = 'none';
    public string $employeeKey = '';
    public string $employeePath = '';
    /** The percent or the amount, as typed. */
    public string $employeeValue = '';
    public string $employerSource = 'none';
    public string $employerKey = '';
    public string $employerPath = '';
    public string $employerValue = '';
    public string $status = 'all';
    public string $headcount = 'all';
    public string $salary = 'always';
    /** The body's id, empty for none. */
    public string $body = '';
    public string $line = '';
    /** Its account in the payroll's entry (step 4.5); empty: the default. */
    public string $account = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(PayrollItem $item): self
    {
        $draft = new self();
        $draft->code = $item->getCode();
        $draft->label = $item->getLabel();
        $draft->kind = $item->getKind()->value;
        $draft->sortOrder = (string) $item->getSortOrder();
        $draft->subtracts = $item->subtracts();
        $draft->benefitInKind = $item->isBenefitInKind();
        $draft->base = $item->getBase()->value;
        $draft->ceiling = $item->getCeiling()->value;
        [$draft->employeeSource, $draft->employeeKey, $draft->employeePath, $draft->employeeValue] = self::side($item->getEmployeeRate());
        [$draft->employerSource, $draft->employerKey, $draft->employerPath, $draft->employerValue] = self::side($item->getEmployerRate());
        $draft->status = $item->getStatusCondition()->value;
        $draft->headcount = $item->getHeadcountCondition()->value;
        $draft->salary = $item->getSalaryCondition()->value;
        $draft->body = (string) $item->getBody()?->getId();
        $draft->line = $item->getPayslipLine()->value ?? '';
        $draft->account = $item->getAccount() ?? '';

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /** @return array{string, string, string, string} */
    private static function side(PayrollRate $rate): array
    {
        $value = '';
        if (null !== $rate->getPercent()) {
            $value = FrenchFormat::number($rate->getPercent());
        } elseif (null !== $rate->getAmount()) {
            $value = FrenchFormat::amount($rate->getAmount());
        }

        return [$rate->getSource()->value, $rate->getReferenceKey() ?? '', $rate->getReferencePath() ?? '', $value];
    }
}
