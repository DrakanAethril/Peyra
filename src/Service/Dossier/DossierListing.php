<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Repository\Dossier\ExerciseRepository;

/**
 * Dossiers grouped by school year, the most recent first, each with the exercise its date falls in
 * (« Mes dossiers », the dossier switcher). The caller hands dossiers the person may open: the
 * exercises are read across them (DossierContext::across), never around DossierVoter.
 *
 * @phpstan-type ListingRow array{dossier: Dossier, exercise: Exercise|null}
 * @phpstan-type ListingGroup array{year: string, rows: list<ListingRow>}
 */
final readonly class DossierListing
{
    public function __construct(private DossierContext $context, private ExerciseRepository $exercises)
    {
    }

    /**
     * @param list<Dossier> $dossiers in the order to show, the most recent year first
     *
     * @return list<ListingGroup>
     */
    public function byYear(array $dossiers): array
    {
        $exercises = $this->context->across($dossiers, fn (): array => $this->exercises->findCurrentOf($dossiers));
        $groups = [];
        foreach ($dossiers as $dossier) {
            $year = $dossier->getSchoolYear()?->getLabel() ?? 'Hors année scolaire';
            $groups[$year] ??= [];
            $groups[$year][] = ['dossier' => $dossier, 'exercise' => $exercises[(int) $dossier->getId()] ?? null];
        }

        return array_map(static fn (string $year, array $rows): array => ['year' => $year, 'rows' => $rows], array_keys($groups), array_values($groups));
    }
}
