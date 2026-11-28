<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Dossier\DossierKind;
use App\Service\Accounting\Fec\FecFinding;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\EntryRules;
use App\Service\Dossier\Library\TemplateFactory;
use App\Service\Dossier\Library\TemplateStart;
use App\Service\Dossier\PeriodPlan;
use App\Service\ThirdParty\ThirdPartyDraft;
use App\Service\ThirdParty\ThirdPartyRules;
use App\Service\ThirdParty\ThirdPartySheet;

/**
 * What the doors would refuse of a FEC's plan, asked **before anything is written** (step 1.10b):
 * the import then writes in the background, a pass at a time, and a pass that commits cannot be
 * taken back. A pure function: the template the file would make is built in memory - its
 * exercise and months, the file's accounts, journals and third parties, never persisted - and
 * every third party goes through ThirdPartyRules, every entry through EntryRules, the very rules
 * the Ledger applies. Each fault comes back on the line of the file that carries it.
 */
final class FecImportRehearsal
{
    /** @return list<FecFinding> */
    public static function check(TemplateStart $start, FecImportPlan $plan): array
    {
        $template = new Dossier(DossierKind::Template, $start->company, $start->dossierDate, null, null, $start->exerciseStart, null);
        $exercise = new Exercise($template, TemplateFactory::exerciseCode($start->exerciseStart, $start->exerciseEnd), $start->exerciseStart, $start->exerciseEnd);
        $periods = array_map(static fn (array $bounds): Period => new Period($exercise, $bounds[0], $bounds[1]), PeriodPlan::monthly($start->exerciseStart, $start->exerciseEnd));

        $accounts = [];
        foreach ($plan->accounts as $read) {
            $accounts['#'.$read->number] = new Account($template, $read->number, $read->label, $read->lettrable, $read->collective, AccountOrigin::Imported);
        }
        $accountOf = static fn (string $number): ?Account => $accounts['#'.$number] ?? null;

        $journals = [];
        foreach ($plan->journals as $read) {
            $journals[$read->code] = new Journal($template, $read->code, $read->label, $read->type, null === $read->counterpart ? null : $accountOf($read->counterpart));
        }

        $findings = [];
        $thirdParties = [];
        foreach ($plan->thirdParties as $read) {
            $draft = self::thirdPartyDraft($read);
            $fields = ThirdPartyRules::check($draft, $accountOf, static fn (string $code): ?string => null, static fn (string $code): ?string => null);
            if (null === $fields) {
                foreach ($draft->errors as $error) {
                    $findings[] = new FecFinding($read->line, 'CompAuxNum', $error);
                }
                continue;
            }
            $thirdParty = new ThirdParty($template, $draft->code, $fields->kind, $draft->name);
            ThirdPartySheet::write($thirdParty, $draft, $fields);
            $thirdParties[$read->code] = $thirdParty;
        }
        // The entries are written after the third parties: a refused one stops the file there.
        if ([] !== $findings) {
            return $findings;
        }

        foreach ($plan->entries as $read) {
            $draft = self::entryDraft($read);
            $fields = EntryRules::check(
                $draft,
                static fn (string $code): ?Journal => $journals[$code] ?? null,
                static function (\DateTimeImmutable $day) use ($periods): ?Period {
                    foreach ($periods as $period) {
                        if ($day >= $period->getStartsOn() && $day <= $period->getEndsOn()) {
                            return $period;
                        }
                    }

                    return null;
                },
                $accountOf,
                static fn (string $code): ?ThirdParty => $thirdParties[$code] ?? null,
            );
            if (null === $fields) {
                foreach ($draft->errors as $key => $error) {
                    $findings[] = self::entryFinding($read, $key, $error);
                }
            }
        }

        return $findings;
    }

    /** A third party of the file as its fiche is written: payment at 30 days by transfer, the plan's default. */
    public static function thirdPartyDraft(FecImportThirdParty $read): ThirdPartyDraft
    {
        $draft = new ThirdPartyDraft();
        $draft->code = $read->code;
        $draft->kind = $read->kind->value;
        $draft->name = $read->name;
        $draft->account = $read->account;
        $draft->auxiliaryCode = $read->auxiliaryCode;
        $draft->termKind = 'net';
        $draft->termDays = '30';
        $draft->paymentMethod = 'transfer';

        return $draft;
    }

    public static function entryDraft(FecImportEntry $read): EntryDraft
    {
        return new EntryDraft($read->journal, $read->date, $read->document, $read->label, array_map(
            static fn (FecImportLine $line): EntryLineDraft => new EntryLineDraft($line->account, $line->thirdParty, $line->label, $line->debit, $line->credit),
            $read->lines,
        ));
    }

    /** A fault of the Ledger's rules, under its key, on the line of the file it names. */
    public static function entryFinding(FecImportEntry $entry, string $key, string $error): FecFinding
    {
        if (1 === preg_match('/^line\.(\d+)\.(\w+)$/', $key, $parts)) {
            $zone = match ($parts[2]) {
                'account' => 'CompteNum',
                'thirdParty' => 'CompAuxNum',
                'label' => 'EcritureLib',
                'amount' => 'Debit',
                default => null,
            };

            return new FecFinding($entry->lines[(int) $parts[1]]->fecLine ?? $entry->line, $zone, $error);
        }

        return new FecFinding($entry->line, match ($key) {
            'journal' => 'JournalCode',
            'date' => 'EcritureDate',
            'document' => 'PieceRef',
            'label' => 'EcritureLib',
            default => null,
        }, $error);
    }
}
