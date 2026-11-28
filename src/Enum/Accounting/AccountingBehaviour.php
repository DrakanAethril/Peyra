<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * What the engine does when a module asks for an entry (design-ux.md § 3.4, « Comportement
 * comptable des modules »): it proposes it, to be checked and confirmed; it lets the student
 * enter it, the grid empty; or it posts it on its own to the brouillard, and the student checks
 * after (lot 2's rule, design-ux.md § 3.5: « écriture déjà au brouillard »).
 * A mission chooses (step 2.9); « Proposer » is the default.
 */
enum AccountingBehaviour: string
{
    case Propose = 'propose';
    case LetEnter = 'let_enter';
    case Post = 'post';

    public function label(): string
    {
        return match ($this) {
            self::Propose => 'Proposer',
            self::LetEnter => 'Laisser saisir',
            self::Post => 'Comptabiliser',
        };
    }
}
