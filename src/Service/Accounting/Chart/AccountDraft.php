<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

use App\Entity\Accounting\Account;

/**
 * An account as a person typed it, before AccountSheet checks it: the error of each field, and the
 * warning that does not stop the save (a number outside the reference chart).
 */
final class AccountDraft
{
    public string $number = '';
    public string $label = '';
    public bool $lettrable = false;
    public bool $collective = false;

    /** @var array<string, string> by field */
    public array $errors = [];

    public ?string $warning = null;

    public static function of(Account $account): self
    {
        $draft = new self();
        $draft->number = $account->getNumber();
        $draft->label = $account->getLabel();
        $draft->lettrable = $account->isLettrable();
        $draft->collective = $account->isCollective();

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
