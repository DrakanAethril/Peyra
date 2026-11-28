<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollBody;
use App\Entity\Regulatory\RegulatoryValue;
use App\Service\Dossier\Demo\DemoPayrollFile;
use App\Service\Payroll\Engine\PayslipEstablishment;
use App\Service\Payroll\Plan\PayrollItemRules;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Payroll\Plan\PayrollPlanFile;
use App\Service\Regulatory\RegulatoryFile;
use App\Service\Regulatory\RegulatorySnapshot;

/**
 * What the payslip engine's tests read from the repository, with no database: the standard plan de
 * paie (with Menuiserie Vasseur's own rubriques, its complementary health insurance), the
 * regulatory values the two millésime files put in force on a day, and an establishment.
 */
trait StandardPlanFixture
{
    /** @return list<PayrollItemSpec> */
    private static function standardPlan(): array
    {
        $root = __DIR__.'/../../../..';
        $dossier = (new \ReflectionClass(Dossier::class))->newInstanceWithoutConstructor();
        $plan = PayrollPlanFile::readStandard($root.'/fixtures/payroll/standard-plan.yaml');
        $specs = [];
        foreach ($plan['items'] as $item) {
            $body = null === $item['body'] ? null : new PayrollBody($dossier, $item['body'], $plan['bodies'][$item['body']->value] ?? '', '');
            $specs[] = PayrollItemRules::check($item['draft'], $body) ?? throw new \LogicException($item['draft']->code.' does not pass the rules.');
        }

        return $specs;
    }

    /** @return list<PayrollItemSpec> the standard plan and the demonstration dossier's own rubriques (complementary health, 28,00 € a side) */
    private static function demoPlan(): array
    {
        $root = __DIR__.'/../../../..';
        $dossier = (new \ReflectionClass(Dossier::class))->newInstanceWithoutConstructor();
        $specs = self::standardPlan();
        foreach (DemoPayrollFile::read($root.'/fixtures/demo/payroll.yaml')->items as $item) {
            $body = null === $item['body'] ? null : new PayrollBody($dossier, $item['body'], 'Mutuelle', '');
            $specs[] = PayrollItemRules::check($item['draft'], $body) ?? throw new \LogicException($item['draft']->code.' does not pass the rules.');
        }

        return $specs;
    }

    /** Menuiserie Vasseur's establishment by default: 8,69 people, work accident 2,10 %, no mobility payment, 151,67 h. */
    private static function establishment(string $headcount = '8.69', ?string $mobility = null): PayslipEstablishment
    {
        return new PayslipEstablishment($headcount, '2.1', $mobility, '151.67');
    }

    private static function regulatoryOn(string $day): RegulatorySnapshot
    {
        $root = __DIR__.'/../../../..';
        $date = new \DateTimeImmutable($day);
        $rows = [];
        foreach ([2025, 2026] as $millesime) {
            foreach (RegulatoryFile::parse((string) file_get_contents($root.'/config/regulatory/'.$millesime.'.yaml'), $millesime.'.yaml', $millesime) as $entry) {
                $rows[] = RegulatoryValue::fromFile($entry, $millesime, new \DateTimeImmutable('2026-11-28 10:00'));
            }
        }
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
