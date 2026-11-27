<?php

declare(strict_types=1);

namespace App\Service\Accounting\Gateway;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\EntryOrigin;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;

/**
 * The one door through which sales, purchases, inventory, bank, fixed assets, payroll, VAT and the
 * year-end work reach the books (conception § 5, README of the plan § 8; ModulesDoNotPostTest):
 * a module hands an EntryProposal and the gateway applies the dossier's behaviour - it proposes the
 * entry in the brouillard, lets the student enter it, or posts it validated - through the Ledger,
 * which checks it like any other entry. A proposal the Ledger refuses comes back as EntryRefused,
 * whatever the behaviour.
 */
final readonly class AccountingGateway
{
    public function __construct(
        private Ledger $ledger,
        private AccountingBehaviours $behaviours,
    ) {
    }

    /** @throws EntryRefused */
    public function submit(Dossier $dossier, EntryProposal $proposal, User|string $actor): GatewayOutcome
    {
        $behaviour = $this->behaviours->for($dossier, $proposal->module);

        if (AccountingBehaviour::LetEnter === $behaviour) {
            // Nothing is written, but a proposal that could not be written is still refused.
            if (null === $this->ledger->check($dossier, $proposal->entry)) {
                throw new EntryRefused($proposal->entry->errors);
            }

            return new GatewayOutcome($behaviour, $proposal, null);
        }

        $entry = $this->ledger->draft($dossier, $proposal->entry, $actor, EntryOrigin::Proposal, $proposal->module->value);
        if (AccountingBehaviour::Post === $behaviour) {
            $this->ledger->validate([$entry], $actor);
        }

        return new GatewayOutcome($behaviour, $proposal, $entry);
    }
}
