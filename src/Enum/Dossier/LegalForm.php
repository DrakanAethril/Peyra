<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/**
 * The legal form of the dossier's company (fiche société). The forms a BTS CG dossier meets; the
 * short name follows the company's name (« Menuiserie Vasseur SARL »).
 */
enum LegalForm: string
{
    case Sarl = 'sarl';
    case Eurl = 'eurl';
    case Sas = 'sas';
    case Sasu = 'sasu';
    case Sa = 'sa';
    case Snc = 'snc';
    case Ei = 'ei';
    case Association = 'association';

    /** « SARL » */
    public function shortName(): string
    {
        return match ($this) {
            self::Sarl => 'SARL',
            self::Eurl => 'EURL',
            self::Sas => 'SAS',
            self::Sasu => 'SASU',
            self::Sa => 'SA',
            self::Snc => 'SNC',
            self::Ei => 'EI',
            self::Association => 'Association',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Sarl => 'SARL · société à responsabilité limitée',
            self::Eurl => 'EURL · entreprise unipersonnelle à responsabilité limitée',
            self::Sas => 'SAS · société par actions simplifiée',
            self::Sasu => 'SASU · société par actions simplifiée unipersonnelle',
            self::Sa => 'SA · société anonyme',
            self::Snc => 'SNC · société en nom collectif',
            self::Ei => 'EI · entreprise individuelle',
            self::Association => 'Association loi 1901',
        };
    }

    /** An entreprise individuelle and an association have no share capital. */
    public function hasCapital(): bool
    {
        return !\in_array($this, [self::Ei, self::Association], true);
    }
}
