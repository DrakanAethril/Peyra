<?php

declare(strict_types=1);

namespace App\Service\Payroll\Hiring;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\FormalityState;

/**
 * Where an employee's hiring formalities stand on a day (step 4.2b), pure:
 *
 * - **followed** only for an employee hired on or after the first day of the dossier's first
 *   exercise - an older hire's formalities were made before the dossier existed (NotFollowed);
 * - **DPAE**: deposited on or before the day of hire is done, after it late; not deposited, to do
 *   until the day of hire, overdue after it. It opens `dpaeEarliestDays` before the hire;
 * - **information and prevention visit**: due before taking the post for a minor (under 18 at
 *   hire), within `apprenticeVisitMonths` for an adult apprentice, `visitMonths` otherwise; a day
 *   recorded on or before the deadline is done, after it late; none, to do, then overdue;
 * - **affiliations**: one per collecting body of the dossier, done once dated.
 */
final readonly class HiringChecklist
{
    /** The civil majority (article 414 du code civil): younger, the visit precedes the post. */
    public const int MAJORITY = 18;

    /**
     * @param array<int, ?\DateTimeImmutable> $affiliations by body id, the day of affiliation or null
     */
    private function __construct(
        public bool $followed,
        public FormalityState $dpae,
        public \DateTimeImmutable $dpaeOpensOn,
        public FormalityState $visit,
        public \DateTimeImmutable $visitDeadline,
        public bool $visitBeforePost,
        public array $affiliations,
        public int $affiliationsMissing,
    ) {
    }

    /**
     * @param ?\DateTimeImmutable              $firstExerciseStart the dossier's first exercise's first day; null: none, nothing followed
     * @param ?\DateTimeImmutable              $declaredOn         the DPAE's deposit, if any
     * @param array<int, ?\DateTimeImmutable> $affiliations       by body id of the dossier, the day of affiliation or null
     */
    public static function of(
        \DateTimeImmutable $hiredOn,
        ContractType $contract,
        \DateTimeImmutable $birthDate,
        ?\DateTimeImmutable $firstExerciseStart,
        ?\DateTimeImmutable $declaredOn,
        ?\DateTimeImmutable $visitOn,
        array $affiliations,
        \DateTimeImmutable $today,
        HiringDelays $delays,
    ): self {
        $followed = null !== $firstExerciseStart && $hiredOn >= $firstExerciseStart;
        $deadline = self::visitDeadline($hiredOn, $contract, $birthDate, $delays);
        $missing = \count(array_filter($affiliations, static fn (?\DateTimeImmutable $day): bool => null === $day));

        return new self(
            $followed,
            $followed ? self::state($declaredOn, $hiredOn, $today) : FormalityState::NotFollowed,
            $hiredOn->modify(\sprintf('-%d days', $delays->dpaeEarliestDays)),
            $followed ? self::state($visitOn, $deadline, $today) : FormalityState::NotFollowed,
            $deadline,
            $deadline == $hiredOn,
            $affiliations,
            $followed ? $missing : 0,
        );
    }

    /** The last day of the information and prevention visit. */
    public static function visitDeadline(\DateTimeImmutable $hiredOn, ContractType $contract, \DateTimeImmutable $birthDate, HiringDelays $delays): \DateTimeImmutable
    {
        if ($birthDate->diff($hiredOn)->y < self::MAJORITY) {
            return $hiredOn;
        }

        return self::addMonths($hiredOn, ContractType::Apprenticeship === $contract ? $delays->apprenticeVisitMonths : $delays->visitMonths);
    }

    /** The same day so many months later, or that month's last day when it has no such day (30/11 + 3 months = 28/02). */
    public static function addMonths(\DateTimeImmutable $day, int $months): \DateTimeImmutable
    {
        $first = $day->modify('first day of this month')->modify(\sprintf('+%d months', $months));
        $last = (int) $first->format('t');

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $day->format('j'), $last));
    }

    /** How many formalities are still open: the DPAE, the visit, each missing affiliation. */
    public function openCount(): int
    {
        return ($this->dpae->isOpen() ? 1 : 0) + ($this->visit->isOpen() ? 1 : 0) + $this->affiliationsMissing;
    }

    private static function state(?\DateTimeImmutable $doneOn, \DateTimeImmutable $deadline, \DateTimeImmutable $today): FormalityState
    {
        if (null !== $doneOn) {
            return $doneOn > $deadline ? FormalityState::Late : FormalityState::Done;
        }

        return $today > $deadline ? FormalityState::Overdue : FormalityState::ToDo;
    }
}
