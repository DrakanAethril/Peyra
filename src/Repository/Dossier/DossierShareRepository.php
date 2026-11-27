<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierShare;
use App\Entity\Identity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DossierShare> */
final class DossierShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierShare::class);
    }

    public function findOneFor(Dossier $dossier, User $teacher): ?DossierShare
    {
        return $this->findOneBy(['dossier' => $dossier, 'teacher' => $teacher]);
    }
}
