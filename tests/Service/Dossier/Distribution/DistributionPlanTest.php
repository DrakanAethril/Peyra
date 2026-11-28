<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Distribution;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolClass;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Enum\Identity\ClassLevel;
use App\Service\Dossier\Distribution\DistributionChoices;
use App\Service\Dossier\Distribution\DistributionPlan;
use App\Tests\Support\DossierFixtures;
use App\Tests\Support\IdentityFixtures;
use PHPUnit\Framework\TestCase;

/**
 * What a distribution makes (design-ux.md § 4.2), a pure function: who is ticked, the default
 * groups, the faults that stop it and the récapitulatif's sentence, on cases written by hand.
 */
final class DistributionPlanTest extends TestCase
{
    private Dossier $template;
    private SchoolClass $class;

    protected function setUp(): void
    {
        $this->template = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-01-31'), null, null, new \DateTimeImmutable('2026-11-28'), null);
        $this->template->describeInLibrary('Vasseur, à-nouveaux 2026', null, null, []);
        $this->class = IdentityFixtures::schoolClass(5, IdentityFixtures::year(1, 2026, true), 'BTS CG 1', ClassLevel::First);
    }

    public function testOnTheFirstDisplayEverybodyButTheHoldersIsTicked(): void
    {
        $plan = $this->plan(4, [2], DistributionChoices::first());

        self::assertSame([101 => true, 102 => false, 103 => true, 104 => true], $this->ticks($plan));
        self::assertTrue($plan->lines[1]->holds);
        self::assertSame(3, $plan->dossierCount());
        self::assertSame(0, $plan->holdersTicked());
        self::assertSame('3 dossiers seront créés depuis « Vasseur, à-nouveaux 2026 », un par étudiant, à la date du 31/01/2026.', $plan->sentence());
        self::assertTrue($plan->isValid());
    }

    public function testTheTeacherTicksAHolderAgainAndChangesTheDate(): void
    {
        $plan = $this->plan(3, [2], new DistributionChoices([102], DistributionMode::Individual, [], [], '2026-03-01'));

        self::assertSame([101 => false, 102 => true, 103 => false], $this->ticks($plan));
        self::assertSame(1, $plan->holdersTicked());
        self::assertSame('1 dossier sera créé depuis « Vasseur, à-nouveaux 2026 », un par étudiant, à la date du 01/03/2026.', $plan->sentence());
    }

    public function testDefaultGroupsAreOfAboutThreeAsEvenAsCanBe(): void
    {
        $choices = new DistributionChoices(null, DistributionMode::Group, [], [], '');
        // 7 students: two groups, 4 and 3.
        self::assertSame([[101, 102, 103, 104], [105, 106, 107]], $this->members($this->plan(7, [], $choices)));
        // 6 students: 3 and 3; 5 students: one group of 5; 2 students: one group of 2.
        self::assertSame([[101, 102, 103], [104, 105, 106]], $this->members($this->plan(6, [], $choices)));
        self::assertSame([[101, 102, 103, 104, 105]], $this->members($this->plan(5, [], $choices)));
        self::assertSame([[101, 102]], $this->members($this->plan(2, [], $choices)));
    }

    public function testGroupsAndProfilesChosenByTheTeacher(): void
    {
        $plan = $this->plan(4, [], new DistributionChoices(
            [101, 102, 103, 104],
            DistributionMode::Group,
            [101 => 2, 102 => 1, 103 => 2, 104 => 1],
            [101 => DossierProfile::SupplierAccountant, 103 => DossierProfile::ChiefAccountant],
            '',
        ));

        $groups = $plan->groups();
        self::assertSame(['Groupe 1', 'Groupe 2'], array_column($groups, 'name'));
        self::assertSame([[102, 104], [101, 103]], $this->members($plan));
        self::assertSame([DossierProfile::SupplierAccountant, DossierProfile::ChiefAccountant], array_map(static fn (array $member): ?DossierProfile => $member[1], $groups[1]['members']));
        self::assertSame('2 dossiers partagés seront créés depuis « Vasseur, à-nouveaux 2026 » pour 4 étudiants, à la date du 31/01/2026.', $plan->sentence());
    }

    public function testWhatStopsADistribution(): void
    {
        $nobody = $this->plan(3, [], new DistributionChoices([], DistributionMode::Individual, [], [], 'demain'));
        self::assertSame([
            'students' => 'Cochez au moins un étudiant.',
            'start' => 'Saisissez la date de départ au format JJ/MM/AAAA.',
        ], $nobody->faults);

        $alone = $this->plan(3, [], new DistributionChoices([101, 102, 103], DistributionMode::Group, [101 => 1, 102 => 1, 103 => 2], [], ''));
        self::assertSame(['groups' => 'Le groupe 2 n’a qu’un membre : un dossier partagé réunit au moins deux étudiants.'], $alone->faults);
        self::assertFalse($alone->isValid());
    }

    public function testChoicesTravelThroughTheQueryString(): void
    {
        $choices = new DistributionChoices([101, 103], DistributionMode::Group, [101 => 1, 103 => 1], [101 => DossierProfile::PayrollManager, 103 => null], '2026-02-01');

        self::assertSame([
            'step' => 1,
            'pick' => [101, 103],
            'mode' => 'group',
            'group' => [101 => 1, 103 => 1],
            'profile' => [101 => 'payroll_manager'],
            'start' => '2026-02-01',
        ], $choices->query());
    }

    /** @param list<int> $holders indexes (1-based) of the students who hold a copy */
    private function plan(int $students, array $holders, DistributionChoices $choices): DistributionPlan
    {
        $list = [];
        for ($i = 1; $i <= $students; ++$i) {
            $list[] = IdentityFixtures::student(100 + $i, 'Étudiant '.$i);
        }

        return DistributionPlan::of($this->template, $this->class, $list, array_map(static fn (int $i): int => 100 + $i, $holders), $choices);
    }

    /** @return array<int, bool> */
    private function ticks(DistributionPlan $plan): array
    {
        $ticks = [];
        foreach ($plan->lines as $line) {
            $ticks[$line->studentId()] = $line->ticked;
        }

        return $ticks;
    }

    /** @return list<list<int>> */
    private function members(DistributionPlan $plan): array
    {
        return array_map(static fn (array $group): array => array_map(static fn (array $member): int => (int) $member[0]->getId(), $group['members']), $plan->groups());
    }
}
