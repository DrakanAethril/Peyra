<?php

declare(strict_types=1);

namespace App\Enum\Mission;

/**
 * What ticks a task of a mission on the student's side (step 2.9b): the student, with « Tâche
 * terminée », or - when it can be read simply in the dossier - what the student did. A detection
 * says « fait », never « juste » (design-ux.md A11): the answer key is not guessed by trying.
 */
enum MissionTaskCheck: string
{
    case Declared = 'declared';
    case Pieces = 'pieces';
    case Drafts = 'drafts';
    case Statements = 'statements';

    public function label(): string
    {
        return match ($this) {
            self::Declared => 'Cochée par l’étudiant (« Tâche terminée »)',
            self::Pieces => 'Toutes les pièces arrivées sont comptabilisées ou écartées',
            self::Drafts => 'Le brouillard est vide : plus aucune écriture à valider',
            self::Statements => 'Tous les relevés bancaires sont rapprochés',
        };
    }

    /** What the student reads under a task the dossier ticks. */
    public function hint(): string
    {
        return match ($this) {
            self::Declared => '',
            self::Pieces => 'Cochée d’elle-même quand toutes les pièces arrivées sont comptabilisées ou écartées.',
            self::Drafts => 'Cochée d’elle-même quand plus aucune écriture n’attend au brouillard.',
            self::Statements => 'Cochée d’elle-même quand tous les relevés sont rapprochés.',
        };
    }

    /** The route of the screen the task is done on, null for a declared one. */
    public function route(): ?string
    {
        return match ($this) {
            self::Declared => null,
            self::Pieces => 'app_documents',
            self::Drafts => 'app_accounting_brouillard',
            self::Statements => 'app_bank_reconciliation',
        };
    }

    /** The module the screen belongs to (MissionModule), to draw the link only where it is open. */
    public function module(): ?MissionModule
    {
        return match ($this) {
            self::Declared => null,
            self::Pieces => MissionModule::Documents,
            self::Drafts => MissionModule::Accounting,
            self::Statements => MissionModule::Bank,
        };
    }
}
