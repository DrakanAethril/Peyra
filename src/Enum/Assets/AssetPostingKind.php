<?php

declare(strict_types=1);

namespace App\Enum\Assets;

/**
 * An entry the fixed assets module asked the accounting gateway for (step 3.4b): the acquisition or
 * the production, the dotations up to a day (with the dérogatoire, and the impairment at the end of
 * an exercise), the way out (with the dotation up to its day).
 */
enum AssetPostingKind: string
{
    case Acquisition = 'acquisition';
    case Dotation = 'dotation';
    case Impairment = 'impairment';
    case Disposal = 'disposal';
}
