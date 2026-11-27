<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\SchoolClass;

/**
 * A « passage de classe » before it is applied (design-ux.md § 4.12): every student of the class
 * of last year with what becomes of them, and the count the summary reads (« 21 passent en CG2
 * 2027-2028, 2 redoublent en CG1 2027-2028, 1 n'est pas placé »).
 */
final readonly class PromotionPlan
{
    /** @param list<PromotionLine> $lines */
    public function __construct(public SchoolClass $source, public SchoolClass $next, public array $lines)
    {
    }

    /**
     * Students going to each class, in the order: the next class first, then the others by name.
     *
     * @return list<array{class: SchoolClass, count: int, repeat: bool}>
     */
    public function byClass(): array
    {
        $counts = [];
        $classes = [];
        foreach ($this->lines as $line) {
            if ($line->isAlreadyEnrolled() || null === $line->target) {
                continue;
            }
            $key = (int) $line->target->getId();
            $classes[$key] = $line->target;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        uasort($classes, fn (SchoolClass $a, SchoolClass $b): int => [$a !== $this->next, $a->getName()] <=> [$b !== $this->next, $b->getName()]);
        $groups = [];
        foreach ($classes as $key => $class) {
            $groups[] = ['class' => $class, 'count' => $counts[$key], 'repeat' => $class !== $this->next && $class->getLevel() === $this->source->getLevel()];
        }

        return $groups;
    }

    public function unplacedCount(): int
    {
        return $this->count(PromotionChoice::NONE);
    }

    public function departedCount(): int
    {
        return $this->count(PromotionChoice::DEPARTED);
    }

    public function alreadyCount(): int
    {
        return \count(array_filter($this->lines, static fn (PromotionLine $l): bool => $l->isAlreadyEnrolled()));
    }

    /** Whether applying the plan changes anything. */
    public function isEmpty(): bool
    {
        return [] === $this->byClass() && 0 === $this->unplacedCount() && 0 === $this->departedCount();
    }

    /**
     * The summary's sentence, without its closing reminder that dossiers are not touched.
     */
    public function sentence(): string
    {
        $parts = [];
        foreach ($this->byClass() as $group) {
            $verb = $group['repeat'] ? ['redouble', 'redoublent'] : ['passe', 'passent'];
            $parts[] = \sprintf('%d %s en %s', $group['count'], 1 === $group['count'] ? $verb[0] : $verb[1], $group['class']->getLabel());
        }
        $unplaced = $this->unplacedCount();
        if ($unplaced > 0) {
            $parts[] = \sprintf('%d %s', $unplaced, 1 === $unplaced ? 'n’est pas placé' : 'ne sont pas placés');
        }
        $departed = $this->departedCount();
        if ($departed > 0) {
            $parts[] = \sprintf('%d %s de la filière', $departed, 1 === $departed ? 'sort' : 'sortent');
        }
        if ([] === $parts) {
            return 'Aucun changement : tous les étudiants sont déjà inscrits dans l’année.';
        }
        return implode(', ', $parts).'.';
    }

    private function count(string $kind): int
    {
        return \count(array_filter($this->lines, static fn (PromotionLine $l): bool => !$l->isAlreadyEnrolled() && $kind === $l->choice->kind));
    }
}
