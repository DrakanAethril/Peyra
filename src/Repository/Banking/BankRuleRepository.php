<?php

declare(strict_types=1);

namespace App\Repository\Banking;

use App\Entity\Banking\BankRule;
use App\Entity\Dossier\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The assignment rules are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<BankRule>
 */
final class BankRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankRule::class);
    }

    /** @return list<BankRule> in the order they are tried: the oldest first */
    public function findInOrder(Dossier $dossier): array
    {
        return $this->findBy(['dossier' => $dossier], ['id' => 'ASC']);
    }
}
