<?php

declare(strict_types=1);

namespace App\Service\Document\Processing;

use App\Entity\Dossier\Dossier;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Accounting\Entry\JournalGrid;

/**
 * A proposed entry checked as F10 would write it - in its journal, in the month of its date, by
 * the grid's own rules and controls: the pièces' screen (step 2.2a) and the commercial documents'
 * posting (step 2.3b) read the same check.
 */
final readonly class ProposedEntryCheck
{
    public function __construct(
        private JournalRepository $journals,
        private PeriodRepository $periods,
        private JournalGrid $grid,
    ) {
    }

    public function check(Dossier $dossier, PieceProposal $entry): PieceCheck
    {
        $journal = '' === $entry->journal ? null : $this->journals->findOneByCode($dossier, $entry->journal);
        if (null === $journal) {
            return new PieceCheck(null, ['journal' => 'Choisissez le journal de l’écriture.']);
        }
        $day = null === $entry->date ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $entry->date) ?: null);
        $period = (null === $day ? null : $this->periods->findAt($dossier, $day)) ?? $this->periods->findAt($dossier, $dossier->getDossierDate());
        if (null === $period) {
            return new PieceCheck(null, ['date' => 'Aucun mois ouvert du dossier ne reçoit cette écriture.']);
        }
        $grid = $this->grid->check($this->grid->context($dossier, $journal, $period), $entry->group());
        $errors = $grid->errors;
        if (null === $day) {
            $errors['date'] = 'Saisissez la date de l’écriture.';
        }

        return new PieceCheck($grid, $errors);
    }
}
