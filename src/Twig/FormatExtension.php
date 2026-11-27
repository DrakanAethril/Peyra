<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigNumber;
use Twig\Attribute\AsTwigFilter;

/**
 * The French formats of design-ux.md § 6.6 as Twig filters (App\Service\Shared\FrenchFormat holds
 * the rules). An amount is a Money or a whole number of cents; null prints nothing.
 *
 *     {{ line.debit|amount_cell }}   {{ total|amount }}   {{ due|amount_eur }}   {{ balance|balance }}
 *     {{ rate|percent }}   {{ entry.date|date_fr }}   {{ entry.date|day_month(2026) }}
 */
final class FormatExtension
{
    #[AsTwigFilter('amount')]
    public static function amount(Money|int|null $amount): string
    {
        return null === $amount ? '' : FrenchFormat::amount(self::money($amount));
    }

    #[AsTwigFilter('amount_cell')]
    public static function amountCell(Money|int|null $amount): string
    {
        return null === $amount ? '' : FrenchFormat::amountCell(self::money($amount));
    }

    #[AsTwigFilter('amount_eur')]
    public static function amountInEuros(Money|int|null $amount): string
    {
        return null === $amount ? '' : FrenchFormat::amountInEuros(self::money($amount));
    }

    #[AsTwigFilter('balance')]
    public static function balance(Money|int|null $debitMinusCredit): string
    {
        return null === $debitMinusCredit ? '' : FrenchFormat::balance(self::money($debitMinusCredit));
    }

    #[AsTwigFilter('whole_euros')]
    public static function wholeEuros(?int $euros): string
    {
        return null === $euros ? '' : FrenchFormat::wholeEuros($euros);
    }

    #[AsTwigFilter('percent')]
    public static function percent(BigNumber|int|string|null $rate): string
    {
        return null === $rate ? '' : FrenchFormat::percent($rate);
    }

    #[AsTwigFilter('quantity')]
    public static function quantity(BigNumber|int|string|null $quantity, int $scale = 2): string
    {
        return null === $quantity ? '' : FrenchFormat::quantity($quantity, $scale);
    }

    #[AsTwigFilter('hours')]
    public static function hours(BigNumber|int|string|null $hours): string
    {
        return null === $hours ? '' : FrenchFormat::hours($hours);
    }

    #[AsTwigFilter('date_fr')]
    public static function date(?\DateTimeInterface $date): string
    {
        return null === $date ? '' : FrenchFormat::date($date);
    }

    #[AsTwigFilter('day_month')]
    public static function dayMonth(?\DateTimeInterface $date, int $shownYear): string
    {
        return null === $date ? '' : FrenchFormat::dayMonth($date, $shownYear);
    }

    #[AsTwigFilter('date_chip')]
    public static function chipDate(?\DateTimeInterface $date): string
    {
        return null === $date ? '' : FrenchFormat::chipDate($date);
    }

    #[AsTwigFilter('period')]
    public static function period(?\DateTimeInterface $date): string
    {
        return null === $date ? '' : FrenchFormat::period($date);
    }

    #[AsTwigFilter('clock')]
    public static function clock(?\DateTimeInterface $time): string
    {
        return null === $time ? '' : FrenchFormat::clock($time);
    }

    #[AsTwigFilter('duration')]
    public static function duration(?int $minutes): string
    {
        return null === $minutes ? '' : FrenchFormat::duration($minutes);
    }

    private static function money(Money|int $amount): Money
    {
        return $amount instanceof Money ? $amount : Money::cents($amount);
    }
}
