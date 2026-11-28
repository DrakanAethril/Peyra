<?php

declare(strict_types=1);

namespace App\Service\Payroll\Declaration;

use App\Entity\Payroll\PayrollBody;
use App\Service\Payroll\Statement\PayrollMonthStatements;
use App\Service\Payroll\Statement\PayrollStatementRow;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * The preparatory state of a month's DSN (step 4.6; lot 4 § 4.6, reduced on 2026-11-28: read only, no
 * deposit), pure: the month's validated payslips in the DSN's blocks - the individuals with their
 * contract and remuneration (DsnIndividual, each amount its payslip's), the contributions by body
 * (PayrollMonthStatements::bodies()), the payments due (SocialDue) - and a few consistency checks:
 *
 * - the contributions declared body by body add up to the état des charges' total (step 4.5);
 * - every employee the month pays has a validated payslip, and no other has one;
 * - every individual has a NIR, without which a DSN identifies nobody.
 */
final readonly class DsnPreparation
{
    /**
     * @param list<DsnIndividual> $individuals
     * @param list<SocialDue>     $payments
     * @param list<DsnControl>    $controls
     */
    private function __construct(
        public PayrollMonthStatements $statements,
        public array $individuals,
        public array $payments,
        public array $controls,
    ) {
    }

    /**
     * @param list<DsnIndividual>   $individuals the month's validated payslips, one each
     * @param array<string, string> $expected    the employees the month pays, name by matricule
     * @param list<SocialDue>       $payments    what the month leaves to pay to the bodies and the tax office
     */
    public static function of(PayrollMonthStatements $statements, array $individuals, array $expected, array $payments): self
    {
        return new self($statements, $individuals, $payments, self::controls($statements, $individuals, $expected));
    }

    /** @return list<array{key: string, body: ?PayrollBody, rows: list<PayrollStatementRow>, employee: Money, employer: Money, total: Money}> */
    public function bodies(): array
    {
        return $this->statements->bodies();
    }

    public function contributions(): Money
    {
        return Money::sum(array_map(static fn (array $state): Money => $state['total'], $this->bodies()));
    }

    public function holds(): bool
    {
        return [] === array_filter($this->controls, static fn (DsnControl $control): bool => !$control->holds);
    }

    /**
     * @param list<DsnIndividual>   $individuals
     * @param array<string, string> $expected
     *
     * @return list<DsnControl>
     */
    private static function controls(PayrollMonthStatements $statements, array $individuals, array $expected): array
    {
        $declared = Money::sum(array_map(static fn (array $state): Money => $state['total'], $statements->bodies()));
        $charges = Money::sum(array_map(static fn (PayrollStatementRow $row): Money => $row->total(), $statements->charges()));
        $controls = [new DsnControl(
            'Total des cotisations par organisme égal à l’état des charges',
            $declared->equals($charges),
            \sprintf('%s déclarés par organisme, %s à l’état des charges.', FrenchFormat::amountInEuros($declared), FrenchFormat::amountInEuros($charges)),
        )];

        $validated = [];
        foreach ($individuals as $individual) {
            $validated[$individual->number] = $individual->lastName.' '.$individual->firstName;
        }
        $missing = array_diff_key($expected, $validated);
        $extra = array_diff_key($validated, $expected);
        $findings = [];
        if ([] !== $missing) {
            $findings[] = 'Sans bulletin validé : '.self::names($missing).'.';
        }
        if ([] !== $extra) {
            $findings[] = 'Bulletin validé hors des salariés du mois : '.self::names($extra).'.';
        }
        $controls[] = new DsnControl(
            'Un bulletin validé par salarié présent dans le mois',
            [] === $findings,
            [] === $findings ? \sprintf('%d salarié%s, %d bulletin%s validé%s.', \count($expected), \count($expected) > 1 ? 's' : '', \count($validated), \count($validated) > 1 ? 's' : '', \count($validated) > 1 ? 's' : '') : implode(' ', $findings),
        );

        $withoutNir = [];
        foreach ($individuals as $individual) {
            if (null === $individual->nir || '' === $individual->nir) {
                $withoutNir[$individual->number] = $individual->lastName.' '.$individual->firstName;
            }
        }
        $controls[] = new DsnControl(
            'Numéro de sécurité sociale de chaque salarié',
            [] === $withoutNir,
            [] === $withoutNir ? 'Renseigné pour chacun.' : 'Manquant : '.self::names($withoutNir).'.',
        );

        return $controls;
    }

    /** @param array<string, string> $names by matricule */
    private static function names(array $names): string
    {
        ksort($names, \SORT_STRING);
        $listed = [];
        foreach ($names as $number => $name) {
            $listed[] = $number.' '.$name;
        }

        return implode(', ', $listed);
    }
}
