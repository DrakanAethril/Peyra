<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\ExerciseRepository;
use App\Service\Accounting\Ledger\EntryScope;

/**
 * What every edition of Comptabilité › Éditions shares (lot 1, step 1.5b; design-ux.md § 3.1,
 * gabarit R): the window read from the parameter bar, and the head of its PDF.
 */
final readonly class Editions
{
    public function __construct(
        private ExerciseRepository $exercises,
    ) {
    }

    /** @param string $fromIso « 2026-01-01 », or empty for the default */
    public function window(Dossier $dossier, string $fromIso, string $toIso): EditionWindow
    {
        return EditionWindow::read($fromIso, $toIso, $this->exercises->findByDossier($dossier), $dossier->getDossierDate());
    }

    /** The window of a state read at one day (the aged balance): its exercise's first day up to it. */
    public function windowAt(Dossier $dossier, string $atIso): EditionWindow
    {
        return EditionWindow::upTo($atIso, $this->exercises->findByDossier($dossier), $dossier->getDossierDate());
    }

    /**
     * The head of the state: « Exercice 2026 », « Du 01/01/2026 au 31/01/2026 · brouillard
     * inclus · comptes 401 à 411 ».
     *
     * @param list<string|null> $more what else narrows the state, in the order said
     */
    public function header(Dossier $dossier, string $title, EditionWindow $window, EntryScope $scope, array $more = []): ReportHeader
    {
        return new ReportHeader(
            $dossier->getName(),
            $title,
            null === $window->exercise ? 'Hors exercice' : 'Exercice '.$window->exercise->getCode(),
            implode(' · ', array_filter([
                \sprintf('Du %s au %s', $window->from->format('d/m/Y'), $window->to->format('d/m/Y')),
                self::scope($scope),
                ...$more,
            ], static fn (?string $part): bool => null !== $part && '' !== $part)),
            $dossier->getDossierDate(),
            self::student($dossier),
        );
    }

    /** « brouillard inclus » or « écritures validées », as the state's head says it. */
    public static function scope(EntryScope $scope): string
    {
        return EntryScope::Validated === $scope ? 'écritures validées' : 'brouillard inclus';
    }

    /** The student a dossier belongs to, named at the foot of its states; nobody for a template or a group. */
    public static function student(Dossier $dossier): ?string
    {
        return DossierKind::Student === $dossier->getKind() ? $dossier->getOwner()?->getDisplayName() : null;
    }
}
