<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

/**
 * What the head and foot of an edited state say on every page (design-ui.md § 4.18 and
 * doc/_report.html.twig): the dossier's company, the state's title, the exercise, the period and
 * what it reads, the dossier's date - never the computer's -, and the student whose dossier it is.
 * Nothing about a mission (design-ux.md § 6.8).
 */
final readonly class ReportHeader
{
    public function __construct(
        public string $company,
        public string $title,
        public string $fiscalYear,
        public ?string $period,
        public \DateTimeImmutable $dossierDate,
        public ?string $student,
    ) {
    }
}
