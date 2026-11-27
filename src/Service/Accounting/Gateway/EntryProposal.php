<?php

declare(strict_types=1);

namespace App\Service\Accounting\Gateway;

use App\Enum\Accounting\AccountingModule;
use App\Service\Accounting\Ledger\EntryDraft;

/**
 * The entry a module asks for (an invoice's, a payment's, a payslip's…), handed to
 * AccountingGateway: the module never writes it itself. `reference` names what it comes from in
 * the module (« FV-2026-0001 »), for the correction and the screens.
 */
final readonly class EntryProposal
{
    public function __construct(
        public AccountingModule $module,
        public EntryDraft $entry,
        public string $reference,
    ) {
    }
}
