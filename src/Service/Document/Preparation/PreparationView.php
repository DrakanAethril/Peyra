<?php

declare(strict_types=1);

namespace App\Service\Document\Preparation;

use App\Entity\Document\Document;

/**
 * The screen « mode préparation » of a pièce (PiecePreparation::view()): the right read data, row
 * by row, and the right proposal, line by line - each value an error may fall on carries its
 * target, and the wrong value with its place once an error is slipped -, the errors, the second
 * arrivals of a doublon, and the first arrival when the pièce is itself one.
 */
final readonly class PreparationView
{
    /**
     * @param list<array{label: string, value: string, target: ?string, wrong: ?string, place: ?string}>                                                                         $reading
     * @param list<array{index: int, account: string, accountLabel: string, debit: int, credit: int, accountTarget: string, wrongAccount: ?string, amountTarget: ?string, wrongAmount: ?string}> $lines
     * @param list<array{target: string, label: string, nature: string, right: string, wrong: string, place: string}>                                                               $errors
     * @param list<Document>                                                                                                                                                         $duplicates
     */
    public function __construct(
        public AnswerKey $key,
        public array $reading,
        public array $lines,
        public array $errors,
        public array $duplicates,
        public ?Document $duplicateOf,
    ) {
    }

    /** « Erreurs à repérer · n »: the errors, and the doublon. */
    public function count(): int
    {
        return \count($this->errors) + ([] === $this->duplicates ? 0 : 1);
    }

    public function debit(): int
    {
        return array_sum(array_map(static fn (array $line): int => $line['debit'], $this->lines));
    }

    public function credit(): int
    {
        return array_sum(array_map(static fn (array $line): int => $line['credit'], $this->lines));
    }
}
