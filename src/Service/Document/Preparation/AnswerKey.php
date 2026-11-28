<?php

declare(strict_types=1);

namespace App\Service\Document\Preparation;

use App\Service\Document\Processing\PieceProposal;

/**
 * What a pièce of a template really says, and the errors the teacher slipped into what the student
 * receives (step 2.2b; design-ux.md § 3.5): the right read data, the right proposal (null when the
 * pièce has none) and the errors, in the order they were slipped. Kept on the pièce
 * (Document::$answerKey) while it carries an error; the student's screens never read it.
 */
final readonly class AnswerKey
{
    /** @param list<SlippedError> $errors */
    public function __construct(
        public PieceReading $reading,
        public ?PieceProposal $proposal,
        public array $errors = [],
    ) {
    }

    public function errorOn(string $target): ?SlippedError
    {
        foreach ($this->errors as $error) {
            if ($error->target === $target) {
                return $error;
            }
        }

        return null;
    }

    public function with(SlippedError $error): self
    {
        return new self($this->reading, $this->proposal, [...$this->errors, $error]);
    }

    public function without(string $target): self
    {
        return new self($this->reading, $this->proposal, array_values(array_filter($this->errors, static fn (SlippedError $error): bool => $error->target !== $target)));
    }

    public function withProposal(PieceProposal $proposal): self
    {
        return new self($this->reading, $proposal, $this->errors);
    }

    /** One of its errors shows in the entry proposed: the right proposal no longer changes. */
    public function touchesProposal(): bool
    {
        foreach ($this->errors as $error) {
            if ($error->place->touchesProposal()) {
                return true;
            }
        }

        return false;
    }

    /** @return array{reading: array<string, mixed>, proposal: ?array<string, mixed>, errors: list<array<string, string>>} */
    public function toArray(): array
    {
        return [
            'reading' => $this->reading->toArray(),
            'proposal' => $this->proposal?->toArray(),
            'errors' => array_map(static fn (SlippedError $error): array => $error->toArray(), $this->errors),
        ];
    }

    /** @param array<mixed> $stored as toArray() wrote it */
    public static function fromArray(array $stored): self
    {
        $errors = [];
        foreach (\is_array($stored['errors'] ?? null) ? $stored['errors'] : [] as $error) {
            if (\is_array($error)) {
                $errors[] = SlippedError::fromArray($error);
            }
        }

        return new self(
            PieceReading::fromArray(\is_array($stored['reading'] ?? null) ? $stored['reading'] : []),
            \is_array($stored['proposal'] ?? null) ? PieceProposal::fromArray($stored['proposal']) : null,
            $errors,
        );
    }
}
