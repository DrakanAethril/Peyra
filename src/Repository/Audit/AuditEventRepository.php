<?php

declare(strict_types=1);

namespace App\Repository\Audit;

use App\Entity\Audit\AuditEvent;
use App\Enum\Audit\AuditAction;
use App\Service\Audit\DossierTrailCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditEvent> */
final class AuditEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEvent::class);
    }

    /** @return list<AuditEvent> a page of a dossier's trail, newest first */
    public function findDossierPage(int $dossierId, DossierTrailCriteria $criteria, int $offset, int $limit): array
    {
        /* @var list<AuditEvent> */
        return $this->dossierQuery($dossierId, $criteria)
            ->orderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<AuditEvent> the whole filter, for an export */
    public function findDossierAll(int $dossierId, DossierTrailCriteria $criteria): array
    {
        /* @var list<AuditEvent> */
        return $this->dossierQuery($dossierId, $criteria)->orderBy('e.id', 'DESC')->getQuery()->getResult();
    }

    public function countDossier(int $dossierId, DossierTrailCriteria $criteria): int
    {
        return (int) $this->dossierQuery($dossierId, $criteria)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
    }

    /** @return list<AuditAction> the gestures the dossier's trail holds, in the enum's order */
    public function actionsOfDossier(int $dossierId): array
    {
        $values = $this->createQueryBuilder('e')
            ->select('DISTINCT e.action AS action')
            ->andWhere('e.dossierId = :dossier')
            ->setParameter('dossier', $dossierId)
            ->getQuery()
            ->getSingleColumnResult();
        $held = [];
        foreach ($values as $value) {
            $action = $value instanceof AuditAction ? $value : (\is_string($value) ? AuditAction::tryFrom($value) : null);
            if (null !== $action) {
                $held[] = $action;
            }
        }

        return array_values(array_filter(AuditAction::cases(), static fn (AuditAction $action): bool => \in_array($action, $held, true)));
    }

    private function dossierQuery(int $dossierId, DossierTrailCriteria $criteria): QueryBuilder
    {
        $query = $this->createQueryBuilder('e')
            ->andWhere('e.dossierId = :dossier')
            ->setParameter('dossier', $dossierId);
        if (null !== $criteria->role) {
            $query->andWhere('e.actorRole = :role')->setParameter('role', $criteria->role);
        }
        if (null !== $criteria->action) {
            $query->andWhere('e.action = :action')->setParameter('action', $criteria->action);
        }
        if ('' !== $criteria->text) {
            $query->andWhere('e.summary LIKE :text OR e.actorName LIKE :text')
                ->setParameter('text', '%'.addcslashes($criteria->text, '%_\\').'%');
        }

        return $query;
    }

    /** @return list<AuditEvent> the history of one subject, newest first */
    public function findForSubject(string $type, string $id, int $limit = 50): array
    {
        /* @var list<AuditEvent> */
        return $this->createQueryBuilder('e')
            ->addSelect('a')
            ->leftJoin('e.actor', 'a')
            ->andWhere('e.subjectType = :type')
            ->andWhere('e.subjectId = :id')
            ->setParameter('type', $type)
            ->setParameter('id', $id)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
