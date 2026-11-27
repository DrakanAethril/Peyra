<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Entity\Regulatory\RegulatoryValue;

/**
 * « Nouvelle valeur datée » as a person fills it: what was typed, field by field, and what was
 * refused, so that a refused form comes back as it was sent. The value's structure is the base
 * row's: a person changes its leaves, never its shape.
 */
final class RegulatoryDraft
{
    public ?\DateTimeImmutable $effectiveFrom = null;

    /** @var list<string> what is typed for each leaf of the base value, in RegulatoryTree::leaves() order */
    public array $leaves = [];

    public string $sourceUrl = '';

    public ?\DateTimeImmutable $consultedOn = null;

    /** A date of consultation was sent that is not a day (31/02): refused rather than dropped. */
    public bool $consultedOnUnreadable = false;

    public bool $verified = false;

    public string $note = '';

    /** @var array<string, string> field => the sentence printed under it; `form` for the whole */
    public array $errors = [];

    public function __construct(public readonly RegulatoryValue $base)
    {
    }

    /** The form as it opens: the base's leaves to change, its source to keep or replace, nothing verified. */
    public static function startingFrom(RegulatoryValue $base): self
    {
        $draft = new self($base);
        $draft->leaves = array_map(static fn (RegulatoryLeaf $leaf): string => RegulatoryDisplay::editable($leaf->value), RegulatoryTree::leaves($base->getTree()));
        $draft->sourceUrl = $base->getSourceUrl() ?? '';

        return $draft;
    }

    /** @return list<RegulatoryLeaf> the base's leaves, which give each field its kind and its label */
    public function baseLeaves(): array
    {
        return RegulatoryTree::leaves($this->base->getTree());
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
