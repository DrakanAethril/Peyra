<?php

declare(strict_types=1);

namespace App\Service\Dossier;

/**
 * The dossier's own clock (conception § 4, « Date du dossier »): every business calculation of a
 * dossier asks it the day, never the server's clock (tests/Architecture/NoClockReadingTest). It
 * reads the dossier of the current context; a teacher moves it (DossierDates).
 */
final readonly class DossierClock
{
    public function __construct(private DossierContext $context)
    {
    }

    /** The current dossier's date, at midnight. */
    public function today(): \DateTimeImmutable
    {
        return $this->context->current()->getDossierDate();
    }
}
