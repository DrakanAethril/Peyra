<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Repository\Dossier\ExerciseRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door that opens an exercise in a dossier: its periods with it (PeriodPlan), never over
 * another exercise. Works inside the dossier's context; the caller flushes.
 */
final readonly class Exercises
{
    public function __construct(private EntityManagerInterface $entityManager, private ExerciseRepository $exercises, private DossierContext $context)
    {
    }

    /**
     * `$closed`: an exercise already over when the dossier is made (the installation of a dossier
     * that starts after a closed year) - itself and every period closed.
     *
     * @throws \InvalidArgumentException when the bounds make no exercise or meet another one
     */
    public function open(Dossier $dossier, string $code, \DateTimeImmutable $start, \DateTimeImmutable $end, bool $closed = false): Exercise
    {
        $plan = PeriodPlan::monthly($start, $end);

        return $this->context->within($dossier, function () use ($dossier, $code, $start, $end, $plan, $closed): Exercise {
            if (null !== $this->exercises->findOverlapping($dossier, $start, $end)) {
                throw new \InvalidArgumentException('Exercises of a dossier do not overlap.');
            }
            foreach ($this->pending($dossier) as $pending) {
                if ($pending->getStartsOn() <= $end && $pending->getEndsOn() >= $start) {
                    throw new \InvalidArgumentException('Exercises of a dossier do not overlap.');
                }
            }
            $exercise = new Exercise($dossier, $code, $start, $end);
            if ($closed) {
                $exercise->close();
            }
            $this->entityManager->persist($exercise);
            foreach ($plan as [$first, $last]) {
                $period = new Period($exercise, $first, $last);
                if ($closed) {
                    $period->close();
                }
                $this->entityManager->persist($period);
            }

            return $exercise;
        });
    }

    /** @return list<Exercise> exercises of the dossier persisted but not flushed yet */
    private function pending(Dossier $dossier): array
    {
        $pending = [];
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Exercise && $entity->getDossier() === $dossier) {
                $pending[] = $entity;
            }
        }

        return $pending;
    }
}
