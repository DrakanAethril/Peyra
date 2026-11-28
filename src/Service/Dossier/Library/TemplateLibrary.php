<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Dossier\TemplateShelf;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Dossier\DossierShareRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Service\Dossier\DossierContext;

/**
 * Bibliothèque › Dossiers modèles as one teacher reads it (design-ux.md §§ 2.6 and 3.3): their own
 * templates, those colleagues shared with them, and the common library the administrators keep -
 * the three shelves DossierVoter lets them read, and nothing else. A fixed number of queries,
 * whatever the number of templates.
 */
final readonly class TemplateLibrary
{
    public function __construct(
        private DossierRepository $dossiers,
        private DossierShareRepository $shares,
        private ExerciseRepository $exercises,
        private DossierContext $context,
    ) {
    }

    /** @return list<TemplateCard> mine (newest first), shared with me, then the common library */
    public function cards(User $teacher): array
    {
        $mine = $this->dossiers->findTemplatesOf($teacher);
        $shared = [];
        $forChange = [];
        foreach ($this->shares->findForTeacher($teacher) as $share) {
            $template = $share->getDossier();
            if ($template->isTemplate() && $template->getOwner() !== $teacher) {
                $shared[] = $template;
                $forChange[(int) $template->getId()] = $share->canEdit();
            }
        }
        $common = $this->dossiers->findCommonTemplates();
        $all = [...$mine, ...$shared, ...$common];
        if ([] === $all) {
            return [];
        }
        $exercises = $this->context->across($all, fn (): array => $this->exercises->findCurrentOf($all));
        $copies = $this->dossiers->countCopiesOf($all);
        $shareCounts = $this->shares->countByDossier($mine);

        $card = static fn (Dossier $template, TemplateShelf $shelf): TemplateCard => new TemplateCard(
            $template,
            $shelf,
            $exercises[(int) $template->getId()] ?? null,
            $copies[(int) $template->getId()] ?? 0,
            $shareCounts[(int) $template->getId()] ?? 0,
            $forChange[(int) $template->getId()] ?? false,
        );

        return [
            ...array_map(static fn (Dossier $template): TemplateCard => $card($template, TemplateShelf::Mine), $mine),
            ...array_map(static fn (Dossier $template): TemplateCard => $card($template, TemplateShelf::Shared), $shared),
            ...array_map(static fn (Dossier $template): TemplateCard => $card($template, TemplateShelf::Common), $common),
        ];
    }

    /** The card of one template the teacher reads; null when it is on none of their shelves. */
    public function card(User $teacher, Dossier $template): ?TemplateCard
    {
        foreach ($this->cards($teacher) as $card) {
            if ($card->template === $template) {
                return $card;
            }
        }

        return null;
    }
}
