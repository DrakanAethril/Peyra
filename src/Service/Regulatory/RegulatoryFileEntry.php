<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Entity\Regulatory\RegulatoryValue;

/**
 * One entry of a millésime file (config/regulatory/<année>.yaml), read and checked by
 * RegulatoryFile.
 *
 * @phpstan-import-type Node from RegulatoryTree
 */
final readonly class RegulatoryFileEntry
{
    /** @param Node $tree */
    public function __construct(
        public string $key,
        public string $label,
        public string|bool|array|null $tree,
        public string $unit,
        public \DateTimeImmutable $effectiveFrom,
        public ?string $sourceUrl,
        public ?\DateTimeImmutable $consultedOn,
        public bool $verified,
        public ?string $note,
    ) {
    }

    /** Whether a stored row says exactly what this entry says (the history is not rewritten for nothing). */
    public function sameAs(RegulatoryValue $row): bool
    {
        return $row->getKey() === $this->key
            && $row->getLabel() === $this->label
            && $row->getValueText() === RegulatoryTree::encode($this->tree)
            && $row->getUnit() === $this->unit
            && $row->getEffectiveFrom()->format('Y-m-d') === $this->effectiveFrom->format('Y-m-d')
            && $row->getSourceUrl() === $this->sourceUrl
            && $row->getConsultedOn()?->format('Y-m-d') === $this->consultedOn?->format('Y-m-d')
            && $row->isVerified() === $this->verified
            && $row->getNote() === $this->note;
    }

    /** Whether a stored row holds the same value at the same date, whatever its source and notes. */
    public function sameValueAs(RegulatoryValue $row): bool
    {
        return $row->getValueText() === RegulatoryTree::encode($this->tree)
            && $row->getEffectiveFrom()->format('Y-m-d') === $this->effectiveFrom->format('Y-m-d');
    }
}
