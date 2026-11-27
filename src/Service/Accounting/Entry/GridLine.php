<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Service\Shared\JsonRequestPayload;

/**
 * A line of the entry grid as the person typed it (design-ux.md § 5.2): the Compte cell holds an
 * account number or a third party's auxiliary code (« 401SCIE »), the Tiers cell a third party only
 * when the account typed is a collective one by number (419100 for a customer's advance). Amounts are
 * whole cents (the browser reads « 2 622,00 »), the due date an ISO day or null.
 */
final readonly class GridLine
{
    public function __construct(
        public string $account = '',
        public string $thirdParty = '',
        public string $label = '',
        public int $debit = 0,
        public int $credit = 0,
        public ?string $due = null,
    ) {
    }

    public static function fromPayload(JsonRequestPayload $line): self
    {
        $due = $line->string('due');

        return new self(
            mb_substr(trim($line->string('account')), 0, 17),
            mb_substr(trim($line->string('thirdParty')), 0, 17),
            mb_substr($line->string('label'), 0, 250),
            max(0, (int) $line->int('debit', 0)),
            max(0, (int) $line->int('credit', 0)),
            1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
        );
    }

    /**
     * Nothing typed on it - the grid's empty last line, whose label is only the one carried from
     * the line above.
     */
    public function isBlank(): bool
    {
        return '' === $this->account && '' === $this->thirdParty && 0 === $this->debit && 0 === $this->credit && null === $this->due;
    }

    /** @return array{account: string, thirdParty: string, label: string, debit: int, credit: int, due: ?string} */
    public function toArray(): array
    {
        return ['account' => $this->account, 'thirdParty' => $this->thirdParty, 'label' => $this->label, 'debit' => $this->debit, 'credit' => $this->credit, 'due' => $this->due];
    }
}
