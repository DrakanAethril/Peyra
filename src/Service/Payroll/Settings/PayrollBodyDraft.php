<?php

declare(strict_types=1);

namespace App\Service\Payroll\Settings;

use App\Entity\Payroll\PayrollBody;

/** A collecting body as a person typed it, with the error of each field. */
final class PayrollBodyDraft
{
    public string $kind = '';
    public string $name = '';
    public string $reference = '';
    /** The account its contributions are owed on (step 4.5); empty: its kind's. */
    public string $account = '';
    /** The charge account of the employer's contributions (step 4.5); empty: its kind's. */
    public string $chargeAccount = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(PayrollBody $body): self
    {
        $draft = new self();
        $draft->kind = $body->getKind()->value;
        $draft->name = $body->getName();
        $draft->reference = $body->getReference();
        $draft->account = $body->getAccount() ?? '';
        $draft->chargeAccount = $body->getChargeAccount() ?? '';

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
