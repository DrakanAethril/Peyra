<?php

declare(strict_types=1);

namespace App\Repository\Banking;

use App\Entity\Banking\PaymentApproval;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PaymentApproval> */
final class PaymentApprovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentApproval::class);
    }

    /**
     * The approvals of these invoices, by the invoice's entry id.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, PaymentApproval>
     */
    public function byInvoice(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }
        $by = [];
        /** @var list<PaymentApproval> $found */
        $found = $this->createQueryBuilder('a')->andWhere('IDENTITY(a.invoice) IN (:ids)')->setParameter('ids', $entryIds)->getQuery()->getResult();
        foreach ($found as $approval) {
            $by[(int) $approval->getInvoice()->getId()] = $approval;
        }

        return $by;
    }
}
