<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\LoginEvent;
use App\Enum\Identity\LoginOutcome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LoginEvent> */
final class LoginEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginEvent::class);
    }

    /**
     * A page of the journal, newest first. `$refused`: null for every line, true for the refusals
     * only, false for the successes only. `$search` matches the login typed or the name.
     *
     * @return list<LoginEvent>
     */
    public function findPage(?bool $refused, string $search, int $page, int $perPage): array
    {
        /* @var list<LoginEvent> */
        return $this->filtered($refused, $search)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(?bool $refused, string $search): int
    {
        return (int) $this->filtered($refused, $search)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function filtered(?bool $refused, string $search): QueryBuilder
    {
        $builder = $this->createQueryBuilder('e');
        if (true === $refused) {
            $builder->andWhere('e.outcome <> :success')->setParameter('success', LoginOutcome::Success);
        } elseif (false === $refused) {
            $builder->andWhere('e.outcome = :success')->setParameter('success', LoginOutcome::Success);
        }
        if ('' !== $search) {
            $builder->andWhere('LOWER(e.login) LIKE :like OR LOWER(e.displayName) LIKE :like')
                ->setParameter('like', '%'.addcslashes(mb_strtolower($search), '%_\\').'%');
        }

        return $builder;
    }
}
