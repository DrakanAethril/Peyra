<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Assistance;

use App\Enum\Accounting\AssistanceLevel;
use App\Service\Shared\Assistance\Assistance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What each level shows, proposes and stops (design-ux.md § 3.2, the « Assistance » column of the
 * screens built so far), level by level - read in the table, not from the code.
 */
final class AssistanceTest extends TestCase
{
    /**
     * @return iterable<string, array{AssistanceLevel, array<string, bool>}>
     */
    public static function levels(): iterable
    {
        yield 'Guidé' => [AssistanceLevel::Guided, [
            'runsControls' => true, 'blocks' => true, 'allowsPartialLettering' => false, 'proposesLettering' => true,
            'opensGuidedEntry' => true, 'refusesAccountOutsideChart' => true, 'signalsAccountOutsideChart' => true,
            'requiresHiringDeclarationFields' => true, 'explainsPortalRejections' => true, 'flagsQuantityGaps' => true,
            'calculatesPayslip' => true, 'controlsPayslip' => true, 'blocksPayslipControls' => false, 'startsPayslipEmpty' => false, 'showsStockValuation' => true, 'requiresStockGapReason' => true, 'proposesBankPointing' => true, 'proposesBankEntry' => true, 'blocksReconciliationGaps' => true, 'signalsReconciliationGaps' => true, 'proposesPaymentAllocation' => true, 'proposesPaymentSelection' => true, 'refusesDoublePayment' => true, 'signalsDoublePayment' => true,
        ]];
        yield 'Contrôles bloquants' => [AssistanceLevel::Blocking, [
            'runsControls' => true, 'blocks' => true, 'allowsPartialLettering' => false, 'proposesLettering' => false,
            'opensGuidedEntry' => true, 'refusesAccountOutsideChart' => true, 'signalsAccountOutsideChart' => true,
            'requiresHiringDeclarationFields' => true, 'explainsPortalRejections' => false, 'flagsQuantityGaps' => false,
            'calculatesPayslip' => true, 'controlsPayslip' => true, 'blocksPayslipControls' => true, 'startsPayslipEmpty' => false, 'showsStockValuation' => false, 'requiresStockGapReason' => true, 'proposesBankPointing' => false, 'proposesBankEntry' => true, 'blocksReconciliationGaps' => true, 'signalsReconciliationGaps' => true, 'proposesPaymentAllocation' => false, 'proposesPaymentSelection' => false, 'refusesDoublePayment' => true, 'signalsDoublePayment' => true,
        ]];
        yield 'Avertissements' => [AssistanceLevel::Warnings, [
            'runsControls' => true, 'blocks' => false, 'allowsPartialLettering' => true, 'proposesLettering' => false,
            'opensGuidedEntry' => false, 'refusesAccountOutsideChart' => false, 'signalsAccountOutsideChart' => true,
            'requiresHiringDeclarationFields' => false, 'explainsPortalRejections' => false, 'flagsQuantityGaps' => false,
            'calculatesPayslip' => true, 'controlsPayslip' => true, 'blocksPayslipControls' => false, 'startsPayslipEmpty' => false, 'showsStockValuation' => false, 'requiresStockGapReason' => false, 'proposesBankPointing' => false, 'proposesBankEntry' => false, 'blocksReconciliationGaps' => false, 'signalsReconciliationGaps' => true, 'proposesPaymentAllocation' => false, 'proposesPaymentSelection' => false, 'refusesDoublePayment' => false, 'signalsDoublePayment' => true,
        ]];
        yield 'Aucun filet' => [AssistanceLevel::None, [
            'runsControls' => false, 'blocks' => false, 'allowsPartialLettering' => true, 'proposesLettering' => false,
            'opensGuidedEntry' => false, 'refusesAccountOutsideChart' => false, 'signalsAccountOutsideChart' => false,
            'requiresHiringDeclarationFields' => false, 'explainsPortalRejections' => false, 'flagsQuantityGaps' => false,
            'calculatesPayslip' => false, 'controlsPayslip' => false, 'blocksPayslipControls' => false, 'startsPayslipEmpty' => true, 'showsStockValuation' => false, 'requiresStockGapReason' => false, 'proposesBankPointing' => false, 'proposesBankEntry' => false, 'blocksReconciliationGaps' => false, 'signalsReconciliationGaps' => false, 'proposesPaymentAllocation' => false, 'proposesPaymentSelection' => false, 'refusesDoublePayment' => false, 'signalsDoublePayment' => false,
        ]];
    }

    /** @param array<string, bool> $expected */
    #[DataProvider('levels')]
    public function testEachLevelDecidesWhatTheTableSays(AssistanceLevel $level, array $expected): void
    {
        $assistance = Assistance::at($level);

        self::assertSame($expected, [
            'runsControls' => $assistance->runsControls(),
            'blocks' => $assistance->blocks(),
            'allowsPartialLettering' => $assistance->allowsPartialLettering(),
            'proposesLettering' => $assistance->proposesLettering(),
            'opensGuidedEntry' => $assistance->opensGuidedEntry(false),
            'refusesAccountOutsideChart' => $assistance->refusesAccountOutsideChart(),
            'signalsAccountOutsideChart' => $assistance->signalsAccountOutsideChart(),
            'requiresHiringDeclarationFields' => $assistance->requiresHiringDeclarationFields(),
            'explainsPortalRejections' => $assistance->explainsPortalRejections(),
            'flagsQuantityGaps' => $assistance->flagsQuantityGaps(),
            'calculatesPayslip' => $assistance->calculatesPayslip(),
            'controlsPayslip' => $assistance->controlsPayslip(),
            'blocksPayslipControls' => $assistance->blocksPayslipControls(),
            'startsPayslipEmpty' => $assistance->startsPayslipEmpty(),
            'showsStockValuation' => $assistance->showsStockValuation(),
            'requiresStockGapReason' => $assistance->requiresStockGapReason(),
            'proposesBankPointing' => $assistance->proposesBankPointing(),
            'proposesBankEntry' => $assistance->proposesBankEntry(),
            'blocksReconciliationGaps' => $assistance->blocksReconciliationGaps(),
            'signalsReconciliationGaps' => $assistance->signalsReconciliationGaps(),
            'proposesPaymentAllocation' => $assistance->proposesPaymentAllocation(),
            'proposesPaymentSelection' => $assistance->proposesPaymentSelection(),
            'refusesDoublePayment' => $assistance->refusesDoublePayment(),
            'signalsDoublePayment' => $assistance->signalsDoublePayment(),
        ]);
        self::assertSame($level, $assistance->level);
    }

    public function testATeacherAlwaysHasTheGuidedEntry(): void
    {
        foreach (AssistanceLevel::cases() as $level) {
            self::assertTrue(Assistance::at($level)->opensGuidedEntry(true), $level->label());
        }
    }
}
