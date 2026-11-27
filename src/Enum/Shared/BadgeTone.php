<?php

declare(strict_types=1);

namespace App\Enum\Shared;

/**
 * The closed list of tones a pill may take (design-ui.md § 4.9). Green is for validated states
 * and nothing else: an open, in-progress or informative state is `Info`, never `Success`.
 */
enum BadgeTone: string
{
    /** Validated, check passed. */
    case Success = 'success';

    /** To be done by the person, to check. */
    case Warning = 'warning';

    /** Error, blocking, late. */
    case Danger = 'danger';

    /** In progress, information, a nature. */
    case Info = 'info';

    /** Not started, closed, inactive. */
    case Neutral = 'neutral';
}
