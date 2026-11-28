<?php

declare(strict_types=1);

namespace App\Repository\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollBody;
use App\Enum\Payroll\PayrollBodyKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The collecting bodies are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<PayrollBody>
 */
final class PayrollBodyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PayrollBody::class);
    }

    /** @return list<PayrollBody> by kind (PayrollBodyKind's order), then name */
    public function findByDossier(Dossier $dossier): array
    {
        /** @var list<PayrollBody> $bodies */
        $bodies = $this->findBy(['dossier' => $dossier], ['name' => 'ASC', 'id' => 'ASC']);
        $kinds = array_flip(array_map(static fn (PayrollBodyKind $kind): string => $kind->value, PayrollBodyKind::cases()));
        usort($bodies, static fn (PayrollBody $a, PayrollBody $b): int => $kinds[$a->getKind()->value] <=> $kinds[$b->getKind()->value]);

        return $bodies;
    }

    public function findOneInDossier(Dossier $dossier, int $id): ?PayrollBody
    {
        return $this->findOneBy(['dossier' => $dossier, 'id' => $id]);
    }

    /** The first body of that kind, by name: the one the standard plan's contributions go to. */
    public function findFirstOfKind(Dossier $dossier, PayrollBodyKind $kind): ?PayrollBody
    {
        return $this->findOneBy(['dossier' => $dossier, 'kind' => $kind], ['name' => 'ASC', 'id' => 'ASC']);
    }
}
