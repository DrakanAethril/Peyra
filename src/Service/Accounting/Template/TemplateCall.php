<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryTemplate;
use App\Enum\Accounting\EntrySide;
use App\Enum\Accounting\TemplateBase;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * A template called (F6 in the grid, the guided entry): its lines as the grid types them, amounts
 * computed by TemplateComputation from the amount and the rate given - a pure function over the
 * template and a lookup of the plan.
 *
 * - The label of a line is its own, else the template's; « {mois} » in it becomes the entry's month
 *   (« Loyer {mois} » → « Loyer février 2026 »).
 * - A line on a collective account typed by number without its third party asks one: the third
 *   party given fills it (the guided entry); none given, the grid's Tiers cell stays to fill.
 * - Faults come back by field: `amount`, `rate`, `thirdParty`.
 */
final class TemplateCall
{
    /**
     * @param callable(string): ?Account $accountOf by number
     *
     * @return array{lines: list<GridLine>, errors: array<string, string>}
     */
    public static function lines(EntryTemplate $template, \DateTimeImmutable $date, ?Money $typed, string $rateText, string $thirdParty, callable $accountOf, bool $thirdPartyRequired = false): array
    {
        $errors = [];
        $rate = null;
        $base = $template->getBase();
        if ($template->asksAmount()) {
            if (null === $typed || !$typed->isPositive()) {
                $errors['amount'] = \sprintf('Indiquez %s.', ($base ?? TemplateBase::Gross)->phrase());
            }
            $rate = VatRate::parse('' === trim($rateText) ? (string) $template->getVatRate() : $rateText);
            if (null === $rate) {
                $errors['rate'] = 'Un taux de TVA s’écrit en pourcentage, de 0 à 100 : 20, 5,5.';
            }
        }
        if ($thirdPartyRequired && self::asksThirdParty($template, $accountOf) && '' === trim($thirdParty)) {
            $errors['thirdParty'] = 'Indiquez le tiers de l’écriture.';
        }

        $spec = [];
        foreach ($template->getLines() as $line) {
            $spec[] = ['side' => $line->getSide(), 'amount' => $line->getAmount(), 'fixed' => $line->getFixed()];
        }
        $computed = TemplateComputation::lines($spec, $base, isset($errors['amount']) || isset($errors['rate']) ? null : $typed, $rate);

        $month = FrenchFormat::period($date);
        $lines = [];
        $templateLines = $template->getLines();
        foreach ($computed as $amount) {
            $line = $templateLines[$amount['index']];
            $label = '' === $line->getLabel() ? $template->getLabel() : $line->getLabel();
            $named = $line->getThirdParty();
            if ('' === $named && self::opensThirdParty($line->getAccount(), $accountOf)) {
                $named = strtoupper(trim($thirdParty));
            }
            $cents = $amount['amount']->cents ?? 0;
            $lines[] = new GridLine(
                $line->getAccount(),
                $named,
                str_replace('{mois}', $month, $label),
                EntrySide::Debit === $amount['side'] ? $cents : 0,
                EntrySide::Credit === $amount['side'] ? $cents : 0,
            );
        }

        return ['lines' => $lines, 'errors' => $errors];
    }

    /**
     * Whether a call names the third party: one of its lines is a collective account typed by
     * number, without one.
     *
     * @param callable(string): ?Account $accountOf
     */
    public static function asksThirdParty(EntryTemplate $template, callable $accountOf): bool
    {
        foreach ($template->getLines() as $line) {
            if ('' === $line->getThirdParty() && self::opensThirdParty($line->getAccount(), $accountOf)) {
                return true;
            }
        }

        return false;
    }

    /** @param callable(string): ?Account $accountOf */
    private static function opensThirdParty(string $cell, callable $accountOf): bool
    {
        return 1 === preg_match('/^\d+$/', $cell) && true === $accountOf($cell)?->isCollective();
    }
}
