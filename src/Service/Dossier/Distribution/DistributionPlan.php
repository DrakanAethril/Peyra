<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierProfile;
use App\Service\Shared\DateValue;

/**
 * What a distribution will make (design-ux.md § 4.2), a pure function of the class's students,
 * those who already hold a copy of the template and the teacher's choices: who is ticked - on the
 * first display everybody but those who already hold one, « signalé et décoché par défaut » -,
 * the groups (by default groups of about three in the list's order, as even as can be), the date
 * the dossiers start at, the faults that stop it and the sentence of the récapitulatif.
 */
final readonly class DistributionPlan
{
    /** The size the default groups aim at. */
    public const int GROUP_SIZE = 3;

    /**
     * @param list<DistributionLine> $lines
     * @param array<string, string>  $faults by field: students, groups, start
     */
    private function __construct(
        public Dossier $template,
        public SchoolClass $schoolClass,
        public DistributionMode $mode,
        public \DateTimeImmutable $startDate,
        public array $lines,
        public array $faults,
        public DistributionChoices $choices,
    ) {
    }

    /**
     * @param list<User> $students the class's, in its order
     * @param list<int>  $holders  the ids of those who already hold a dossier made from the template
     */
    public static function of(Dossier $template, SchoolClass $class, array $students, array $holders, DistributionChoices $choices): self
    {
        $faults = [];
        $ticked = [];
        foreach ($students as $student) {
            $id = $student->getId() ?? 0;
            $holds = \in_array($id, $holders, true);
            $ticked[$id] = null === $choices->picked ? !$holds : \in_array($id, $choices->picked, true);
        }
        $count = \count(array_filter($ticked));
        $groupCount = max(1, intdiv($count, self::GROUP_SIZE));

        $lines = [];
        $rank = 0;
        foreach ($students as $student) {
            $id = $student->getId() ?? 0;
            $default = $ticked[$id] ? intdiv($rank * $groupCount, max(1, $count)) + 1 : 1;
            if ($ticked[$id]) {
                ++$rank;
            }
            $group = $choices->groups[$id] ?? $default;
            $lines[] = new DistributionLine($student, \in_array($id, $holders, true), $ticked[$id], min(max(1, $group), max(1, $count)), $choices->profiles[$id] ?? null);
        }

        if (0 === $count) {
            $faults['students'] = 'Cochez au moins un étudiant.';
        }
        if (DistributionMode::Group === $choices->mode) {
            foreach (self::grouped($lines) as $number => $members) {
                if (1 === \count($members)) {
                    $faults['groups'] = \sprintf('Le groupe %d n’a qu’un membre : un dossier partagé réunit au moins deux étudiants.', $number);
                    break;
                }
            }
        }
        $start = $template->getDossierDate();
        if ('' !== $choices->start) {
            $parsed = DateValue::parse($choices->start);
            if (null === $parsed) {
                $faults['start'] = 'Saisissez la date de départ au format JJ/MM/AAAA.';
            } else {
                $start = $parsed;
            }
        }

        return new self($template, $class, $choices->mode, $start, $lines, $faults, $choices);
    }

    public function isValid(): bool
    {
        return [] === $this->faults;
    }

    public function fault(string $field): ?string
    {
        return $this->faults[$field] ?? null;
    }

    /** @return list<DistributionLine> */
    public function ticked(): array
    {
        return array_values(array_filter($this->lines, static fn (DistributionLine $line): bool => $line->ticked));
    }

    /**
     * The groups' dossiers, by group number in ascending order: « Groupe 2 » and its members.
     *
     * @return list<array{name: string, members: list<array{0: User, 1: DossierProfile|null}>}>
     */
    public function groups(): array
    {
        $groups = [];
        foreach (self::grouped($this->lines) as $number => $members) {
            $groups[] = [
                'name' => 'Groupe '.$number,
                'members' => array_map(static fn (DistributionLine $line): array => [$line->student, $line->profile], $members),
            ];
        }

        return $groups;
    }

    /** How many dossiers it makes. */
    public function dossierCount(): int
    {
        return DistributionMode::Group === $this->mode ? \count($this->groups()) : \count($this->ticked());
    }

    /** The ticked students who already hold a dossier made from the template. */
    public function holdersTicked(): int
    {
        return \count(array_filter($this->ticked(), static fn (DistributionLine $line): bool => $line->holds));
    }

    /** The récapitulatif's sentence: « 6 dossiers seront créés depuis « Vasseur », un par étudiant, au 31/01/2026. » */
    public function sentence(): string
    {
        $dossiers = $this->dossierCount();
        $students = \count($this->ticked());
        $date = $this->startDate->format('d/m/Y');
        $title = $this->template->getLibraryTitle();
        if (DistributionMode::Group === $this->mode) {
            return \sprintf(
                '%d %s depuis « %s » pour %d %s, à la date du %s.',
                $dossiers,
                $dossiers > 1 ? 'dossiers partagés seront créés' : 'dossier partagé sera créé',
                $title,
                $students,
                $students > 1 ? 'étudiants' : 'étudiant',
                $date,
            );
        }

        return \sprintf(
            '%d %s depuis « %s », un par étudiant, à la date du %s.',
            $dossiers,
            $dossiers > 1 ? 'dossiers seront créés' : 'dossier sera créé',
            $title,
            $date,
        );
    }

    /**
     * @param list<DistributionLine> $lines
     *
     * @return array<int, list<DistributionLine>> the ticked lines by group number, ascending
     */
    private static function grouped(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            if ($line->ticked) {
                $groups[$line->group][] = $line;
            }
        }
        ksort($groups);

        return $groups;
    }
}
