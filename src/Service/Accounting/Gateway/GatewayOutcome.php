<?php

declare(strict_types=1);

namespace App\Service\Accounting\Gateway;

use App\Entity\Accounting\Entry;
use App\Enum\Accounting\AccountingBehaviour;

/**
 * What AccountingGateway did with a proposal: the behaviour it applied and the entry it wrote - in
 * the brouillard (« Proposer »), validated (« Comptabiliser »), or none (« Laisser saisir »: the
 * student enters it, the proposal is what the correction compares with).
 */
final readonly class GatewayOutcome
{
    public function __construct(
        public AccountingBehaviour $behaviour,
        public EntryProposal $proposal,
        public ?Entry $entry,
    ) {
    }
}
