<?php

declare(strict_types=1);

namespace App\Service\Document\Processing;

use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\GridLine;

/**
 * The entry proposed for a pièce (conception, « Piste A »; design-ux.md § 3.5), as the pièce keeps
 * it: the head of the entry - journal, date, pièce number, label - and its lines in the grid's own
 * shape (GridLine: the Compte cell holds an account number or a third party's auxiliary code), plus
 * the fingerprint of the read data it was made from, so that the screen knows when they changed
 * since (« Reprendre l'écriture depuis les données lues »). A line may lack its account: the
 * proposal then leaves it to the student.
 */
final readonly class PieceProposal
{
    /** @param list<GridLine> $lines */
    public function __construct(
        public string $journal,
        public ?string $date,
        public string $document,
        public string $label,
        public array $lines,
        public string $basis = '',
    ) {
    }

    /** The entry as the grid's checks read it: every line carries the entry's label. */
    public function group(): GridGroup
    {
        return new GridGroup(
            'piece',
            null,
            $this->date,
            $this->document,
            array_map(fn (GridLine $line): GridLine => new GridLine($line->account, $line->thirdParty, $this->label, $line->debit, $line->credit, $line->due), $this->lines),
        );
    }

    public function totalDebit(): int
    {
        return array_sum(array_map(static fn (GridLine $line): int => $line->debit, $this->lines));
    }

    public function totalCredit(): int
    {
        return array_sum(array_map(static fn (GridLine $line): int => $line->credit, $this->lines));
    }

    /** @return array{journal: string, date: ?string, document: string, label: string, lines: list<array{account: string, thirdParty: string, label: string, debit: int, credit: int, due: ?string}>, basis: string} */
    public function toArray(): array
    {
        return [
            'journal' => $this->journal,
            'date' => $this->date,
            'document' => $this->document,
            'label' => $this->label,
            'lines' => array_map(static fn (GridLine $line): array => $line->toArray(), $this->lines),
            'basis' => $this->basis,
        ];
    }

    /** @param array<mixed> $stored as toArray() wrote it */
    public static function fromArray(array $stored): self
    {
        $lines = [];
        foreach (\is_array($stored['lines'] ?? null) ? $stored['lines'] : [] as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $lines[] = new GridLine(
                self::text($line, 'account'),
                self::text($line, 'thirdParty'),
                self::text($line, 'label'),
                \is_int($line['debit'] ?? null) ? $line['debit'] : 0,
                \is_int($line['credit'] ?? null) ? $line['credit'] : 0,
                \is_string($line['due'] ?? null) ? $line['due'] : null,
            );
        }

        return new self(
            self::text($stored, 'journal'),
            \is_string($stored['date'] ?? null) ? $stored['date'] : null,
            self::text($stored, 'document'),
            self::text($stored, 'label'),
            $lines,
            self::text($stored, 'basis'),
        );
    }

    /** @param array<mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
