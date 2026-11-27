<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\DirectoryGroupMapping;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Identity\AdministrationAction;
use App\Enum\Identity\ClassLevel;
use App\Repository\Identity\DirectoryGroupMappingRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Administration › Années scolaires (design-ux.md §§ 2.6 and 4.12): create a year, make one the
 * current one. Exactly one year is current once a year exists: the first one created is, and
 * making another current takes the flag off the others in the same gesture.
 *
 * Creating a year may take over the previous one's classes (same names and levels) and its mapping
 * with the directory, and name the next class of the previous year's first-year classes when the
 * new year has a single second-year class: the « Préparer l'année » of the rentrée.
 */
final readonly class SchoolYears
{
    /** A school year lasts a year or so: more than this is a typing error. */
    private const int MAX_DAYS = 400;

    public function __construct(
        private SchoolYearRepository $years,
        private SchoolClassRepository $classes,
        private DirectoryGroupMappingRepository $mappings,
        private AdministrationJournal $journal,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The dates the « Nouvelle année » form proposes: the day after the latest year ends, for a
     * year; else 1 September to 31 August around the given date.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    public function proposedDates(\DateTimeImmutable $today): array
    {
        $latest = $this->years->findAllNewestFirst()[0] ?? null;
        if (null !== $latest) {
            $start = $latest->getEndsOn()->modify('+1 day');

            return [$start, $start->modify('+1 year -1 day')];
        }
        $year = (int) $today->format('Y') - ((int) $today->format('n') < 8 ? 1 : 0);
        $start = new \DateTimeImmutable(\sprintf('%d-09-01', $year));

        return [$start, new \DateTimeImmutable(\sprintf('%d-08-31', $year + 1))];
    }

    /**
     * Creates a year; returns the refusal to show when the dates do not hold.
     *
     * @return SchoolYear|string the year, or the reason it was not created
     */
    public function create(?\DateTimeImmutable $start, ?\DateTimeImmutable $end, bool $takeOverPrevious, User $actor, \DateTimeImmutable $at): SchoolYear|string
    {
        if (null === $start || null === $end) {
            return 'Saisissez la date de début et la date de fin de l’année.';
        }
        if ($end <= $start) {
            return 'L’année doit finir après avoir commencé.';
        }
        if ((int) $start->diff($end)->days > self::MAX_DAYS) {
            return 'Une année scolaire dure un an au plus.';
        }
        $overlapping = $this->years->findOverlapping($start, $end);
        if (null !== $overlapping) {
            return \sprintf('Ces dates chevauchent l’année %s.', $overlapping->getLabel());
        }

        $year = new SchoolYear($start, $end, $at);
        $previous = $this->years->findPrevious($year);
        $first = null === $this->years->findCurrent();
        if ($first) {
            $year->markCurrent();
        }
        $this->entityManager->persist($year);
        $this->journal->record($at, $actor, AdministrationAction::SchoolYearCreated, $year->getLabel(), $year->getLabel(), $first ? 'Année courante' : null);

        if ($takeOverPrevious && null !== $previous) {
            $this->takeOver($previous, $year, $actor, $at);
        }
        $this->entityManager->flush();

        return $year;
    }

    public function makeCurrent(SchoolYear $year, User $actor, \DateTimeImmutable $at): void
    {
        if ($year->isCurrent()) {
            return;
        }
        $current = $this->years->findCurrent();
        $current?->unmarkCurrent();
        // The previous current year loses its flag first: the database sees one current year at a time.
        $this->entityManager->flush();
        $year->markCurrent();
        $this->journal->record($at, $actor, AdministrationAction::SchoolYearMadeCurrent, $year->getLabel(), $year->getLabel(), null === $current ? null : 'Auparavant '.$current->getLabel());
        $this->entityManager->flush();
    }

    /** The previous year's classes, mapping and next classes carried into a new year. */
    private function takeOver(SchoolYear $previous, SchoolYear $year, User $actor, \DateTimeImmutable $at): void
    {
        $created = [];
        foreach ($this->classes->findByYear($previous) as $old) {
            $class = new SchoolClass($year, $old->getName(), $old->getLevel(), $at);
            $this->entityManager->persist($class);
            $created[$old->getName()] = $class;
            $this->journal->record($at, $actor, AdministrationAction::ClassCreated, $class->getLabel(), $class->getLabel(), 'Reprise de '.$old->getLabel());
        }

        foreach ($this->mappings->findByYear($previous) as $group => $mapping) {
            $class = $created[$mapping->getSchoolClass()->getName()] ?? null;
            if (null !== $class) {
                $this->entityManager->persist(new DirectoryGroupMapping($group, $class));
            }
        }

        $secondYear = array_values(array_filter($created, static fn (SchoolClass $c): bool => ClassLevel::Second === $c->getLevel()));
        if (1 !== \count($secondYear)) {
            return;
        }
        foreach ($this->classes->findByYear($previous) as $old) {
            if (ClassLevel::First === $old->getLevel() && null === $old->getNextClass()) {
                $old->setNextClass($secondYear[0]);
                $this->journal->record($at, $actor, AdministrationAction::ClassUpdated, $old->getLabel(), $old->getLabel(), 'Classe suivante : '.$secondYear[0]->getLabel());
            }
        }
    }
}
