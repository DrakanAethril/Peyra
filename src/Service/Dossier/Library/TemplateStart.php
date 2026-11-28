<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Dossier\Company;
use App\Service\Accounting\Chart\ReferenceChart;

/** What a new template starts from, checked (TemplateFactory::prepare()): the company, the first exercise, the dossier's date, the reference chart. */
final readonly class TemplateStart
{
    public function __construct(
        public Company $company,
        public \DateTimeImmutable $exerciseStart,
        public \DateTimeImmutable $exerciseEnd,
        public \DateTimeImmutable $dossierDate,
        public ReferenceChart $chart,
    ) {
    }
}
