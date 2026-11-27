<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * How much the tool helps and stops a student (design-ux.md §§ 1.2 A20 and 3.4, « Niveau
 * d'assistance »): guided (G), blocking controls (B), warnings (A), no safety net (0). A mission
 * chooses (step 2.8); « Avertissements » is the default. It changes what is proposed and what is
 * stopped, never the layout.
 */
enum AssistanceLevel: string
{
    case Guided = 'guided';
    case Blocking = 'blocking';
    case Warnings = 'warnings';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Guided => 'Guidé',
            self::Blocking => 'Contrôles bloquants',
            self::Warnings => 'Avertissements',
            self::None => 'Aucun filet',
        };
    }

    /** Whether a control stops the entry (G and B) rather than warning (A); level 0 runs none. */
    public function blocks(): bool
    {
        return self::Guided === $this || self::Blocking === $this;
    }

    public function controls(): bool
    {
        return self::None !== $this;
    }
}
