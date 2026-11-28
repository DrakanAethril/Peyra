<?php

declare(strict_types=1);

namespace App\Tests\Service\Mission;

use App\Enum\Mission\MissionModule;
use App\Service\Mission\MissionRules;
use PHPUnit\Framework\TestCase;

/** What a mission's tabs must hold (step 2.9a), and which module an address belongs to. */
final class MissionRulesTest extends TestCase
{
    public function testTheGeneralTabNeedsATitleAndAnOpenModule(): void
    {
        self::assertSame([], MissionRules::general('Janvier, de la pièce à l’écriture', '', ['documents', 'accounting']));
        self::assertSame(['title', 'modules'], array_keys(MissionRules::general('', '', [])));
        self::assertSame(['title', 'statement', 'modules'], array_keys(MissionRules::general(str_repeat('a', 121), str_repeat('b', 6001), ['documents', 'treasury'])));
    }

    public function testEachTaskHasItsTitle(): void
    {
        self::assertSame([], MissionRules::tasks([['title' => 'Contrôler les factures', 'instruction' => ''], ['title' => 'Lettrer', 'instruction' => 'Les clients réglés.']]));
        self::assertSame(['task.1.title', 'task.1.instruction'], array_keys(MissionRules::tasks([['title' => 'Contrôler', 'instruction' => ''], ['title' => '', 'instruction' => str_repeat('x', 2001)]])));
        self::assertSame(['tasks'], array_keys(MissionRules::tasks(array_fill(0, 21, ['title' => 'Tâche', 'instruction' => '']))));
    }

    public function testOnlyTheReferentialsActivitiesAreAimedAt(): void
    {
        self::assertSame([], MissionRules::activities(['A1.2', 'A1.5'], ['A1.1', 'A1.2', 'A1.5']));
        self::assertSame(['activities'], array_keys(MissionRules::activities(['A9.9'], ['A1.1'])));
    }

    public function testAnAddressBelongsToTheModuleItNames(): void
    {
        self::assertSame(MissionModule::Sales, MissionModule::ofPath('/d/12/sales/customers'));
        self::assertSame(MissionModule::Purchases, MissionModule::ofPath('/d/12/purchases/invoices'));
        self::assertSame(MissionModule::Purchases, MissionModule::ofPath('/d/12/inventory/articles'));
        self::assertSame(MissionModule::Sales, MissionModule::ofPath('/d/12/payments/receipts/new'));
        self::assertSame(MissionModule::Purchases, MissionModule::ofPath('/d/12/payments/disbursements/proposal'));
        self::assertSame(MissionModule::Accounting, MissionModule::ofPath('/d/12/accounting/entry'));
        self::assertSame(MissionModule::Documents, MissionModule::ofPath('/d/12/documents/4'));
        self::assertSame(MissionModule::Payroll, MissionModule::ofPath('/d/12/payroll/payslips'));
        self::assertSame(MissionModule::Closing, MissionModule::ofPath('/d/12/assets'));
        self::assertNull(MissionModule::ofPath('/d/12'));
        self::assertNull(MissionModule::ofPath('/d/12/company'));
        self::assertNull(MissionModule::ofPath('/d/12/restore-points'));
        self::assertNull(MissionModule::ofPath('/teacher/library'));
    }
}
