<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Doctrine\Filter\DossierFilter;
use App\Entity\Dossier\Dossier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The dossier a request or a command works in (README of the plan, § 8): the only writer of the
 * Doctrine filter `dossier`. App\Security\DossierRouteListener enters the dossier of a route's
 * `{dossier}` once DossierVoter has let the person in; a command enters the one it works on.
 * `within()` works in another dossier for a moment, `across()` reads several at once (a listing of
 * dossiers the caller already has the right to read). Reset between two requests of the worker:
 * the next one starts with no context, and a DossierOwned read throws until it enters one.
 */
final class DossierContext implements ResetInterface
{
    /** @var list<Dossier> */
    private array $dossiers = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function enter(Dossier $dossier): void
    {
        $this->apply([$dossier]);
    }

    public function has(): bool
    {
        return 1 === \count($this->dossiers);
    }

    /** The dossier of the context; throws when there is none, or several. */
    public function current(): Dossier
    {
        if (1 !== \count($this->dossiers)) {
            throw new \LogicException('No single dossier in context.');
        }

        return $this->dossiers[0];
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function within(Dossier $dossier, callable $work): mixed
    {
        return $this->scoped([$dossier], $work);
    }

    /**
     * Reads several dossiers at once - never a way around DossierVoter: the caller passes dossiers
     * it was given the right to read.
     *
     * @template T
     *
     * @param list<Dossier>  $dossiers
     * @param callable(): T $work
     *
     * @return T
     */
    public function across(array $dossiers, callable $work): mixed
    {
        return $this->scoped($dossiers, $work);
    }

    public function leave(): void
    {
        $this->dossiers = [];
        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled(DossierFilter::NAME)) {
            // A filter keeps its parameters: only a new instance forgets them.
            $filters->disable(DossierFilter::NAME);
        }
        $filters->enable(DossierFilter::NAME);
    }

    public function reset(): void
    {
        $this->leave();
    }

    /**
     * @template T
     *
     * @param list<Dossier>  $dossiers
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(array $dossiers, callable $work): mixed
    {
        $previous = $this->dossiers;
        $this->apply($dossiers);
        try {
            return $work();
        } finally {
            if ([] === $previous) {
                $this->leave();
            } else {
                $this->apply($previous);
            }
        }
    }

    /** @param list<Dossier> $dossiers */
    private function apply(array $dossiers): void
    {
        $ids = [];
        foreach ($dossiers as $dossier) {
            $ids[] = $dossier->getId() ?? throw new \LogicException('A dossier enters a context once it is saved.');
        }
        if ([] === $ids) {
            // An empty list reads nothing rather than everything.
            $ids = [0];
        }
        $this->dossiers = $dossiers;
        $filters = $this->entityManager->getFilters();
        $filter = $filters->isEnabled(DossierFilter::NAME) ? $filters->getFilter(DossierFilter::NAME) : $filters->enable(DossierFilter::NAME);
        $filter->setParameterList(DossierFilter::PARAMETER, $ids, Types::INTEGER);
    }
}
