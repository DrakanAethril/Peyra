<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

/**
 * What an imported plan would do, line by line - and, when one line is refused, the reason it
 * writes nothing at all.
 */
final readonly class ChartImportReport
{
    /** @param list<ChartImportLine> $lines */
    public function __construct(public array $lines, public ?string $fileRefusal = null)
    {
    }

    public static function refused(string $reason): self
    {
        return new self([], $reason);
    }

    public function isApplicable(): bool
    {
        return null === $this->fileRefusal && [] !== $this->lines && 0 === $this->count(ChartImportLine::REFUSED);
    }

    public function count(string $action): int
    {
        return \count(array_filter($this->lines, static fn (ChartImportLine $line): bool => $line->action === $action));
    }

    /** @return list<ChartImportLine> */
    public function refusedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (ChartImportLine $line): bool => ChartImportLine::REFUSED === $line->action));
    }

    /** @return list<ChartImportLine> lines written with a note (a number outside the reference chart) */
    public function notedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (ChartImportLine $line): bool => ChartImportLine::REFUSED !== $line->action && null !== $line->message));
    }
}
