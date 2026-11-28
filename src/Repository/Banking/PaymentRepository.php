<?php

declare(strict_types=1);

namespace App\Repository\Banking;

use App\Entity\Accounting\Journal;
use App\Entity\Banking\Payment;
use App\Entity\Banking\PaymentAllocation;
use App\Entity\Dossier\Dossier;
use App\Enum\Banking\BillRemittance;
use App\Enum\Banking\PaymentDirection;
use App\Enum\ThirdParty\PaymentMethod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The payments are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<Payment>
 */
final class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /** @return list<Payment> one way, the latest first */
    public function findLatestFirst(Dossier $dossier, PaymentDirection $direction, ?PaymentMethod $method = null): array
    {
        $criteria = ['dossier' => $dossier, 'direction' => $direction];
        if (null !== $method) {
            $criteria['method'] = $method;
        }

        return $this->findBy($criteria, ['paidOn' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<Payment> the cheques received into a journal and not yet remitted, oldest first */
    public function findChequesToDeposit(Journal $journal): array
    {
        return $this->findBy(['journal' => $journal, 'direction' => PaymentDirection::Receipt, 'method' => PaymentMethod::Cheque, 'deposit' => null], ['paidOn' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<Payment> the bills remitted for collection into a journal, due between two days */
    public function findBillsDueAtCollection(Journal $journal, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.remittanceJournal = :journal')->setParameter('journal', $journal)
            ->andWhere('p.remittance = :collection')->setParameter('collection', BillRemittance::Collection)
            ->andWhere('p.dueOn BETWEEN :from AND :to')->setParameter('from', $from->format('Y-m-d'))->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('p.dueOn', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * The allocations already made on these invoices, by any payment.
     *
     * @param list<int> $entryIds
     *
     * @return list<PaymentAllocation>
     */
    public function allocationsOf(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }

        return $this->getEntityManager()->createQueryBuilder()
            ->select('a')->from(PaymentAllocation::class, 'a')
            ->andWhere('IDENTITY(a.invoice) IN (:ids)')->setParameter('ids', $entryIds)
            ->orderBy('a.id', 'ASC')
            ->getQuery()->getResult();
    }
}
