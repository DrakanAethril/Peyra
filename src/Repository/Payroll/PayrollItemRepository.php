<?php

declare(strict_types=1);

namespace App\Repository\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollBody;
use App\Entity\Payroll\PayrollItem;
use App\Enum\Payroll\PayrollItemKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The items of a plan de paie are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<PayrollItem>
 */
final class PayrollItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PayrollItem::class);
    }

    /** @return list<PayrollItem> in the payslip's order: by family (PayrollItemKind's order), then place, then code */
    public function findPlan(Dossier $dossier): array
    {
        /** @var list<PayrollItem> $items */
        $items = $this->createQueryBuilder('i')
            ->leftJoin('i.body', 'b')
            ->addSelect('b')
            ->andWhere('i.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->getQuery()
            ->getResult();
        $families = array_flip(array_map(static fn (PayrollItemKind $kind): string => $kind->value, PayrollItemKind::cases()));
        usort($items, static fn (PayrollItem $a, PayrollItem $b): int => [$families[$a->getKind()->value], $a->getSortOrder(), $a->getCode()] <=> [$families[$b->getKind()->value], $b->getSortOrder(), $b->getCode()]);

        return $items;
    }

    public function findOneByCode(Dossier $dossier, string $code): ?PayrollItem
    {
        return $this->findOneBy(['dossier' => $dossier, 'code' => $code]);
    }

    public function countForBody(PayrollBody $body): int
    {
        return $this->count(['body' => $body]);
    }

    public function countInDossier(Dossier $dossier): int
    {
        return $this->count(['dossier' => $dossier]);
    }
}
