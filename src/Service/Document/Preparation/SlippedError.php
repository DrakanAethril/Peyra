<?php

declare(strict_types=1);

namespace App\Service\Document\Preparation;

use App\Enum\Document\SlipNature;
use App\Enum\Document\SlipPlace;

/**
 * One error the teacher slipped into a pièce (design-ux.md § 3.5): the value it falls on (a
 * target of SlippedErrors: `thirdParty`, `totalIncludingTax`, `vat.0.rate`, `line.2.account`…), its
 * nature, where it shows and the wrong value - a third party's code, an account number, a rate's
 * canonical text, cents. The right value is the answer key's.
 */
final readonly class SlippedError
{
    public function __construct(
        public string $target,
        public SlipNature $nature,
        public SlipPlace $place,
        public string $wrong,
    ) {
    }

    /** @return array{target: string, nature: string, place: string, wrong: string} */
    public function toArray(): array
    {
        return ['target' => $this->target, 'nature' => $this->nature->value, 'place' => $this->place->value, 'wrong' => $this->wrong];
    }

    /** @param array<mixed> $stored as toArray() wrote it */
    public static function fromArray(array $stored): self
    {
        $target = $stored['target'] ?? null;
        $nature = \is_string($stored['nature'] ?? null) ? SlipNature::tryFrom($stored['nature']) : null;
        $place = \is_string($stored['place'] ?? null) ? SlipPlace::tryFrom($stored['place']) : null;
        $wrong = $stored['wrong'] ?? null;
        if (!\is_string($target) || null === $nature || null === $place || !\is_string($wrong)) {
            throw new \UnexpectedValueException('A stored slipped error is {target, nature, place, wrong}.');
        }

        return new self($target, $nature, $place, $wrong);
    }
}
