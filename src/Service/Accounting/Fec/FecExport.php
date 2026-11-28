<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Enum\Accounting\JournalType;
use App\Repository\Accounting\EntryRepository;

/**
 * The FEC of an exercise (Livre des procédures fiscales, article A47 A-1; lot 1, step 1.9a): every
 * validated entry of the exercise - the brouillard is not the accounting, and is counted apart -,
 * one line of the file per line of an entry, the à-nouveaux first, then in the chronological order
 * of validation (the dossier's date when it was validated; then the entry's date, its journal and
 * its number). The file is checked as it is written (FecConformity): the screen shows the report
 * of the very bytes it hands over.
 *
 * The zones: the journal's code and label; EcritureNum « ACH-2026-00012 », numbered without a gap
 * per journal and exercise at validation; the entry's date; the account's number and label; the
 * auxiliary code and the third party's name when the line names a third party on a collective;
 * the pièce and, until the documents of lot 2 carry their own date, the entry's date as PieceDate;
 * the line's label; debit and credit; the letter and its date; the day of validation (ValidDate);
 * no foreign currency. Read inside the dossier's context.
 */
final readonly class FecExport
{
    public function __construct(private EntryRepository $entries)
    {
    }

    public function of(Dossier $dossier, Exercise $exercise): FecFile
    {
        $entries = $this->entries->findValidatedInExercise($exercise);
        usort($entries, static fn (Entry $a, Entry $b): int => self::rank($a) <=> self::rank($b));
        $rows = [];
        foreach ($entries as $entry) {
            foreach ($entry->getLines() as $line) {
                $rows[] = self::row($entry, $line);
            }
        }
        $name = FecFormat::fileName($dossier->getCompany()->getSiren(), $exercise->getEndsOn());
        $content = FecWriter::write($rows);

        return new FecFile(
            $name,
            $content,
            \count($entries),
            \count($rows),
            $this->entries->countDraftsInExercise($exercise),
            FecConformity::check($content, $name, $exercise->getStartsOn(), $exercise->getEndsOn()),
        );
    }

    /** @return array{int, string, string, string, int} */
    private static function rank(Entry $entry): array
    {
        return [
            JournalType::Opening === $entry->getJournal()->getType() ? 0 : 1,
            $entry->getValidatedOn()?->format('Ymd') ?? '',
            $entry->getEntryDate()->format('Ymd'),
            $entry->getJournal()->getCode(),
            (int) $entry->getNumber(),
        ];
    }

    /** @return list<string> */
    private static function row(Entry $entry, EntryLine $line): array
    {
        $thirdParty = $line->getThirdParty();
        $auxiliary = $thirdParty?->getAuxiliaryCode();
        $date = FecFormat::day($entry->getEntryDate());

        return [
            $entry->getJournal()->getCode(),
            $entry->getJournal()->getLabel(),
            (string) $entry->getNumberLabel(),
            $date,
            $line->getAccount()->getNumber(),
            $line->getAccount()->getLabel(),
            $auxiliary ?? '',
            null !== $thirdParty && null !== $auxiliary ? $thirdParty->getName() : '',
            $entry->getDocument(),
            $date,
            '' === $line->getLabel() ? $entry->getLabel() : $line->getLabel(),
            FecFormat::amount($line->getDebit()),
            FecFormat::amount($line->getCredit()),
            $line->getLetter() ?? '',
            null === $line->getLetteredOn() ? '' : FecFormat::day($line->getLetteredOn()),
            null === $entry->getValidatedOn() ? '' : FecFormat::day($entry->getValidatedOn()),
            '',
            '',
        ];
    }
}
