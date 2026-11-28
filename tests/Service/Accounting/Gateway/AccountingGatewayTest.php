<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Gateway;

use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\EntryState;
use App\Repository\Accounting\EntryRepository;
use App\Service\Accounting\Gateway\AccountingBehaviours;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Gateway\EntryProposal;
use App\Service\Accounting\Gateway\ProposeByDefault;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsLedger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AccountingGateway, the one door of the modules (README of the plan, § 8): without a mission it
 * proposes in the brouillard; the two other behaviours a mission will choose (step 2.9) post the
 * entry to the brouillard on their own or write nothing; a proposal the Ledger refuses is refused
 * whatever the behaviour. record() writes what a person made of a proposal on a module's screen.
 */
final class AccountingGatewayTest extends KernelTestCase
{
    use BuildsLedger;

    public function testWithoutAMissionTheEngineProposesInTheBrouillard(): void
    {
        $dossier = $this->ledgerDossier();
        $outcome = new AccountingGateway(self::getContainer()->get(Ledger::class), new ProposeByDefault())->submit($dossier, $this->proposal(), 'test');

        self::assertSame(AccountingBehaviour::Propose, $outcome->behaviour);
        self::assertNotNull($outcome->entry);
        self::assertSame([EntryState::Draft, EntryOrigin::Proposal, 'sales'], [$outcome->entry->getState(), $outcome->entry->getOrigin(), $outcome->entry->getSource()]);
    }

    public function testPostingDraftsAndLettingEnterWritesNothing(): void
    {
        $dossier = $this->ledgerDossier();

        $posted = $this->gateway(AccountingBehaviour::Post)->submit($dossier, $this->proposal(), 'test');
        self::assertNotNull($posted->entry);
        self::assertSame([EntryState::Draft, null], [$posted->entry->getState(), $posted->entry->getNumberLabel()], 'Comptabiliser passe l’écriture au brouillard, sans la valider.');

        $left = $this->gateway(AccountingBehaviour::LetEnter)->submit($dossier, $this->proposal(), 'test');
        self::assertNull($left->entry);
        self::assertSame(1, self::getContainer()->get(EntryRepository::class)->count([]), 'Laisser saisir n’écrit rien.');
    }

    public function testARefusedProposalIsRefusedWhateverTheBehaviour(): void
    {
        $dossier = $this->ledgerDossier();
        foreach (AccountingBehaviour::cases() as $behaviour) {
            $proposal = $this->proposal();
            $proposal->entry->lines[0]->debit = Money::cents(1);
            try {
                $this->gateway($behaviour)->submit($dossier, $proposal, 'test');
                self::fail($behaviour->label());
            } catch (EntryRefused $refused) {
                self::assertStringStartsWith('L’écriture n’est pas équilibrée', $refused->getMessage(), $behaviour->label());
            }
        }
        self::assertSame(0, self::getContainer()->get(EntryRepository::class)->count([]));
    }

    public function testRecordWritesWhatThePersonMadeOfTheProposal(): void
    {
        $dossier = $this->ledgerDossier();

        $proposed = $this->gateway(AccountingBehaviour::Propose)->record($dossier, $this->proposal(), 'test');
        self::assertSame([EntryState::Draft, EntryOrigin::Proposal, 'sales'], [$proposed->getState(), $proposed->getOrigin(), $proposed->getSource()]);

        $typed = $this->gateway(AccountingBehaviour::LetEnter)->record($dossier, $this->proposal(), 'test');
        self::assertSame([EntryState::Draft, EntryOrigin::Manual], [$typed->getState(), $typed->getOrigin()], 'Rien n’était proposé : l’écriture est une saisie.');
    }

    private function proposal(): EntryProposal
    {
        // FV-2026-0001: 7 950,00 HT + 1 590,00 VAT = 9 540,00.
        return new EntryProposal(AccountingModule::Sales, new EntryDraft('VTE', new \DateTimeImmutable('2026-01-06'), 'FV-2026-0001', 'Girard, escalier chêne', [
            EntryLineDraft::debit('411000', Money::cents(954_000), '', 'C0019'),
            EntryLineDraft::credit('701000', Money::cents(795_000)),
            EntryLineDraft::credit('445710', Money::cents(159_000)),
        ]), 'FV-2026-0001');
    }

    private function gateway(AccountingBehaviour $behaviour): AccountingGateway
    {
        return new AccountingGateway(self::getContainer()->get(Ledger::class), new readonly class($behaviour) implements AccountingBehaviours {
            public function __construct(private AccountingBehaviour $behaviour)
            {
            }

            public function for(Dossier $dossier, AccountingModule $module): AccountingBehaviour
            {
                return $this->behaviour;
            }
        });
    }
}
