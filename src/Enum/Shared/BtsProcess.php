<?php

declare(strict_types=1);

namespace App\Enum\Shared;

/**
 * The seven processes of the BTS CG référentiel (annexed to the arrêté of 3 November 2014 as
 * amended; plan, donnees/referentiel/bts-cg.yaml, read in the published text on 2026-11-27). A
 * template says which it covers (lot 1, step 1.8); missions, positioning and the passeport read
 * the activities under them (steps 2.8 and 6.2).
 */
enum BtsProcess: string
{
    case P1 = 'P1';
    case P2 = 'P2';
    case P3 = 'P3';
    case P4 = 'P4';
    case P5 = 'P5';
    case P6 = 'P6';
    case P7 = 'P7';

    /** The official wording. */
    public function label(): string
    {
        return match ($this) {
            self::P1 => 'Contrôle et traitement comptable des opérations commerciales',
            self::P2 => 'Contrôle et production de l’information financière',
            self::P3 => 'Gestion des obligations fiscales',
            self::P4 => 'Gestion des relations sociales',
            self::P5 => 'Analyse et prévision de l’activité',
            self::P6 => 'Analyse de la situation financière',
            self::P7 => 'Fiabilisation de l’information et système d’information comptable',
        };
    }
}
