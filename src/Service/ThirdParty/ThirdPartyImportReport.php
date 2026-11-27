<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

/**
 * What an imported file of third parties would do, line by line - and, when one line is refused,
 * the reason it writes nothing at all.
 */
final readonly class ThirdPartyImportReport
{
    /** @param list<ThirdPartyImportLine> $lines */
    public function __construct(public array $lines, public ?string $fileRefusal = null)
    {
    }

    public static function refused(string $reason): self
    {
        return new self([], $reason);
    }

    public function isApplicable(): bool
    {
        return null === $this->fileRefusal && [] !== $this->lines && 0 === $this->count(ThirdPartyImportLine::REFUSED);
    }

    public function count(string $action): int
    {
        return \count(array_filter($this->lines, static fn (ThirdPartyImportLine $line): bool => $line->action === $action));
    }

    /** @return list<ThirdPartyImportLine> */
    public function refusedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (ThirdPartyImportLine $line): bool => ThirdPartyImportLine::REFUSED === $line->action));
    }

    /** @return list<ThirdPartyImportLine> lines written with a note (an auxiliary code given by the application) */
    public function notedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (ThirdPartyImportLine $line): bool => ThirdPartyImportLine::REFUSED !== $line->action && null !== $line->message));
    }
}
