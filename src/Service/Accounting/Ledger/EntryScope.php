<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

/**
 * Which entries a reading adds up (design-ux.md § 3.2, « brouillard inclus ou non »): the validated
 * ones only - the books as they stand for good - or the brouillard with them, what a person sees
 * while working (the picker's balance, a third party's outstanding amount).
 */
enum EntryScope: string
{
    case Validated = 'validated';
    case WithDrafts = 'with_drafts';
}
