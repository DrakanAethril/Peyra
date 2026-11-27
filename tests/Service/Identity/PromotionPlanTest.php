<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity;

use App\Entity\Identity\Enrolment;
use App\Enum\Identity\ClassLevel;
use App\Enum\Identity\EnrolmentOrigin;
use App\Service\Identity\PromotionChoice;
use App\Service\Identity\PromotionLine;
use App\Service\Identity\PromotionPlan;
use App\Tests\Support\IdentityFixtures as F;
use PHPUnit\Framework\TestCase;

/** The summary of a passage de classe (design-ux.md § 4.12, step 4), counted by hand. */
final class PromotionPlanTest extends TestCase
{
    public function testTheSummaryCountsWhatChanges(): void
    {
        $last = F::year(1, 2026);
        $new = F::year(2, 2027);
        $cg1 = F::schoolClass(10, $last, 'CG1', ClassLevel::First);
        $nextCg2 = F::schoolClass(20, $new, 'CG2', ClassLevel::Second);
        $nextCg1 = F::schoolClass(21, $new, 'CG1', ClassLevel::First);
        $cg1->setNextClass($nextCg2);

        $lines = [];
        // 21 pass, 2 repeat, 1 not placed, 1 leaves, 1 already enrolled in the new year.
        for ($i = 1; $i <= 21; ++$i) {
            $lines[] = new PromotionLine(F::student($i, 'Étudiant '.$i), PromotionChoice::next(), $nextCg2, null);
        }
        $lines[] = new PromotionLine(F::student(22, 'Redoublant Un'), PromotionChoice::otherClass(21), $nextCg1, null);
        $lines[] = new PromotionLine(F::student(23, 'Redoublant Deux'), PromotionChoice::otherClass(21), $nextCg1, null);
        $lines[] = new PromotionLine(F::student(24, 'Non Placé'), PromotionChoice::none(), null, null);
        $lines[] = new PromotionLine(F::student(25, 'Parti Ailleurs'), PromotionChoice::departed(), null, null);
        $already = F::student(26, 'Déjà Inscrit');
        $lines[] = new PromotionLine($already, PromotionChoice::next(), $nextCg2, new Enrolment($already, $nextCg1, EnrolmentOrigin::Manual, new \DateTimeImmutable('2027-09-02'), null));

        $plan = new PromotionPlan($cg1, $nextCg2, $lines);

        self::assertSame([[$nextCg2, 21, false], [$nextCg1, 2, true]], array_map(static fn (array $g): array => [$g['class'], $g['count'], $g['repeat']], $plan->byClass()));
        self::assertSame(1, $plan->unplacedCount());
        self::assertSame(1, $plan->departedCount());
        self::assertSame(1, $plan->alreadyCount());
        self::assertFalse($plan->isEmpty());
        self::assertSame('21 passent en CG2 · 2027-2028, 2 redoublent en CG1 · 2027-2028, 1 n’est pas placé, 1 sort de la filière.', $plan->sentence());
    }

    public function testAPlanWhoseStudentsAreAllEnrolledChangesNothing(): void
    {
        $cg1 = F::schoolClass(10, F::year(1, 2026), 'CG1', ClassLevel::First);
        $cg2 = F::schoolClass(20, F::year(2, 2027), 'CG2', ClassLevel::Second);
        $student = F::student(1, 'Lucas Bernard');
        $plan = new PromotionPlan($cg1, $cg2, [new PromotionLine($student, PromotionChoice::next(), $cg2, new Enrolment($student, $cg2, EnrolmentOrigin::Promotion, new \DateTimeImmutable('2027-09-01'), null))]);

        self::assertTrue($plan->isEmpty());
        self::assertSame('Aucun changement : tous les étudiants sont déjà inscrits dans l’année.', $plan->sentence());
    }

    public function testAClassWithoutStudentsSaysSoRatherThanThatEverybodyIsEnrolled(): void
    {
        $cg1 = F::schoolClass(10, F::year(1, 2026), 'CG1', ClassLevel::First);
        $cg2 = F::schoolClass(20, F::year(2, 2027), 'CG2', ClassLevel::Second);
        $plan = new PromotionPlan($cg1, $cg2, []);

        self::assertTrue($plan->isEmpty());
        self::assertSame('Personne dans cette classe : aucun étudiant à faire passer.', $plan->sentence());
    }

    public function testASecondYearClassGoingToAnotherSecondYearClassDoesNotRepeat(): void
    {
        $cg1 = F::schoolClass(10, F::year(1, 2026), 'CG1', ClassLevel::First);
        $new = F::year(2, 2027);
        $cg2a = F::schoolClass(20, $new, 'CG2 A', ClassLevel::Second);
        $cg2b = F::schoolClass(21, $new, 'CG2 B', ClassLevel::Second);
        $plan = new PromotionPlan($cg1, $cg2a, [new PromotionLine(F::student(1, 'Une Étudiante'), PromotionChoice::otherClass(21), $cg2b, null)]);

        self::assertSame('1 passe en CG2 B · 2027-2028.', $plan->sentence());
    }

    public function testTheChoiceOfARowReadsTheFormAsItCame(): void
    {
        self::assertSame(PromotionChoice::NEXT, PromotionChoice::fromForm(true, 'departed')->kind);
        self::assertSame(PromotionChoice::DEPARTED, PromotionChoice::fromForm(false, 'departed')->kind);
        self::assertSame(12, PromotionChoice::fromForm(false, '12')->classId);
        self::assertSame(PromotionChoice::NONE, PromotionChoice::fromForm(false, 'none')->kind);
        self::assertSame(PromotionChoice::NONE, PromotionChoice::fromForm(false, '0')->kind);
        self::assertSame(PromotionChoice::NONE, PromotionChoice::fromForm(false, 'n’importe quoi')->kind);
        self::assertSame('12', PromotionChoice::otherClass(12)->formValue());
        self::assertSame('none', PromotionChoice::none()->formValue());
    }
}
