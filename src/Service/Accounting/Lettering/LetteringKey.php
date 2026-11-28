<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryLine;
use App\Entity\ThirdParty\ThirdParty;

/**
 * What one lettering belongs to (lot 1, step 1.6): a lettrable general account (467000, 455100),
 * or a third party on a collective account - the auxiliary account (« 401SCIE » on 401000; Girard
 * on 411000 and, apart, on 419100). Its letters are continuous; a lettering never spans two keys.
 */
final readonly class LetteringKey
{
    private function __construct(public Account $account, public ?ThirdParty $thirdParty)
    {
    }

    /** A collective account is lettered per third party; a general account whole, whatever its lines name. */
    public static function of(Account $account, ?ThirdParty $thirdParty): self
    {
        return new self($account, $account->isCollective() ? $thirdParty : null);
    }

    public static function ofLine(EntryLine $line): self
    {
        return self::of($line->getAccount(), $line->getThirdParty());
    }

    public function holds(EntryLine $line): bool
    {
        return $line->getAccount()->getId() === $this->account->getId()
            && (null === $this->thirdParty || $line->getThirdParty()?->getId() === $this->thirdParty->getId());
    }

    public function equals(self $other): bool
    {
        return $this->account->getId() === $other->account->getId() && $this->thirdParty?->getId() === $other->thirdParty?->getId();
    }

    /** What is typed to find it again: « 401SCIE », « 467000 ». */
    public function code(): string
    {
        return $this->thirdParty?->getAuxiliaryCode() ?? $this->thirdParty?->getCode() ?? $this->account->getNumber();
    }

    public function label(): string
    {
        return $this->thirdParty?->getName() ?? $this->account->getLabel();
    }

    /** The account to name besides the code: a third party's other collective (« 419100 »), else null. */
    public function otherAccount(): ?string
    {
        if (null === $this->thirdParty || $this->thirdParty->getAccount()?->getId() === $this->account->getId()) {
            return null;
        }

        return $this->account->getNumber();
    }

    /** « 401SCIE », or « 411GIRA sur 419100 » when the third party sits on another collective than its own. */
    public function describe(): string
    {
        $other = $this->otherAccount();

        return null === $other ? $this->code() : \sprintf('%s sur %s', $this->code(), $other);
    }
}
