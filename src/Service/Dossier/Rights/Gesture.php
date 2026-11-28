<?php

declare(strict_types=1);

namespace App\Service\Dossier\Rights;

use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\AccessLevel;
use App\Enum\Dossier\DossierArea;

/**
 * The verb and its object a refusal names (« valider le journal de banque »): ProfileMatrix::refusal()
 * puts it after « Votre profil “…” ne permet pas de ».
 */
final class Gesture
{
    public static function journal(JournalType $type, AccessLevel $level): string
    {
        return match ($level) {
            AccessLevel::Closed, AccessLevel::Read => 'consulter le '.$type->phrase(),
            AccessLevel::Enter => 'saisir dans le '.$type->phrase(),
            AccessLevel::Validate => 'valider le '.$type->phrase(),
        };
    }

    public static function settings(): string
    {
        return 'modifier les paramètres du dossier';
    }

    public static function lettering(DossierArea $area): string
    {
        return match ($area) {
            DossierArea::Purchases => 'lettrer les comptes des fournisseurs',
            DossierArea::Sales => 'lettrer les comptes des clients',
            default => 'lettrer ce compte',
        };
    }
}
