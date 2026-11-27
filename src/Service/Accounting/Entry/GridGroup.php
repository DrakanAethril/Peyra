<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Service\Shared\JsonRequestPayload;

/**
 * One entry of the entry grid as typed, before it is an Entry: its day, its document and its lines.
 * `entry` names the draft it rewrites (null for a new one); a draft whose lines were all deleted is
 * a group without lines, which F10 deletes from the brouillard. `key` is the browser's name for it,
 * so that an answer finds its group again.
 *
 * @phpstan-type GridGroupArray array{key: string, entry: ?int, date: ?string, document: string, lines: list<array{account: string, thirdParty: string, label: string, debit: int, credit: int, due: ?string}>}
 */
final readonly class GridGroup
{
    /** @param list<GridLine> $lines */
    public function __construct(
        public string $key,
        public ?int $entry = null,
        public ?string $date = null,
        public string $document = '',
        public array $lines = [],
    ) {
    }

    public static function fromPayload(JsonRequestPayload $group): self
    {
        $date = $group->string('date');
        $lines = [];
        foreach (\array_slice($group->objects('lines'), 0, PendingEntries::MAX_LINES) as $line) {
            $lines[] = GridLine::fromPayload($line);
        }
        $entry = $group->int('entry');

        return new self(
            mb_substr($group->string('key'), 0, 40),
            null === $entry || $entry <= 0 ? null : $entry,
            1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ? $date : null,
            mb_substr($group->string('document'), 0, 60),
            $lines,
        );
    }

    /** @return list<GridLine> the lines with something typed on them */
    public function typedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (GridLine $line): bool => !$line->isBlank()));
    }

    /** A new group with nothing typed: dropped rather than kept. */
    public function isBlank(): bool
    {
        return null === $this->entry && [] === $this->typedLines() && '' === trim($this->document);
    }

    public function day(): ?\DateTimeImmutable
    {
        return null === $this->date ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $this->date) ?: null);
    }

    /** @return GridGroupArray */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'entry' => $this->entry,
            'date' => $this->date,
            'document' => $this->document,
            'lines' => array_map(static fn (GridLine $line): array => $line->toArray(), $this->typedLines()),
        ];
    }
}
