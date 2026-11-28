<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Inventory\Article;
use App\Entity\Inventory\StockMovement;
use App\Entity\Sales\SalesDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The stock movements are DossierOwned: every method reads inside the dossier's context. Their
 * order is the valuation's - date, then the order they were recorded in.
 *
 * @extends ServiceEntityRepository<StockMovement>
 */
final class StockMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockMovement::class);
    }

    /** @return list<StockMovement> the article's movements up to that day (all of them when null), in order */
    public function findOfArticle(Article $article, ?\DateTimeImmutable $until = null): array
    {
        $query = $this->createQueryBuilder('m')
            ->andWhere('m.article = :article')
            ->setParameter('article', $article)
            ->orderBy('m.date', 'ASC')
            ->addOrderBy('m.id', 'ASC');
        if (null !== $until) {
            $query->andWhere('m.date <= :until')->setParameter('until', $until->format('Y-m-d'));
        }
        /** @var list<StockMovement> $movements */
        $movements = $query->getQuery()->getResult();

        return $movements;
    }

    /** @return list<StockMovement> the dossier's movements up to that day (all of them when null), in order, their articles loaded */
    public function findUntil(Dossier $dossier, ?\DateTimeImmutable $until = null): array
    {
        $query = $this->createQueryBuilder('m')
            ->addSelect('a')
            ->join('m.article', 'a')
            ->andWhere('m.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('m.date', 'ASC')
            ->addOrderBy('m.id', 'ASC');
        if (null !== $until) {
            $query->andWhere('m.date <= :until')->setParameter('until', $until->format('Y-m-d'));
        }
        /** @var list<StockMovement> $movements */
        $movements = $query->getQuery()->getResult();

        return $movements;
    }

    public function countOfArticle(Article $article): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.article = :article')
            ->setParameter('article', $article)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<StockMovement> the movements a validated delivery note or receipt made */
    public function findOfDocument(SalesDocument $document): array
    {
        return $this->findBy(['salesDocument' => $document], ['id' => 'ASC']);
    }
}
