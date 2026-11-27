<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Template;

use App\Enum\Accounting\EntrySide;
use App\Enum\Accounting\TemplateAmount;
use App\Enum\Accounting\TemplateBase;
use App\Service\Accounting\Template\TemplateComputation;
use App\Service\Accounting\Template\VatRate;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HT, TVA and TTC of a call, worked out by hand:
 * - 3 200,00 HT at 20 %: TVA 640,00, TTC 3 840,00 (the demonstration's rent);
 * - 3 840,00 TTC at 20 %: TVA = 3 840 × 20 / 120 = 640,00, HT 3 200,00;
 * - 117,60 TTC at 20 %: TVA = 117,60 / 6 = 19,60, HT 98,00 (Forez Télécom);
 * - 100,00 TTC at 5,5 %: TVA = 100 × 5,5 / 105,5 = 5,2132… → 5,21, HT 94,79;
 * - 10,01 HT at 20 %: TVA = 2,002 → 2,00, TTC 12,01;
 * - 0,05 HT at 10 %: TVA = 0,005 → 0,01 (half up), TTC 0,06;
 * - 1 000,00 TTC at 2,1 %: TVA = 1 000 × 2,1 / 102,1 = 20,5680… → 20,57, HT 979,43.
 */
final class TemplateComputationTest extends TestCase
{
    /** @return iterable<string, array{TemplateBase, int, string, int, int, int}> */
    public static function splits(): iterable
    {
        yield 'loyer HT' => [TemplateBase::Net, 320_000, '20', 320_000, 64_000, 384_000];
        yield 'loyer TTC' => [TemplateBase::Gross, 384_000, '20', 320_000, 64_000, 384_000];
        yield 'téléphone TTC' => [TemplateBase::Gross, 11_760, '20', 9_800, 1_960, 11_760];
        yield 'taux réduit TTC' => [TemplateBase::Gross, 10_000, '5.5', 9_479, 521, 10_000];
        yield 'arrondi vers le bas' => [TemplateBase::Net, 1_001, '20', 1_001, 200, 1_201];
        yield 'demi-centime vers le haut' => [TemplateBase::Net, 5, '10', 5, 1, 6];
        yield 'taux à une décimale' => [TemplateBase::Gross, 100_000, '2.1', 97_943, 2_057, 100_000];
        yield 'sans TVA' => [TemplateBase::Net, 26_700, '0', 26_700, 0, 26_700];
    }

    #[DataProvider('splits')]
    public function testSplit(TemplateBase $base, int $typed, string $rate, int $net, int $vat, int $gross): void
    {
        $split = TemplateComputation::split($base, Money::cents($typed), BigDecimal::of($rate));

        self::assertSame([$net, $vat, $gross], [$split['net']->cents, $split['vat']->cents, $split['gross']->cents]);
    }

    public function testThePurchaseOfTheDemonstrationBalances(): void
    {
        $lines = [
            ['side' => EntrySide::Debit, 'amount' => TemplateAmount::Net, 'fixed' => null],
            ['side' => EntrySide::Debit, 'amount' => TemplateAmount::Vat, 'fixed' => null],
            ['side' => EntrySide::Credit, 'amount' => TemplateAmount::Gross, 'fixed' => null],
        ];

        $computed = TemplateComputation::lines($lines, TemplateBase::Net, Money::cents(320_000), BigDecimal::of('20'));

        self::assertSame([[0, 'debit', 320_000], [1, 'debit', 64_000], [2, 'credit', 384_000]], self::flat($computed));
    }

    public function testTheBalanceLineTakesWhatBalancesOnWhicheverSide(): void
    {
        // 5 000,00 sold, 120,00 of fees kept by the bank: 4 880,00 on the bank's debit.
        $lines = [
            ['side' => EntrySide::Credit, 'amount' => TemplateAmount::Fixed, 'fixed' => Money::cents(500_000)],
            ['side' => EntrySide::Debit, 'amount' => TemplateAmount::Fixed, 'fixed' => Money::cents(12_000)],
            ['side' => EntrySide::Credit, 'amount' => TemplateAmount::Balance, 'fixed' => null],
        ];

        self::assertSame([[0, 'credit', 500_000], [1, 'debit', 12_000], [2, 'debit', 488_000]], self::flat(TemplateComputation::lines($lines, null, null, null)));
    }

    public function testABalanceLineWithNothingToBalanceIsLeftOut(): void
    {
        $lines = [
            ['side' => EntrySide::Debit, 'amount' => TemplateAmount::Fixed, 'fixed' => Money::cents(3_200)],
            ['side' => EntrySide::Credit, 'amount' => TemplateAmount::Fixed, 'fixed' => Money::cents(3_200)],
            ['side' => EntrySide::Credit, 'amount' => TemplateAmount::Balance, 'fixed' => null],
        ];

        self::assertSame([[0, 'debit', 3_200], [1, 'credit', 3_200]], self::flat(TemplateComputation::lines($lines, null, null, null)));
    }

    public function testALineToTypeAndAnAmountNotGivenHaveNoAmount(): void
    {
        $lines = [
            ['side' => EntrySide::Debit, 'amount' => TemplateAmount::Net, 'fixed' => null],
            ['side' => EntrySide::Credit, 'amount' => TemplateAmount::Typed, 'fixed' => null],
        ];

        self::assertSame([[0, 'debit', null], [1, 'credit', null]], self::flat(TemplateComputation::lines($lines, TemplateBase::Net, null, BigDecimal::of('20'))));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function rates(): iterable
    {
        yield 'entier' => ['20', '20'];
        yield 'virgule' => ['5,5', '5.5'];
        yield 'point et zéros' => ['10.00', '10'];
        yield 'pour cent' => ['2,1 %', '2.1'];
        yield 'zéro' => ['0', '0'];
        yield 'cent' => ['100', '100'];
        yield 'au-delà de cent' => ['120', null];
        yield 'quatre décimales' => ['5,5001', null];
        yield 'négatif' => ['-5', null];
        yield 'texte' => ['vingt', null];
        yield 'vide' => ['', null];
    }

    #[DataProvider('rates')]
    public function testVatRateReadsWhatAPersonTypes(string $typed, ?string $canonical): void
    {
        $rate = VatRate::parse($typed);

        self::assertSame($canonical, null === $rate ? null : VatRate::canonical($rate));
    }

    /**
     * @param list<array{index: int, side: EntrySide, amount: ?Money}> $computed
     *
     * @return list<array{int, string, ?int}>
     */
    private static function flat(array $computed): array
    {
        return array_map(static fn (array $line): array => [$line['index'], $line['side']->value, $line['amount']?->cents], $computed);
    }
}
