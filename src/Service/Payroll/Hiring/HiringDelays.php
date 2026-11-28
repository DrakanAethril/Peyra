<?php

declare(strict_types=1);

namespace App\Service\Payroll\Hiring;

use App\Service\Regulatory\RegulatorySnapshot;

/**
 * The delays of the hiring formalities, read at a day (step 4.2b): how many days before the hire a
 * DPAE may be deposited at the earliest, and within how many months of the hire the information
 * and prevention visit takes place - an adult apprentice's sooner, a minor's before taking the post.
 */
final readonly class HiringDelays
{
    public function __construct(public int $dpaeEarliestDays, public int $visitMonths, public int $apprenticeVisitMonths)
    {
    }

    public static function from(RegulatorySnapshot $values): self
    {
        return new self(
            $values->integer('social.hiring.dpae_earliest_days'),
            $values->integer('social.hiring.information_visit_months', 'general'),
            $values->integer('social.hiring.information_visit_months', 'adult_apprentice'),
        );
    }
}
