<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Service\Payroll\Engine\GeneralReduction;
use App\Service\Regulatory\RegulatorySnapshot;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The « Contrôles » card of a calculated payslip (maquette 4; design-ux.md § 4.7, point 3; review 4.7),
 * pure: the salary against its legal minimum - no collective agreement is applied, so the minimum is the
 * SMIC, or for an apprentice the share of the SMIC their age and year of contract give -, the gross against
 * the month's ceiling, the general reduction the tool calculated, and the values forced by hand that differ
 * from the tool's calculation. A salary under its minimum and a forced value are faults: they stop the
 * validation at level B (Assistance::blocksPayslipControls()) and are signalled elsewhere; the general
 * reduction is to check (the maquette's « à justifier »), the ceiling informs.
 */
final readonly class PayslipControls
{
    public const string PASSED = 'passed';
    /** Right, but for the student to check: the general reduction the tool calculated. */
    public const string TO_CHECK = 'check';
    public const string FAULT = 'fault';

    /** @param list<array{tone: string, text: string}> $items in the card's order */
    private function __construct(public array $items)
    {
    }

    /**
     * @param ?Money $apprenticeMinimum the monthly minimum of an apprentice (apprenticeMinimum()), null for anybody else
     * @param int    $gaps              the cells forced by hand that differ from the tool's calculation (PayslipEntryCheck::gaps())
     */
    public static function of(
        BigDecimal $hourlyRate,
        Money $baseSalary,
        BigDecimal $smicHourly,
        ?Money $apprenticeMinimum,
        Money $gross,
        BigDecimal $ceiling,
        ?GeneralReduction $reduction,
        int $gaps,
    ): self {
        $items = [];
        if (null !== $apprenticeMinimum) {
            $items[] = $baseSalary->compareTo($apprenticeMinimum) >= 0
                ? ['tone' => self::PASSED, 'text' => \sprintf('Salaire d’apprenti au moins égal au minimum légal (%s)', FrenchFormat::amountInEuros($apprenticeMinimum))]
                : ['tone' => self::FAULT, 'text' => \sprintf('Salaire d’apprenti de %s sous le minimum légal de %s : à revoir dans la fiche du salarié', FrenchFormat::amountInEuros($baseSalary), FrenchFormat::amountInEuros($apprenticeMinimum))];
        } else {
            $items[] = $hourlyRate->compareTo($smicHourly) >= 0
                ? ['tone' => self::PASSED, 'text' => \sprintf('Salaire horaire de %s € au moins égal au SMIC (%s €)', FrenchFormat::number($hourlyRate), FrenchFormat::number($smicHourly))]
                : ['tone' => self::FAULT, 'text' => \sprintf('Salaire horaire de %s € sous le SMIC (%s €) : à revoir dans la fiche du salarié', FrenchFormat::number($hourlyRate), FrenchFormat::number($smicHourly))];
        }
        $over = $gross->toDecimal()->minus($ceiling);
        $items[] = $over->isPositive()
            ? ['tone' => self::PASSED, 'text' => \sprintf('Brut au-dessus du plafond du mois (%s) : %s en tranche 2', FrenchFormat::amountInEuros(Money::euros($ceiling, RoundingMode::HalfUp)), FrenchFormat::amountInEuros(Money::euros($over, RoundingMode::HalfUp)))]
            : ['tone' => self::PASSED, 'text' => \sprintf('Brut sous le plafond du mois (%s) : tout en tranche 1', FrenchFormat::amountInEuros(Money::euros($ceiling, RoundingMode::HalfUp)))];
        $items[] = null === $reduction || $reduction->amount->isZero()
            ? ['tone' => self::PASSED, 'text' => 'Aucune réduction générale de cotisations ce mois-ci']
            : ['tone' => self::TO_CHECK, 'text' => \sprintf('Réduction générale calculée par l’outil : %s (coefficient %s), détail en F5 sur sa ligne', FrenchFormat::amountInEuros($reduction->amount->abs()), FrenchFormat::number($reduction->coefficient))];
        if ($gaps > 0) {
            $items[] = ['tone' => self::FAULT, 'text' => 1 === $gaps ? 'Une valeur saisie dans le bulletin diffère du calcul de l’outil' : \sprintf('%d valeurs saisies dans le bulletin diffèrent du calcul de l’outil', $gaps)];
        }

        return new self($items);
    }

    /**
     * The monthly minimum of an apprentice for the month of that day (social.apprentice.minimum_pay, F2918):
     * the share of the month's SMIC their year of contract and their age give, rounded to the cent. The
     * year counts from the day of hire, read on the month's first day (the third and later read the third);
     * an age reached changes the share from the first day of the month after the birthday (D6222-34), so
     * the age is read on the month's eve.
     */
    public static function apprenticeMinimum(RegulatorySnapshot $regulatory, \DateTimeImmutable $birthDate, \DateTimeImmutable $hiredOn, \DateTimeImmutable $day): Money
    {
        $first = $day->setTime(0, 0)->modify('first day of this month');
        $year = $hiredOn > $first ? 1 : min(3, $hiredOn->diff($first)->y + 1);
        $age = $birthDate->diff($first->modify('-1 day'))->y;
        $band = match (true) {
            $age < 18 => 'age_16_17',
            $age < 21 => 'age_18_20',
            $age < 26 => 'age_21_25',
            default => 'age_26_plus',
        };
        $share = $regulatory->decimal('social.apprentice.minimum_pay', 'year_'.$year, $band);

        return Money::euros($regulatory->decimal('social.smic_monthly')->multipliedBy($share)->dividedBy(100, 2, RoundingMode::HalfUp));
    }

    public function faults(): int
    {
        return \count(array_filter($this->items, static fn (array $item): bool => self::FAULT === $item['tone']));
    }

    /** Why the payslip may not be validated at level B, in French, or null. */
    public function refusal(): ?string
    {
        $faults = $this->faults();

        return 0 === $faults ? null : (1 === $faults ? 'Un contrôle du bulletin est en défaut : revoyez-le avant de valider.' : \sprintf('%d contrôles du bulletin sont en défaut : revoyez-les avant de valider.', $faults));
    }
}
