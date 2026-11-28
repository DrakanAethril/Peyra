<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollBody;
use App\Entity\Regulatory\RegulatoryValue;
use App\Enum\Payroll\PayrollBodyKind;
use App\Enum\Payroll\PayrollItemKind;
use App\Service\Payroll\Plan\PayrollItemRules;
use App\Service\Payroll\Plan\PayrollItemSheet;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Payroll\Plan\PayrollPlanFile;
use App\Service\Regulatory\RegulatoryFile;
use App\Service\Regulatory\RegulatorySnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The standard plan de paie (fixtures/payroll/standard-plan.yaml, step 4.1): every rubrique passes
 * the fiche's rules, and every regulatory value it names reads - in 2025 and in 2026 - from the
 * millésime files of the repository, at the dates the values change (May 2025, June 2026). The
 * expected rates are the ones read on the official sources (plan/donnees/reglementaire/), written
 * here by hand.
 */
final class StandardPayrollPlanTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    public function testEveryRubriquePassesTheRules(): void
    {
        $specs = $this->specs();

        self::assertCount(49, $specs);
        $codes = array_map(static fn (PayrollItemSpec $spec): string => $spec->code, $specs);
        self::assertSame($codes, array_values(array_unique($codes)), 'Each code once.');
        $families = array_count_values(array_map(static fn (PayrollItemSpec $spec): string => $spec->kind->value, $specs));
        self::assertSame(['gross' => 14, 'contribution' => 30, 'not_subject' => 2, 'deduction' => 3], $families);
        // The benefits in kind the deduction 4000 takes back off the net.
        self::assertSame(['1400', '1410'], array_values(array_map(static fn (PayrollItemSpec $spec): string => $spec->code, array_filter($specs, static fn (PayrollItemSpec $spec): bool => $spec->benefitInKind))));
    }

    public function testEveryRegulatoryValueReadsInBothMillesimes(): void
    {
        foreach (['2025-01-15', '2025-06-15', '2026-01-15', '2026-07-15'] as $day) {
            $snapshot = $this->snapshot($day);
            foreach ($this->specs() as $spec) {
                foreach ([$spec->employeeRate, $spec->employerRate] as $rate) {
                    self::assertNull(PayrollItemSheet::unreadable($rate, $snapshot), $spec->code.' au '.$day);
                }
            }
        }
    }

    public function testTheRatesTheyReadAsTheSourcesSay(): void
    {
        $read = function (string $day, string $code, string $side): string {
            $snapshot = $this->snapshot($day);
            foreach ($this->specs() as $spec) {
                if ($spec->code === $code) {
                    $rate = 'employee' === $side ? $spec->employeeRate : $spec->employerRate;

                    return (string) $snapshot->decimal((string) $rate->getReferenceKey(), ...$rate->pathSegments())->strippedOfTrailingZeros();
                }
            }
            self::fail('No rubrique '.$code);
        };

        // Vieillesse plafonnée 6,90 / 8,55 both years; déplafonnée employer 2,02 then 2,11 (décret 2025-1446).
        self::assertSame(['6.9', '8.55'], [$read('2026-01-15', '2200', 'employee'), $read('2026-01-15', '2200', 'employer')]);
        self::assertSame('2.02', $read('2025-06-15', '2210', 'employer'));
        self::assertSame('2.11', $read('2026-01-15', '2210', 'employer'));
        // Maladie: the full rate 13 % in both years (2025's reduced 7 % is the engine's, below 2,25 SMIC).
        self::assertSame('13', $read('2025-06-15', '2000', 'employer'));
        self::assertSame('13', $read('2026-07-15', '2000', 'employer'));
        // Chômage 4,05 % until April 2025, then 4,00 %.
        self::assertSame('4.05', $read('2025-01-15', '2500', 'employer'));
        self::assertSame('4', $read('2025-06-15', '2500', 'employer'));
        // Agirc-Arrco T1 3,15 / 4,72, CEG T1 0,86 / 1,29: the 4,01 / 6,01 of maquette 4.
        self::assertSame(['3.15', '4.72', '0.86', '1.29'], [$read('2026-01-15', '2300', 'employee'), $read('2026-01-15', '2300', 'employer'), $read('2026-01-15', '2310', 'employee'), $read('2026-01-15', '2310', 'employer')]);
        // FNAL below 50 0,10 %; vocational training below 11 0,55 %; apprenticeship tax 0,68 %.
        self::assertSame(['0.1', '0.55', '0.68'], [$read('2026-01-15', '2610', 'employer'), $read('2026-01-15', '2640', 'employer'), $read('2026-01-15', '2660', 'employer')]);
        // CSG 6,80 + 2,40 and CRDS 0,50; overtime 25 % and 50 %, complementary hours 10 % and 25 %.
        self::assertSame(['6.8', '2.4', '0.5'], [$read('2026-01-15', '2800', 'employee'), $read('2026-01-15', '2810', 'employee'), $read('2026-01-15', '2820', 'employee')]);
        self::assertSame(['25', '50', '10', '25'], [$read('2026-01-15', '1100', 'employee'), $read('2026-01-15', '1110', 'employee'), $read('2026-01-15', '1120', 'employee'), $read('2025-06-15', '1130', 'employee')]);
        // Meal allowance on travel 10,40 €, benefit in kind 5,50 € a meal; flat deduction 1,50 € an hour below 20.
        self::assertSame(['10.4', '5.5', '1.5'], [$read('2026-01-15', '3000', 'employee'), $read('2026-01-15', '1410', 'employee'), $read('2026-01-15', '2920', 'employer')]);
    }

    /** @return list<PayrollItemSpec> */
    private function specs(): array
    {
        $dossier = (new \ReflectionClass(Dossier::class))->newInstanceWithoutConstructor();
        $plan = PayrollPlanFile::readStandard(self::ROOT.'/fixtures/payroll/standard-plan.yaml');
        $specs = [];
        foreach ($plan['items'] as $item) {
            $body = null === $item['body'] ? null : new PayrollBody($dossier, $item['body'], $plan['bodies'][$item['body']->value] ?? '', '');
            $spec = PayrollItemRules::check($item['draft'], $body);
            self::assertNotNull($spec, $item['draft']->code.' : '.implode(' ', $item['draft']->errors));
            self::assertSame(PayrollItemKind::Contribution === $spec->kind, $body instanceof PayrollBody);
            if (PayrollBodyKind::Health === $item['body']) {
                self::fail('The standard plan names no health insurer: its contract is the company’s.');
            }
            $specs[] = $spec;
        }

        return $specs;
    }

    /** The values the two millésime files put in force on that day, as the database would after app:regulatory:sync. */
    private function snapshot(string $day): RegulatorySnapshot
    {
        $date = new \DateTimeImmutable($day);
        $rows = [];
        foreach ([2025, 2026] as $millesime) {
            foreach (RegulatoryFile::parse((string) file_get_contents(self::ROOT.'/config/regulatory/'.$millesime.'.yaml'), $millesime.'.yaml', $millesime) as $entry) {
                $rows[] = RegulatoryValue::fromFile($entry, $millesime, new \DateTimeImmutable('2026-11-28 10:00'));
            }
        }
        // Reading order: by effective date, the later file last; the last row taking effect by the day wins.
        usort($rows, static fn (RegulatoryValue $a, RegulatoryValue $b): int => $a->getEffectiveFrom() <=> $b->getEffectiveFrom());
        $inForce = [];
        foreach ($rows as $row) {
            if ($row->getEffectiveFrom() <= $date) {
                $inForce[$row->getKey()] = $row;
            }
        }

        return new RegulatorySnapshot($date, $inForce);
    }
}
