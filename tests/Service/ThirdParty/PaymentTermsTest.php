<?php

declare(strict_types=1);

namespace App\Tests\Service\ThirdParty;

use App\Entity\ThirdParty\PaymentTerms;
use App\Enum\ThirdParty\PaymentTermKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A third party's due date, reckoned by hand for each kind of term - and checked against the
 * opening balance of the demonstration dossier, whose due dates were computed apart
 * (donnees/demo/ouverture-2026.yaml).
 */
final class PaymentTermsTest extends TestCase
{
    /** @return iterable<string, array{PaymentTerms, string, string}> */
    public static function cases(): iterable
    {
        yield 'comptant' => [new PaymentTerms(PaymentTermKind::Immediate), '2026-01-15', '2026-01-15'];
        yield '30 jours date de facture' => [new PaymentTerms(PaymentTermKind::Net, 30), '2026-01-15', '2026-02-14'];
        yield '60 jours, à cheval sur février' => [new PaymentTerms(PaymentTermKind::Net, 60), '2026-01-15', '2026-03-16'];
        yield '30 jours, février bissextile' => [new PaymentTerms(PaymentTermKind::Net, 30), '2028-02-15', '2028-03-16'];
        yield '30 jours fin de mois : 14/02 puis fin février' => [new PaymentTerms(PaymentTermKind::NetEndOfMonth, 30), '2026-01-15', '2026-02-28'];
        yield '30 jours fin de mois, début de mois' => [new PaymentTerms(PaymentTermKind::NetEndOfMonth, 30), '2026-03-01', '2026-03-31'];
        yield 'fin de mois + 45 jours : 31/01 + 45' => [new PaymentTerms(PaymentTermKind::EndOfMonthNet, 45), '2026-01-15', '2026-03-17'];
        yield 'fin de mois + 45 jours : l’échéance du 15/10 (maquette 5)' => [new PaymentTerms(PaymentTermKind::EndOfMonthNet, 45), '2026-08-20', '2026-10-15'];
        yield 'le 10 du mois suivant, en fin d’année' => [new PaymentTerms(PaymentTermKind::DayOfNextMonth, null, 10), '2026-12-05', '2027-01-10'];
        yield 'le 31 du mois suivant, en février' => [new PaymentTerms(PaymentTermKind::DayOfNextMonth, null, 31), '2026-01-31', '2026-02-28'];
        // The opening balance of Menuiserie Vasseur: invoice date → due date of the prepared data.
        yield 'FV-2025-0268, Bâtir Loire Habitat, fin de mois + 45' => [new PaymentTerms(PaymentTermKind::EndOfMonthNet, 45), '2025-11-28', '2026-01-14'];
        yield 'FV-2025-0281, Atelier Dumas, 30 jours' => [new PaymentTerms(PaymentTermKind::Net, 30), '2025-12-12', '2026-01-11'];
        yield 'FV-2025-0279, Agencements Rivoire, 30 jours fin de mois' => [new PaymentTerms(PaymentTermKind::NetEndOfMonth, 30), '2025-12-10', '2026-01-31'];
        yield 'FV-2025-0274, Constructions Peyrard, fin de mois + 45' => [new PaymentTerms(PaymentTermKind::EndOfMonthNet, 45), '2025-12-05', '2026-02-14'];
    }

    #[DataProvider('cases')]
    public function testTheDueDate(PaymentTerms $terms, string $invoiceDate, string $dueDate): void
    {
        self::assertSame($dueDate, $terms->dueDate(new \DateTimeImmutable($invoiceDate.' 15:42'))->format('Y-m-d'));
    }

    public function testItsLabel(): void
    {
        self::assertSame('Comptant', new PaymentTerms(PaymentTermKind::Immediate)->label());
        self::assertSame('30 jours date de facture', new PaymentTerms(PaymentTermKind::Net, 30)->label());
        self::assertSame('30 jours fin de mois', new PaymentTerms(PaymentTermKind::NetEndOfMonth, 30)->label());
        self::assertSame('Fin de mois + 45 jours', new PaymentTerms(PaymentTermKind::EndOfMonthNet, 45)->label());
        self::assertSame('Le 1er du mois suivant', new PaymentTerms(PaymentTermKind::DayOfNextMonth, null, 1)->label());
        self::assertSame('Le 20 du mois suivant', new PaymentTerms(PaymentTermKind::DayOfNextMonth, null, 20)->label());
        self::assertSame('30 jours date de facture', PaymentTerms::default()->label());
    }

    public function testATermCarriesTheNumberItsKindNeedsAndNoOther(): void
    {
        foreach ([[PaymentTermKind::Net, null, null], [PaymentTermKind::Immediate, 30, null], [PaymentTermKind::DayOfNextMonth, 10, null], [PaymentTermKind::Net, 0, null], [PaymentTermKind::Net, 121, null], [PaymentTermKind::DayOfNextMonth, null, 32]] as [$kind, $days, $day]) {
            try {
                new PaymentTerms($kind, $days, $day);
                self::fail(\sprintf('%s %s %s accepted', $kind->value, $days ?? '-', $day ?? '-'));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
