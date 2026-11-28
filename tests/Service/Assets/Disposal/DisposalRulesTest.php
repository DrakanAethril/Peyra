<?php

declare(strict_types=1);

namespace App\Tests\Service\Assets\Disposal;

use App\Enum\Assets\DisposalKind;
use App\Service\Assets\Disposal\DisposalCalculation;
use App\Service\Assets\Disposal\DisposalDraft;
use App\Service\Assets\Disposal\DisposalRules;
use App\Service\Assets\Disposal\DisposalValues;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/** A fixed asset's way out as typed (step 3.4b): checked by DisposalRules, its calculation worked out by hand. */
final class DisposalRulesTest extends TestCase
{
    public function testASaleTakesTheNormalRateWhenTheVatWasDeducted(): void
    {
        // 7 500,00 × 20 % = 1 500,00, the van I07's.
        $values = $this->check($this->draft('sale', '2026-06-30', 750_000, null));
        self::assertNotNull($values);
        self::assertSame([DisposalKind::Sale, '2026-06-30', 750_000, 150_000], [$values->kind, $values->on->format('Y-m-d'), $values->price->cents, $values->vat->cents]);

        $without = $this->check($this->draft('sale', '2026-06-30', 750_000, 0));
        self::assertSame(0, $without?->vat->cents, 'Saisie à 0 : une cession sans TVA.');

        $none = DisposalRules::check($this->draft('sale', '2026-06-30', 750_000, null), new \DateTimeImmutable('2022-02-01'), null, Money::zero(), BigDecimal::of('0.20'), static fn (): null => null);
        self::assertSame(0, $none?->vat->cents, 'Sans TVA déduite à l’acquisition, la cession est sans TVA.');
    }

    public function testAScrappingHasNoPrice(): void
    {
        $values = $this->check($this->draft('scrapping', '2026-05-15', 100_000, 20_000));
        self::assertNotNull($values);
        self::assertTrue($values->price->isZero() && $values->vat->isZero());
    }

    public function testFaultsAreNamed(): void
    {
        $draft = $this->draft('sale', '2026-03-31', null, -1);
        self::assertNull(DisposalRules::check($draft, new \DateTimeImmutable('2022-02-01'), new \DateTimeImmutable('2026-06-30'), Money::euros('4300.00'), BigDecimal::of('0.20'), static fn (): null => null));
        self::assertSame(['disposedOn', 'price', 'vat'], array_keys($draft->errors));
        self::assertStringContainsString('30/06/2026', $draft->errors['disposedOn']);

        $draft = $this->draft('sale', '2021-12-31', 100, null);
        $draft->buyer = 'zz99';
        self::assertNull($this->check($draft));
        self::assertSame(['disposedOn', 'buyer'], array_keys($draft->errors));
        self::assertSame('Le tiers ZZ99 n’existe pas dans le dossier.', $draft->errors['buyer']);
    }

    public function testTheCalculationOfALoss(): void
    {
        // Cost 10 000,00, 6 000,00 in the books, 500,00 up to the day: net 3 500,00, less an impairment of
        // 300,00: 3 200,00; sold 2 000,00: a loss of 1 200,00. 657 receives 10 000 − 6 500 = 3 500,00.
        $calculation = new DisposalCalculation(Money::euros('10000.00'), Money::euros('6000.00'), Money::euros('500.00'), Money::euros('300.00'), Money::zero(), Money::euros('2000.00'), Money::euros('400.00'), Money::zero());
        self::assertSame([650_000, 350_000, 320_000, -120_000, 240_000], [$calculation->depreciation()->cents, $calculation->bookValueOut()->cents, $calculation->netBookValue()->cents, $calculation->result()->cents, $calculation->receivable()->cents]);
    }

    private function check(DisposalDraft $draft): ?DisposalValues
    {
        return DisposalRules::check($draft, new \DateTimeImmutable('2022-02-01'), null, Money::euros('4300.00'), BigDecimal::of('0.20'), static fn (): null => null);
    }

    private function draft(string $kind, string $on, ?int $price, ?int $vat): DisposalDraft
    {
        $draft = new DisposalDraft();
        $draft->kind = $kind;
        $draft->disposedOn = $on;
        $draft->price = $price;
        $draft->vat = $vat;

        return $draft;
    }
}
