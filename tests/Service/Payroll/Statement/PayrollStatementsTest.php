<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Statement;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollBody;
use App\Enum\Payroll\PayrollBodyKind;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Dossier\Demo\DemoPayrollFile;
use App\Service\Payroll\Engine\GeneralReduction;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipExplanation;
use App\Service\Payroll\Engine\PayslipRow;
use App\Service\Payroll\Engine\PayslipSource;
use App\Service\Payroll\Engine\PayslipTotals;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Plan\PayrollItemRules;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Payroll\Plan\PayrollPlanFile;
use App\Service\Payroll\Statement\PayrollAllocation;
use App\Service\Payroll\Statement\PayrollChart;
use App\Service\Payroll\Statement\PayrollEntries;
use App\Service\Payroll\Statement\PayrollMonthPayslip;
use App\Service\Payroll\Statement\PayrollMonthStatements;
use App\Service\Payroll\Statement\PayrollStatementRow;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The payroll's states and entry (step 4.5), pure, on two payslips written by hand with round amounts
 * (not calculated by the engine: what is tested is where each amount goes), and the standard plan's
 * rubriques. Every expected figure is summed by hand in the comments.
 *
 * S001 Martin: base 2 000,00, car 100,00 (benefit in kind); old age capped 138,00 / 171,00 (Urssaf),
 * complementary T1 84,00 / 126,00 (pension body, own account 437100), health 28,00 / 28,00 (health body,
 * default 437000); general reduction 300,00 = Urssaf 240,00 + pension 60,00; meals 50,00 not subject;
 * deductions: car 100,00, advance 200,00, tax 80,00. Net: 2 100 − 250 + 50 − 100 − 200 − 80 = 1 520,00.
 *
 * S002 Bernard: base 1 500,00; old age capped 103,50 / 128,25; complementary T1 63,00 / 94,50; no tax.
 * Net: 1 500 − 166,50 = 1 333,50.
 */
final class PayrollStatementsTest extends TestCase
{
    public function testTheMonthsEntry(): void
    {
        $entry = PayrollEntries::month($this->statements(), 'OD');

        self::assertSame(['OD', '2026-03-31', 'PAIE-2026-03', 'Paie de mars 2026'], [$entry->journal, $entry->date, $entry->document, $entry->label]);
        self::assertSame([
            // Debits: what is charged.
            '625100 D 5000',      // meals
            '641100 D 350000',    // 2 000 + 1 500
            '641400 D 10000',     // the car
            '645100 D 5925',      // 171 − 240 + 128,25
            '645200 D 2800',      // health, employer
            '645300 D 16050',     // 126 − 60 + 94,50
            // Credits: what is owed.
            '425000 C 20000',     // the advance
            '431000 C 30075',     // (138 + 171) − 240 + (103,50 + 128,25)
            '437000 C 5600',      // health, both shares
            '437100 C 30750',     // (84 + 126) − 60 + (63 + 94,50)
            '442100 C 8000',      // the tax withheld
            '649000 C 10000',     // the car taken back off the net
            '421000 C 285350',    // 1 520 + 1 333,50
        ], array_map(static fn (GridLine $line): string => $line->account.' '.($line->debit > 0 ? 'D '.$line->debit : 'C '.$line->credit), $entry->lines));
        self::assertSame(389775, $entry->totalDebit(), '50 + 3 500 + 100 + 59,25 + 28 + 160,50.');
        self::assertSame($entry->totalDebit(), $entry->totalCredit());
        self::assertSame(285350, $this->statements()->totals()['netPay']->cents, 'The net credited is the payslips’ net paid.');
        self::assertSame('442100', $this->statements()->withholdingAccount, 'The tax is paid back from the account 4200 credits (step 4.6).');
    }

    public function testTheCotisationsByBody(): void
    {
        $bodies = $this->statements()->bodies();

        self::assertSame(['Mutuelle', 'Caisse de retraite', 'Urssaf'], array_map(static fn (array $state): string => $state['body']?->getName() ?? '', $bodies));
        self::assertSame([5600, 30750, 30075], array_map(static fn (array $state): int => $state['total']->cents, $bodies));
        self::assertSame([
            '2300 Retraite complémentaire tranche 1 14700 22050',
            '2900 Réduction générale des cotisations patronales (part retraite complémentaire) 0 -6000',
        ], self::rows($bodies[1]['rows']));
        self::assertSame([
            '2200 Vieillesse plafonnée 24150 29925',
            '2900 Réduction générale des cotisations patronales 0 -24000',
        ], self::rows($bodies[2]['rows']));
    }

    public function testTheJournalTheChargesAndTheDetail(): void
    {
        $statements = $this->statements();
        $journal = $statements->journal();

        self::assertSame(['gross', 'contribution', 'not_subject', 'deduction'], array_keys($journal));
        self::assertSame(['1000 Salaire de base 350000 0', '1400 Avantage en nature véhicule 10000 0'], self::rows($journal['gross']));
        self::assertSame('303.34', (string) $journal['gross'][0]->base, '151,67 h twice.');
        self::assertSame(['S001', 'S002'], $journal['gross'][0]->numbers);
        $reduction = $journal['contribution'][3];
        self::assertSame(['2900', -30000, '2100.00'], [$reduction->key, $reduction->employer->cents, (string) $reduction->base], 'Both shares of the reduction, on the gross of S001 alone.');
        self::assertSame(['4000 Avantages en nature (déduction) -10000 0', '4100 Acompte -20000 0', '4200 Prélèvement à la source -8000 0'], self::rows($journal['deduction']));

        self::assertSame([
            'complementary_health Complémentaire santé 2800 2800',
            'social_security_capped Sécurité sociale plafonnée 24150 29925',
            'complementary_tranche_1 Complémentaire tranche 1 14700 22050',
            'reductions Exonérations et allègements de cotisations 0 -30000',
        ], self::rows($statements->charges()));

        self::assertSame(['S001 Martin Paul 13800 17100', 'S002 Bernard Anne 10350 12825'], self::rows($statements->detail(['item' => '2200'])));
        $pension = $statements->bodies()[1]['key'];
        self::assertSame(['S001 Martin Paul 8400 6600', 'S002 Bernard Anne 6300 9450'], self::rows($statements->detail(['body' => $pension])), '126 − 60 on S001.');
        self::assertSame(['S001 Martin Paul 0 -30000'], self::rows($statements->detail(['line' => PayslipLine::Reductions->value])));
    }

    public function testAPayment(): void
    {
        $payment = PayrollEntries::payment('421000', Money::euros('2853.50'), 'BQ', '512000', new \DateTimeImmutable('2026-03-31'), 'VIRSAL-2026-03', 'Salaires nets de mars 2026');

        self::assertSame([['421000', 285350, 0], ['512000', 0, 285350]], array_map(static fn (GridLine $line): array => [$line->account, $line->debit, $line->credit], $payment->lines));
    }

    /** @param list<PayrollStatementRow> $rows */
    private static function rows(array $rows): array
    {
        return array_map(static fn (PayrollStatementRow $row): string => \sprintf('%s %s %d %d', $row->key, $row->label, $row->employee->cents, $row->employer->cents), $rows);
    }

    private function statements(): PayrollMonthStatements
    {
        $dossier = (new \ReflectionClass(Dossier::class))->newInstanceWithoutConstructor();
        $bodies = [
            PayrollBodyKind::Urssaf->value => new PayrollBody($dossier, PayrollBodyKind::Urssaf, 'Urssaf', ''),
            PayrollBodyKind::Retirement->value => new PayrollBody($dossier, PayrollBodyKind::Retirement, 'Caisse de retraite', '', '437100'),
            PayrollBodyKind::Health->value => new PayrollBody($dossier, PayrollBodyKind::Health, 'Mutuelle', ''),
            PayrollBodyKind::Provident->value => new PayrollBody($dossier, PayrollBodyKind::Provident, 'Prévoyance', ''),
        ];
        $root = __DIR__.'/../../../..';
        $plan = [];
        foreach ([...PayrollPlanFile::readStandard($root.'/fixtures/payroll/standard-plan.yaml')['items'], ...DemoPayrollFile::read($root.'/fixtures/demo/payroll.yaml')->items] as $item) {
            $plan[] = PayrollItemRules::check($item['draft'], null === $item['body'] ? null : $bodies[$item['body']->value]) ?? throw new \LogicException($item['draft']->code);
        }
        $chart = PayrollChart::of($plan, array_values($bodies));
        $spec = static fn (string $code): PayrollItemSpec => array_values(array_filter($plan, static fn (PayrollItemSpec $s): bool => $s->code === $code))[0];

        $martin = new Payslip([
            self::row($spec('1000'), '2000.00', '151.67', PayslipUnit::Hours),
            self::row($spec('1400'), '100.00'),
            self::row($spec('2200'), '138.00', '2100.00', PayslipUnit::Euros, '171.00'),
            self::row($spec('2300'), '84.00', '2100.00', PayslipUnit::Euros, '126.00'),
            self::row($spec('2010'), '28.00', null, null, '28.00'),
            new PayslipRow('2900', $spec('2900')->label, PayrollItemKind::Contribution, BigDecimal::of('2100.00'), PayslipUnit::Euros, null, null, Money::zero(), self::explained(), BigDecimal::of('0.1428'), PayslipUnit::Coefficient, Money::euros('-300'), PayslipLine::Reductions),
            self::row($spec('3000'), '50.00'),
            self::row($spec('4000'), '-100.00'),
            self::row($spec('4100'), '-200.00'),
            self::row($spec('4200'), '-80.00'),
        ], Money::euros('2100'), new GeneralReduction('rgdu', Money::euros('2100'), Money::euros('1823.03'), BigDecimal::of('0.1428'), Money::euros('300'), Money::euros('240'), Money::euros('60'), [], new PayslipSource('social.rgdu.parameters', null, 'RGDU', new \DateTimeImmutable('2026-01-01'), null, true), Money::euros('2100'), Money::euros('1823.03'), Money::euros('300')),
            self::totals('250', '25', '1520'), PayslipYearToDate::none());
        $bernard = new Payslip([
            self::row($spec('1000'), '1500.00', '151.67', PayslipUnit::Hours),
            self::row($spec('2200'), '103.50', '1500.00', PayslipUnit::Euros, '128.25'),
            self::row($spec('2300'), '63.00', '1500.00', PayslipUnit::Euros, '94.50'),
        ], Money::euros('1500'), null, self::totals('166.50', '222.75', '1333.50'), PayslipYearToDate::none());

        return PayrollMonthStatements::of(new \DateTimeImmutable('2026-03-01'), [new PayrollMonthPayslip('S001', 'Martin Paul', $martin), new PayrollMonthPayslip('S002', 'Bernard Anne', $bernard)], $chart);
    }

    private static function row(PayrollItemSpec $spec, string $employee, ?string $base = null, ?PayslipUnit $unit = null, ?string $employer = null): PayslipRow
    {
        return new PayslipRow($spec->code, $spec->label, $spec->kind, null === $base ? null : BigDecimal::of($base), $unit, null, null, Money::euros($employee), self::explained(), null, null, null === $employer ? null : Money::euros($employer), $spec->line);
    }

    private static function totals(string $employee, string $employer, string $net): PayslipTotals
    {
        return new PayslipTotals(Money::euros($employee), Money::euros($employer), Money::zero(), Money::zero(), Money::zero(), Money::euros($net), BigDecimal::zero(), true, Money::zero(), Money::euros($net), Money::zero(), []);
    }

    private static function explained(): PayslipExplanation
    {
        return new PayslipExplanation('', '', '');
    }

    public function testABodyKeyIsItsId(): void
    {
        $dossier = (new \ReflectionClass(Dossier::class))->newInstanceWithoutConstructor();
        self::assertStringStartsWith('o', PayrollAllocation::bodyKey(new PayrollBody($dossier, PayrollBodyKind::Urssaf, 'Urssaf', '')), 'A body not stored is told by its object.');
    }
}
