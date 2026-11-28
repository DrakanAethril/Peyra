<?php

declare(strict_types=1);

namespace App\Service\Payroll\Exit;

/**
 * The jobs an employee held one after the other, and the periods they held them (step 4.2c, the
 * work certificate: article D1234-6 du code du travail), pure: the dated terms of the contract read
 * in order, consecutive terms of the same job merged (a raise is not a new job), the last period
 * ending on the last day of the contract.
 *
 * @phpstan-type JobPeriod array{job: string, from: \DateTimeImmutable, to: \DateTimeImmutable}
 */
final class JobPeriods
{
    /**
     * @param list<array{from: \DateTimeImmutable, job: string}> $terms the contract's terms, from the first, on the day of hire, to the latest
     *
     * @return list<JobPeriod>
     */
    public static function of(array $terms, \DateTimeImmutable $lastDay): array
    {
        $periods = [];
        foreach ($terms as $index => $row) {
            if ($row['from'] > $lastDay) {
                break;
            }
            $next = $terms[$index + 1] ?? null;
            $to = null === $next || $next['from'] > $lastDay ? $lastDay : $next['from']->modify('-1 day');
            $last = array_key_last($periods);
            if (null !== $last && $periods[$last]['job'] === $row['job']) {
                $periods[$last]['to'] = $to;
            } else {
                $periods[] = ['job' => $row['job'], 'from' => $row['from'], 'to' => $to];
            }
        }

        return $periods;
    }
}
