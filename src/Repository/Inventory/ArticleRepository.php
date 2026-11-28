<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Inventory\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The articles are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<Article>
 */
final class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /** @return list<Article> by code */
    public function findByDossier(Dossier $dossier): array
    {
        /** @var list<Article> $articles */
        $articles = $this->createQueryBuilder('a')
            ->andWhere('a.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('a.code', 'ASC')
            ->getQuery()
            ->getResult();

        return $articles;
    }

    public function findOneByCode(Dossier $dossier, string $code): ?Article
    {
        return $this->findOneBy(['dossier' => $dossier, 'code' => $code]);
    }
}
