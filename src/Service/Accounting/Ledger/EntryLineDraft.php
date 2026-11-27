<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\EntryLine;
use App\Service\Shared\Money;

/** A line of an entry as typed (or as a module proposes it), before EntryRules checks it. */
final class EntryLineDraft
{
    public Money $debit;

    public Money $credit;

    public function __construct(
        /** The account's number (« 411000 »). */
        public string $account = '',
        /** The third party's code (« C0019 »); empty when the line names none. */
        public string $thirdParty = '',
        /** Empty: the entry's label. */
        public string $label = '',
        ?Money $debit = null,
        ?Money $credit = null,
        public ?\DateTimeImmutable $dueDate = null,
    ) {
        $this->debit = $debit ?? Money::zero();
        $this->credit = $credit ?? Money::zero();
    }

    public static function debit(string $account, Money $amount, string $label = '', string $thirdParty = '', ?\DateTimeImmutable $dueDate = null): self
    {
        return new self($account, $thirdParty, $label, $amount, Money::zero(), $dueDate);
    }

    public static function credit(string $account, Money $amount, string $label = '', string $thirdParty = '', ?\DateTimeImmutable $dueDate = null): self
    {
        return new self($account, $thirdParty, $label, Money::zero(), $amount, $dueDate);
    }

    public static function of(EntryLine $line): self
    {
        return new self($line->getAccount()->getNumber(), $line->getThirdParty()?->getCode() ?? '', $line->getLabel(), $line->getDebit(), $line->getCredit(), $line->getDueDate());
    }

    /** Nothing typed on it: the grid's last line, never counted. */
    public function isBlank(): bool
    {
        return '' === trim($this->account) && '' === trim($this->thirdParty) && '' === trim($this->label) && $this->debit->isZero() && $this->credit->isZero() && null === $this->dueDate;
    }
}
