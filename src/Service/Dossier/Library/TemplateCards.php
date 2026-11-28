<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\TemplateLevel;
use App\Enum\Shared\BtsProcess;
use App\Service\Audit\AuditTrail;

/**
 * The one door of a template's card (Bibliothèque › Dossiers modèles, a template's fiche): its
 * title, description, the year it is written for and the processes it covers - checked, then
 * saved with a line of the dossier's trail when something changed. Who may: DossierVoter::EDIT.
 */
final readonly class TemplateCards
{
    public const int TITLE_MAX = 120;
    public const int DESCRIPTION_MAX = 2000;

    public function __construct(private AuditTrail $auditTrail)
    {
    }

    /** True when the card is well formed; the faults are written on the draft otherwise. */
    public function check(TemplateCardDraft $draft): bool
    {
        $draft->errors = [];
        if (mb_strlen($draft->title) > self::TITLE_MAX) {
            $draft->errors['title'] = \sprintf('Le titre tient en %d caractères au plus.', self::TITLE_MAX);
        }
        if (mb_strlen($draft->description) > self::DESCRIPTION_MAX) {
            $draft->errors['description'] = \sprintf('La description tient en %d caractères au plus.', self::DESCRIPTION_MAX);
        }
        if ('' !== $draft->level && null === TemplateLevel::tryFrom($draft->level)) {
            $draft->errors['level'] = 'Choisissez un niveau dans la liste.';
        }
        foreach ($draft->processes as $process) {
            if (null === BtsProcess::tryFrom($process)) {
                $draft->errors['processes'] = 'Cochez des processus de la liste.';
            }
        }

        return [] === $draft->errors;
    }

    /** Writes a checked card; false when nothing changed. */
    public function save(Dossier $template, TemplateCardDraft $draft, User $by): bool
    {
        if (!$template->isTemplate() || !$this->check($draft)) {
            throw new \LogicException('A checked card of a template is saved.');
        }
        $before = TemplateCardDraft::of($template);
        $processes = array_values(array_filter(array_map(BtsProcess::tryFrom(...), $draft->processes)));
        $template->describeInLibrary('' === $draft->title ? null : $draft->title, '' === $draft->description ? null : $draft->description, TemplateLevel::tryFrom($draft->level), $processes);
        $after = TemplateCardDraft::of($template);
        if ([$before->title, $before->description, $before->level, $before->processes] === [$after->title, $after->description, $after->level, $after->processes]) {
            return false;
        }
        $this->auditTrail->inDossier($template, AuditAction::TemplateCardChanged, $by, \sprintf('Fiche du modèle « %s » modifiée', $template->getLibraryTitle()), [
            'title' => $after->title,
            'level' => $after->level,
            'processes' => $after->processes,
        ]);

        return true;
    }
}
