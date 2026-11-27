<?php

declare(strict_types=1);

namespace App\Enum\Regulatory;

/** Where a dated value comes from: a millésime file of the repository, or an administrator. */
enum RegulatoryOrigin: string
{
    /** config/regulatory/<année>.yaml, installed by app:regulatory:sync. */
    case File = 'file';

    /** « Nouvelle valeur datée », Paramètres › Paramètres réglementaires. */
    case Administrator = 'administrator';
}
