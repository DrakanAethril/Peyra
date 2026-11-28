<?php

declare(strict_types=1);

namespace App\Service\Accounting\Gateway;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\EntryOrigin;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;

/**
 * The one door through which sales, purchases, inventory, bank, fixed assets, payroll, VAT and the
 * year-end work reach the books (conception § 5, README of the plan § 8; ModulesDoNotPostTest):
 * a module hands an EntryProposal and the gateway applies the dossier's behaviour - it proposes the
 * entry in the brouillard, lets the student enter it, or posts it to the brouillard without anybody
 * touching it (« Comptabiliser », lot 2: the student checks it afterwards) - through the Ledger,
 * which checks it like any other entry. A proposal the Ledger refuses comes back as EntryRefused,
 * whatever the behaviour. A module whose screen shows its proposal before anything is written (the
 * pièces, step 2.2a) asks the behaviour first, then hands the entry the student made of it to
 * record().
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

        return new GatewayOutcome($behaviour, $proposal, $entry);
    }

    /** What the dossier's mission does with this module's proposals. */
    public function behaviourFor(Dossier $dossier, AccountingModule $module): AccountingBehaviour
    {
        return $this->behaviours->for($dossier, $module);
    }

    /**
     * The entry a person made on a module's screen from its proposal - the proposal as they
     * corrected it, or the entry they typed in the screen's empty grid (« Laisser saisir ») -,
     * written in the brouillard: « proposée par un module » unless the module proposed nothing.
     *
     * @throws EntryRefused
     */
    public function record(Dossier $dossier, EntryProposal $entry, User|string $actor): Entry
    {
        $origin = AccountingBehaviour::LetEnter === $this->behaviours->for($dossier, $entry->module) ? EntryOrigin::Manual : EntryOrigin::Proposal;

        return $this->ledger->draft($dossier, $entry->entry, $actor, $origin, $entry->module->value);
    }
}
