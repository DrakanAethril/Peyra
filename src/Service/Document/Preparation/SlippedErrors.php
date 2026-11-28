<?php

declare(strict_types=1);

namespace App\Service\Document\Preparation;

use App\Enum\Document\SlipNature;
use App\Enum\Document\SlipPlace;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Accounting\Template\VatRate;
use App\Service\Document\Processing\PieceParty;
use App\Service\Document\Processing\PieceProposal;
use Brick\Math\RoundingMode;

/**
 * The errors a teacher slips into a pièce (step 2.2b; design-ux.md § 3.5), a pure function over
 * the answer key - the right read data and the right proposal - and two lookups.
 *
 * - **Targets**: the third party, the three totals and each VAT rate of the read data
 *   (`thirdParty`, `totalExcludingTax`, `totalVat`, `totalIncludingTax`, `vat.<i>.rate`); the
 *   Compte cell and the amount of each line of the proposal (`line.<i>.account`, `line.<i>.amount`).
 *   One error per target. The nature is the target's: a Compte cell holding an auxiliary code is a
 *   third party.
 * - **Places**: an error on a value of the proposal shows in the proposal. An error on the read
 *   data shows in them, in the entry, or in both - in the entry only when a line of the right
 *   proposal follows the value: the third party's line (third party, total including tax), the one
 *   expense line (total excluding tax), the one VAT line (total VAT), the VAT line of that rate and
 *   the third party's line (a rate: the VAT recomputed on its base, half up, and the third party's
 *   line moved by as much, so that the entry still balances).
 * - **What the student receives** (`apply()`): the right values with every error put on, in the
 *   order slipped. Nothing here announces an error: the wrong values are values like any other.
 */
final class SlippedErrors
{
    public const string THIRD_PARTY = 'thirdParty';
    public const string TOTAL_EXCLUDING_TAX = 'totalExcludingTax';
    public const string TOTAL_VAT = 'totalVat';
    public const string TOTAL_INCLUDING_TAX = 'totalIncludingTax';

    private const array TOTALS = [self::TOTAL_EXCLUDING_TAX, self::TOTAL_VAT, self::TOTAL_INCLUDING_TAX];

    /**
     * The values of this pièce an error may fall on, read data first.
     *
     * @return list<string>
     */
    public static function targets(PieceReading $reading, ?PieceProposal $proposal): array
    {
        $targets = null === $reading->thirdParty ? [] : [self::THIRD_PARTY];
        foreach (self::TOTALS as $total) {
            if (null !== $reading->value($total) && '0' !== $reading->value($total)) {
                $targets[] = $total;
            }
        }
        foreach (array_keys($reading->vatLines) as $index) {
            $targets[] = 'vat.'.$index.'.rate';
        }
        foreach ($proposal->lines ?? [] as $index => $line) {
            if ('' !== $line->account) {
                $targets[] = 'line.'.$index.'.account';
            }
            if (0 !== self::amountOf($line)) {
                $targets[] = 'line.'.$index.'.amount';
            }
        }

        return $targets;
    }

    public static function isReadingTarget(string $target): bool
    {
        return \in_array($target, [self::THIRD_PARTY, ...self::TOTALS], true) || 1 === preg_match('/^vat\.\d\.rate$/', $target);
    }

    /** The line of the proposal a `line.<i>.…` target names; null for a target of the read data. */
    public static function lineOf(string $target): ?int
    {
        return 1 === preg_match('/^line\.(\d{1,2})\.(account|amount)$/', $target, $match) ? (int) $match[1] : null;
    }

    public static function nature(string $target, ?PieceProposal $proposal): ?SlipNature
    {
        if (self::THIRD_PARTY === $target) {
            return SlipNature::ThirdParty;
        }
        if (\in_array($target, self::TOTALS, true) || str_ends_with($target, '.amount')) {
            return SlipNature::Amount;
        }
        if (str_ends_with($target, '.rate')) {
            return SlipNature::VatRate;
        }
        $line = self::lineOf($target);
        $cell = null === $line ? null : ($proposal->lines[$line] ?? null)?->account;
        if (null === $cell) {
            return null;
        }

        return 1 === preg_match('/[A-Z]/i', $cell) ? SlipNature::ThirdParty : SlipNature::Account;
    }

    /** The right value of a target, as an error writes it; null when the pièce has no such value. */
    public static function rightValue(string $target, PieceReading $reading, ?PieceProposal $proposal): ?string
    {
        if (self::isReadingTarget($target)) {
            return $reading->value($target);
        }
        $index = self::lineOf($target);
        $line = null === $index ? null : ($proposal->lines[$index] ?? null);
        if (null === $line) {
            return null;
        }

        return str_ends_with($target, '.account') ? $line->account : (string) self::amountOf($line);
    }

    /**
     * Where an error on this target may show, the default first: both when the entry follows the
     * value, else the read data; the proposal for a value of the proposal.
     *
     * @param callable(string): ?PieceParty $partyOf a third party by its code, null without an account
     *
     * @return list<SlipPlace>
     */
    public static function places(string $target, PieceReading $reading, ?PieceProposal $proposal, callable $partyOf): array
    {
        if (!self::isReadingTarget($target)) {
            return null === self::lineOf($target) ? [] : [SlipPlace::Proposal];
        }
        $follows = null !== $proposal && [] !== self::followingLines($target, $reading, $proposal, null === $reading->thirdParty ? null : $partyOf($reading->thirdParty));

        return $follows ? [SlipPlace::Both, SlipPlace::Reading, SlipPlace::Proposal] : [SlipPlace::Reading];
    }

    /**
     * Why this error cannot be slipped; null when it can. The wrong value comes as an error
     * writes it: a third party's code, an account number, a rate's canonical text, cents.
     *
     * @param callable(string): bool        $accountExists an account of the dossier's plan
     * @param callable(string): ?PieceParty $partyOf       a third party by its code, null without an account
     */
    public static function refusal(AnswerKey $key, string $target, SlipPlace $place, string $wrong, callable $accountExists, callable $partyOf): ?string
    {
        if (!\in_array($target, self::targets($key->reading, $key->proposal), true)) {
            return 'Cette valeur ne reçoit pas d’erreur.';
        }
        if (null !== $key->errorOn($target)) {
            return 'Une erreur est déjà glissée sur cette valeur : retirez-la d’abord.';
        }
        if (!\in_array($place, self::places($target, $key->reading, $key->proposal, $partyOf), true)) {
            return 'L’écriture proposée ne dépend pas de cette valeur : l’erreur ne peut être que dans les données lues.';
        }
        if ($wrong === self::rightValue($target, $key->reading, $key->proposal)) {
            return 'La valeur fausse doit différer de la valeur juste.';
        }

        $rate = VatRate::parse($wrong);

        return match (self::nature($target, $key->proposal)) {
            SlipNature::Amount => 1 === preg_match('/^[1-9]\d{0,14}$/', $wrong) ? null : 'Indiquez un montant supérieur à zéro.',
            SlipNature::VatRate => null !== $rate && VatRate::canonical($rate) === $wrong ? null : 'Indiquez un taux de TVA, par exemple 10 ou 5,5.',
            SlipNature::Account => $accountExists($wrong) ? null : 'Choisissez un compte du plan du dossier.',
            SlipNature::ThirdParty => self::partyRefusal($target, $wrong, $key, $partyOf),
            null => 'Cette valeur ne reçoit pas d’erreur.',
        };
    }

    /**
     * What the student receives: the read data and the proposal with every error put on.
     *
     * @param callable(string): ?PieceParty $partyOf
     *
     * @return array{PieceReading, ?PieceProposal}
     */
    public static function apply(AnswerKey $key, callable $partyOf): array
    {
        $reading = $key->reading;
        $proposal = $key->proposal;
        $lines = $proposal->lines ?? [];
        $label = $proposal->label ?? '';
        $rightParty = null === $key->reading->thirdParty ? null : $partyOf($key->reading->thirdParty);

        foreach ($key->errors as $error) {
            if (SlipNature::ThirdParty === $error->nature && $error->place->touchesProposal() && null !== $rightParty) {
                // The entry's label names the third party read: the wrong one, then.
                $wrongParty = $partyOf($error->wrong);
                $label = null === $wrongParty ? $label : str_replace($rightParty->name, $wrongParty->name, $label);
            }
            if (self::isReadingTarget($error->target)) {
                if ($error->place->touchesReading()) {
                    $reading = $reading->with($error->target, $error->wrong);
                }
                if ($error->place->touchesProposal() && null !== $proposal) {
                    $lines = self::follow($error, $key, $lines, self::followingLines($error->target, $key->reading, $proposal, $rightParty), $partyOf);
                }
                continue;
            }
            $index = self::lineOf($error->target);
            if (null === $index || !isset($lines[$index])) {
                continue;
            }
            $line = $lines[$index];
            if (str_ends_with($error->target, '.amount')) {
                $lines[$index] = self::withAmount($line, (int) $error->wrong);
            } elseif (SlipNature::ThirdParty === $error->nature) {
                $party = $partyOf($error->wrong);
                if (null !== $party) {
                    $lines[$index] = new GridLine($party->accountCell, $party->thirdPartyCell, $line->label, $line->debit, $line->credit, $line->due);
                }
            } else {
                $lines[$index] = new GridLine($error->wrong, $line->thirdParty, $line->label, $line->debit, $line->credit, $line->due);
            }
        }

        return [
            $reading,
            null === $proposal ? null : new PieceProposal($proposal->journal, $proposal->date, $proposal->document, $label, $lines, $proposal->basis),
        ];
    }

    /**
     * A third party for a wrong value: one of the dossier kept on an account, and, on a Compte cell,
     * not the one the line already names.
     *
     * @param callable(string): ?PieceParty $partyOf
     */
    private static function partyRefusal(string $target, string $code, AnswerKey $key, callable $partyOf): ?string
    {
        $party = $partyOf($code);
        if (null === $party) {
            return 'Choisissez un tiers du dossier tenu sur un compte.';
        }
        $line = self::lineOf($target);
        $right = null === $line ? null : ($key->proposal->lines[$line] ?? null);
        if (null !== $right && $right->account === $party->accountCell && $right->thirdParty === $party->thirdPartyCell) {
            return 'La valeur fausse doit différer de la valeur juste.';
        }

        return null;
    }

    public static function amountOf(GridLine $line): int
    {
        return 0 !== $line->debit ? $line->debit : $line->credit;
    }

    /**
     * The lines of the right proposal a value of the read data decides, by role: `line` the one it
     * sets, `party` the third party's line a rate moves too. Empty when no line follows it.
     *
     * @return array{line?: int, party?: int}
     */
    private static function followingLines(string $target, PieceReading $reading, PieceProposal $proposal, ?PieceParty $party): array
    {
        $partyLine = null;
        $vatLines = [];
        $otherLines = [];
        foreach ($proposal->lines as $index => $line) {
            if (null === $partyLine && null !== $party && $line->account === $party->accountCell && $line->thirdParty === $party->thirdPartyCell) {
                $partyLine = $index;
            } elseif (str_starts_with($line->account, '4456') || str_starts_with($line->account, '4457')) {
                $vatLines[] = $index;
            } else {
                $otherLines[] = $index;
            }
        }

        if (self::THIRD_PARTY === $target || self::TOTAL_INCLUDING_TAX === $target) {
            return null === $partyLine ? [] : ['line' => $partyLine];
        }
        if (self::TOTAL_EXCLUDING_TAX === $target) {
            return 1 === \count($otherLines) ? ['line' => $otherLines[0]] : [];
        }
        if (self::TOTAL_VAT === $target) {
            return 1 === \count($vatLines) ? ['line' => $vatLines[0]] : [];
        }
        if (1 === preg_match('/^vat\.(\d)\.rate$/', $target, $match) && null !== $partyLine) {
            $vat = $reading->vatLines[(int) $match[1]]->vat ?? null;
            foreach ($vatLines as $index) {
                if (null !== $vat && self::amountOf($proposal->lines[$index]) === $vat->cents) {
                    return ['line' => $index, 'party' => $partyLine];
                }
            }
        }

        return [];
    }

    /**
     * The proposal's lines after a value of the read data was read wrong.
     *
     * @param list<GridLine>                $lines
     * @param array{line?: int, party?: int} $following
     * @param callable(string): ?PieceParty  $partyOf
     *
     * @return list<GridLine>
     */
    private static function follow(SlippedError $error, AnswerKey $key, array $lines, array $following, callable $partyOf): array
    {
        $index = $following['line'] ?? null;
        if (null === $index || !isset($lines[$index])) {
            return $lines;
        }
        $line = $lines[$index];
        if (self::THIRD_PARTY === $error->target) {
            $party = $partyOf($error->wrong);
            if (null !== $party) {
                $lines[$index] = new GridLine($party->accountCell, $party->thirdPartyCell, $line->label, $line->debit, $line->credit, $line->due);
            }

            return $lines;
        }
        if (\in_array($error->target, self::TOTALS, true)) {
            $lines[$index] = self::withAmount($line, (int) $error->wrong);

            return $lines;
        }
        // A rate read wrong: the VAT computed again on its base, the third party's line moved by as much.
        $row = 1 === preg_match('/^vat\.(\d)\.rate$/', $error->target, $match) ? ($key->reading->vatLines[(int) $match[1]] ?? null) : null;
        $rate = VatRate::parse($error->wrong);
        $party = $following['party'] ?? null;
        if (null === $row || null === $rate || null === $party || !isset($lines[$party])) {
            return $lines;
        }
        $vat = $row->base->times($rate->dividedBy(100, 5), RoundingMode::HalfUp)->cents;
        $delta = $vat - self::amountOf($line);
        $lines[$index] = self::withAmount($line, $vat);
        $lines[$party] = self::withAmount($lines[$party], self::amountOf($lines[$party]) + $delta);

        return $lines;
    }

    /** The line with another amount on the side it already has. */
    private static function withAmount(GridLine $line, int $cents): GridLine
    {
        $cents = max(0, $cents);

        return 0 !== $line->debit || 0 === $line->credit
            ? new GridLine($line->account, $line->thirdParty, $line->label, $cents, 0, $line->due)
            : new GridLine($line->account, $line->thirdParty, $line->label, 0, $cents, $line->due);
    }
}
