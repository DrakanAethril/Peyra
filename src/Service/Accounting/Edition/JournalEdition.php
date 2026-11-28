<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\EntryState;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\Totals;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Comptabilité › Éditions › Journaux (lot 1, step 1.5b): the entries of the window, journal by
 * journal in code order, each journal month by month (a subtotal per month, a total per journal),
 * every line of every entry, and the general total - which balances, every entry balancing.
 * Entries in date order, then as written. Runs in the dossier's context.
 */
final readonly class JournalEdition
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function read(Dossier $dossier, EditionWindow $window, EntryScope $scope, ?Journal $journal = null): JournalReport
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('e', 'j', 'l', 'a', 't')
            ->from(Entry::class, 'e')
            ->join('e.journal', 'j')
            ->join('e.lines', 'l')
            ->join('l.account', 'a')
            ->leftJoin('l.thirdParty', 't')
            ->andWhere('e.dossier = :dossier')
            ->andWhere('e.entryDate BETWEEN :from AND :to')
            ->setParameter('dossier', $dossier)
            ->setParameter('from', $window->from->format('Y-m-d'))
            ->setParameter('to', $window->to->format('Y-m-d'))
            ->orderBy('j.code', 'ASC')
            ->addOrderBy('e.entryDate', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->addOrderBy('l.position', 'ASC');
        if (EntryScope::Validated === $scope) {
            $query->andWhere('e.state = :validated')->setParameter('validated', EntryState::Validated);
        }
        if (null !== $journal) {
            $query->andWhere('e.journal = :journal')->setParameter('journal', $journal);
        }

        /** @var array<string, array{journal: Journal, months: array<string, list<Entry>>}> $grouped */
        $grouped = [];
        foreach ($query->getQuery()->getResult() as $entry) {
            $code = $entry->getJournal()->getCode();
            $grouped[$code] ??= ['journal' => $entry->getJournal(), 'months' => []];
            $grouped[$code]['months'][$entry->getEntryDate()->format('Y-m')][] = $entry;
        }

        $sections = [];
        $total = Totals::zero();
        foreach ($grouped as $group) {
            $months = [];
            $journalTotal = Totals::zero();
            foreach ($group['months'] as $month => $entries) {
                $monthTotal = Totals::zero();
                foreach ($entries as $entry) {
                    $monthTotal = $monthTotal->plus(new Totals($entry->totalDebit(), $entry->totalCredit()));
                }
                $months[] = new JournalMonth(new \DateTimeImmutable($month.'-01'), $entries, $monthTotal);
                $journalTotal = $journalTotal->plus($monthTotal);
            }
            $sections[] = new JournalSection($group['journal'], $months, $journalTotal);
            $total = $total->plus($journalTotal);
        }

        return new JournalReport($sections, $total);
    }
}
