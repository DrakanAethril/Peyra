<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Identity\EntryRefusal;

/** What the entry rule made of a person: let in as a kind, on a basis, or refused for a reason. */
final readonly class EntryDecision
{
    private function __construct(
        public ?AccountKind $kind,
        public ?EntryBasis $basis,
        public ?EntryRefusal $refusal,
    ) {
    }

    public static function admit(AccountKind $kind, EntryBasis $basis): self
    {
        return new self($kind, $basis, null);
    }

    public static function refuse(EntryRefusal $refusal): self
    {
        return new self(null, null, $refusal);
    }

    /**
     * @phpstan-assert-if-true !null $this->kind
     * @phpstan-assert-if-true !null $this->basis
     * @phpstan-assert-if-false !null $this->refusal
     */
    public function isAdmitted(): bool
    {
        return null !== $this->kind;
    }
}
