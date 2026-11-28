<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Enum\Document\AnomalyNature;
use App\Enum\Document\AnomalyOutcome;

/**
 * An anomaly a student reported on a pièce (design-ux.md § 4.3): its nature, the comment that
 * justifies it, what was done (rejected, or posted with a reserve), on the dossier's date.
 */
final readonly class PieceAnomaly
{
    public function __construct(
        public AnomalyNature $nature,
        public string $comment,
        public AnomalyOutcome $outcome,
        public \DateTimeImmutable $on,
    ) {
    }

    /** @return array{nature: string, comment: string, outcome: string, on: string} */
    public function toArray(): array
    {
        return ['nature' => $this->nature->value, 'comment' => $this->comment, 'outcome' => $this->outcome->value, 'on' => $this->on->format('Y-m-d')];
    }

    /** @param array<mixed> $row as toArray() wrote it */
    public static function fromArray(array $row): self
    {
        $nature = AnomalyNature::tryFrom(\is_string($row['nature'] ?? null) ? $row['nature'] : '');
        $outcome = AnomalyOutcome::tryFrom(\is_string($row['outcome'] ?? null) ? $row['outcome'] : '');
        $comment = $row['comment'] ?? null;
        $on = \is_string($row['on'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $row['on']) : false;
        if (null === $nature || null === $outcome || !\is_string($comment) || false === $on) {
            throw new \UnexpectedValueException('A stored anomaly is {nature, comment, outcome, on}.');
        }

        return new self($nature, $comment, $outcome, $on);
    }
}
