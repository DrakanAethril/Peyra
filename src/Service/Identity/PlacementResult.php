<?php

declare(strict_types=1);

namespace App\Service\Identity;

/** What a placement did: students enrolled, moved from another class of the year, already there. */
final readonly class PlacementResult
{
    public function __construct(public int $placed, public int $moved, public int $unchanged)
    {
    }

    /** « 3 étudiants placés en BTS CG 1 » - the status bar's sentence (design-ux.md § 4.1). */
    public function sentence(string $className): string
    {
        $parts = [];
        if ($this->placed > 0) {
            $parts[] = \sprintf('%d %s en %s', $this->placed, 1 === $this->placed ? 'étudiant placé' : 'étudiants placés', $className);
        }
        if ($this->moved > 0) {
            $parts[] = \sprintf('%d %s en %s', $this->moved, 1 === $this->moved ? 'étudiant changé de classe' : 'étudiants changés de classe', $className);
        }
        if ($this->unchanged > 0) {
            $parts[] = \sprintf('%d déjà dans la classe', $this->unchanged);
        }

        return [] === $parts ? 'Aucun étudiant placé.' : implode(' ; ', $parts).'.';
    }
}
