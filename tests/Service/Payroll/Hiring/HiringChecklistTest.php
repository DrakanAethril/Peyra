<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Hiring;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\FormalityState;
use App\Service\Payroll\Hiring\HiringChecklist;
use App\Service\Payroll\Hiring\HiringDelays;
use PHPUnit\Framework\TestCase;

/**
 * Where hiring formalities stand (step 4.2b), on dates worked out by hand: a DPAE opens 8 days
 * before the hire, the visit falls 3 months after it (2 for an adult apprentice, before the post
 * for a minor), the dossier's first exercise opens on 01/01/2026.
 */
final class HiringChecklistTest extends TestCase
{
    private const string OPENING = '2026-01-01';

    public function testANewHireWithNothingDoneHasEverythingToDo(): void
    {
        // Hired 01/04/2026; the dossier's date 31/03/2026; two bodies, none affiliated.
        $list = $this->list('2026-04-01', ContractType::FixedTerm, '2001-05-09', null, null, [1 => null, 2 => null], '2026-03-31');

        self::assertTrue($list->followed);
        self::assertSame(FormalityState::ToDo, $list->dpae);
        self::assertSame('2026-03-24', $list->dpaeOpensOn->format('Y-m-d'), '01/04 minus 8 days.');
        self::assertSame(FormalityState::ToDo, $list->visit);
        self::assertSame('2026-07-01', $list->visitDeadline->format('Y-m-d'), '01/04 plus 3 months.');
        self::assertFalse($list->visitBeforePost);
        self::assertSame(2, $list->affiliationsMissing);
        self::assertSame(4, $list->openCount());
    }

    public function testDoneLateAndOverdue(): void
    {
        // DPAE on 30/03 for a hire on 01/04: done; the visit on 15/06, within 01/07: done; one body of two.
        $done = $this->list('2026-04-01', ContractType::FixedTerm, '2001-05-09', '2026-03-30', '2026-06-15', [1 => '2026-03-30', 2 => null], '2026-04-10');
        self::assertSame([FormalityState::Done, FormalityState::Done, 1, 1], [$done->dpae, $done->visit, $done->affiliationsMissing, $done->openCount()]);

        // DPAE on 02/04 for a hire on 01/04: late; the visit on 02/07, after 01/07: late. Neither is open.
        $late = $this->list('2026-04-01', ContractType::Permanent, '2001-05-09', '2026-04-02', '2026-07-02', [], '2026-08-01');
        self::assertSame([FormalityState::Late, FormalityState::Late, 0], [$late->dpae, $late->visit, $late->openCount()]);

        // Nothing done on 02/07: the DPAE is overdue since 02/04, the visit since 02/07.
        $overdue = $this->list('2026-04-01', ContractType::Permanent, '2001-05-09', null, null, [], '2026-07-02');
        self::assertSame([FormalityState::Overdue, FormalityState::Overdue, 2], [$overdue->dpae, $overdue->visit, $overdue->openCount()]);
        // On the day of hire itself, the DPAE is still to do; the day after, overdue.
        self::assertSame(FormalityState::ToDo, $this->list('2026-04-01', ContractType::Permanent, '2001-05-09', null, null, [], '2026-04-01')->dpae);
        self::assertSame(FormalityState::Overdue, $this->list('2026-04-01', ContractType::Permanent, '2001-05-09', null, null, [], '2026-04-02')->dpae);
    }

    public function testTheVisitOfAnApprenticeAndOfAMinor(): void
    {
        // An adult apprentice hired 01/09/2026: 2 months, 01/11/2026.
        $adult = $this->list('2026-09-01', ContractType::Apprenticeship, '2006-03-12', null, null, [], '2026-09-01');
        self::assertSame('2026-11-01', $adult->visitDeadline->format('Y-m-d'));

        // A minor (born 12/03/2010, 16 at hire): before taking the post, the day of hire; whatever the contract.
        $minor = $this->list('2026-09-01', ContractType::Apprenticeship, '2010-03-12', null, null, [], '2026-09-01');
        self::assertTrue($minor->visitBeforePost);
        self::assertSame('2026-09-01', $minor->visitDeadline->format('Y-m-d'));
        // 18 the day before the hire: an adult.
        self::assertSame('2026-12-01', $this->list('2026-09-01', ContractType::Permanent, '2008-08-31', null, null, [], '2026-09-01')->visitDeadline->format('Y-m-d'));
        // 18 the day after: still a minor at hire.
        self::assertSame('2026-09-01', $this->list('2026-09-01', ContractType::Permanent, '2008-09-02', null, null, [], '2026-09-01')->visitDeadline->format('Y-m-d'));
    }

    public function testMonthsAddedStopAtTheMonthsLastDay(): void
    {
        self::assertSame('2027-02-28', HiringChecklist::addMonths(new \DateTimeImmutable('2026-11-30'), 3)->format('Y-m-d'));
        self::assertSame('2028-02-29', HiringChecklist::addMonths(new \DateTimeImmutable('2027-11-30'), 3)->format('Y-m-d'));
        self::assertSame('2026-03-31', HiringChecklist::addMonths(new \DateTimeImmutable('2025-12-31'), 3)->format('Y-m-d'));
    }

    public function testAHireBeforeTheDossierIsNotFollowed(): void
    {
        $old = $this->list('2015-03-02', ContractType::Permanent, '1990-09-05', null, null, [1 => null], '2026-03-31');

        self::assertFalse($old->followed);
        self::assertSame([FormalityState::NotFollowed, FormalityState::NotFollowed, 0, 0], [$old->dpae, $old->visit, $old->affiliationsMissing, $old->openCount()]);
        // A dossier without an exercise follows nobody.
        self::assertFalse(HiringChecklist::of(new \DateTimeImmutable('2026-04-01'), ContractType::Permanent, new \DateTimeImmutable('2001-05-09'), null, null, null, [], new \DateTimeImmutable('2026-04-01'), new HiringDelays(8, 3, 2))->followed);
    }

    /** @param array<int, ?string> $affiliations */
    private function list(string $hiredOn, ContractType $contract, string $born, ?string $declaredOn, ?string $visitOn, array $affiliations, string $today): HiringChecklist
    {
        $day = static fn (?string $d): ?\DateTimeImmutable => null === $d ? null : new \DateTimeImmutable($d);

        return HiringChecklist::of(
            new \DateTimeImmutable($hiredOn),
            $contract,
            new \DateTimeImmutable($born),
            new \DateTimeImmutable(self::OPENING),
            $day($declaredOn),
            $day($visitOn),
            array_map($day, $affiliations),
            new \DateTimeImmutable($today),
            new HiringDelays(8, 3, 2),
        );
    }
}
