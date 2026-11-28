<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Entity\Payroll\PayrollBody;
use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollBodyKind;
use App\Enum\Payroll\PayrollItemKind;
use App\Service\Payroll\Plan\PayrollItemSpec;

/**
 * The plan de paie as the payroll's entry reads it (step 4.5): each rubrique by its code - its body and
 * accounts - and the complementary pension body the general reduction's second share is imputed on. The
 * dossier's current plan: a frozen payslip keeps its amounts, never where they are posted.
 */
final readonly class PayrollChart
{
    /** @param array<string, PayrollItemSpec> $items by code */
    private function __construct(private array $items, public ?PayrollBody $retirement)
    {
    }

    /**
     * @param list<PayrollItemSpec> $plan
     * @param list<PayrollBody>     $bodies the dossier's, the first complementary pension one taking the reduction's share
     */
    public static function of(array $plan, array $bodies): self
    {
        $items = [];
        foreach ($plan as $spec) {
            $items[$spec->code] = $spec;
        }
        $retirement = null;
        foreach ($bodies as $body) {
            if (PayrollBodyKind::Retirement === $body->getKind()) {
                $retirement = $body;
                break;
            }
        }

        return new self($items, $retirement);
    }

    public function item(string $code): ?PayrollItemSpec
    {
        return $this->items[$code] ?? null;
    }

    /** The account the withholding tax is owed on (step 4.6): the plan's tax rubrique's - a deduction on the net taxable -, else 442100. */
    public function withholdingAccount(): string
    {
        foreach ($this->items as $spec) {
            if (PayrollItemKind::Deduction === $spec->kind && PayrollBase::NetTaxable === $spec->base) {
                return PayrollAccounts::of($spec);
            }
        }

        return PayrollAccounts::WITHHOLDING;
    }
}
