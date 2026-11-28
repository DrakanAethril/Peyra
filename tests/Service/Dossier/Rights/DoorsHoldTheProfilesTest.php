<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Rights;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Entity\Audit\AuditEvent;
use App\Entity\Dossier\Dossier;
use App\Enum\Audit\AuditAction;
use App\Enum\Audit\AuditActorRole;
use App\Enum\Dossier\DossierProfile;
use App\Repository\Dossier\DossierMemberRepository;
use App\Service\Accounting\Consultation\Brouillard;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Accounting\Lettering\Lettering;
use App\Service\Accounting\Lettering\LetteringRefused;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Rights\DossierProfiles;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsGroupDossier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The doors hold the profiles themselves (lot 1, step 1.7), whatever screen calls them: the
 * Ledger refuses a draft, a change or a deletion where the profile does not enter, a validation or
 * a reversal where it does not validate; Lettering refuses a key whose area it does not enter; the
 * brouillard holds back what the person may not validate. Each line of the dossier's trail says
 * who acted, as what, when in real time and at the dossier's date.
 */
final class DoorsHoldTheProfilesTest extends KernelTestCase
{
    use BuildsGroupDossier;

    public function testTheLedgerAsksTheProfile(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();
        $ledger = self::getContainer()->get(Ledger::class);

        $refusal = $this->refusal(fn () => $ledger->draft($dossier, $this->sale(), $people['ines']));
        self::assertSame(['journal' => 'Votre profil “Comptable fournisseurs” ne permet pas de saisir dans le journal de ventes.'], $refusal);

        $payment = $ledger->draft($dossier, $this->payment(), $people['ines']);
        self::assertFalse($payment->isValidated(), 'She enters the payments.');
        $refusal = $this->refusal(fn () => $ledger->validate([$payment], $people['ines']));
        self::assertSame(['entry.'.$payment->getId() => 'Votre profil “Comptable fournisseurs” ne permet pas de valider le journal de banque.'], $refusal);

        $purchase = $ledger->draft($dossier, $this->purchase(), $people['ines']);
        $refusal = $this->refusal(fn () => $ledger->update($purchase, $this->sale(), $people['ines']));
        self::assertSame(['journal' => 'Votre profil “Comptable fournisseurs” ne permet pas de saisir dans le journal de ventes.'], $refusal, 'A draft does not move to a journal she does not enter.');
        $ledger->validate([$purchase], $people['ines']);
        self::assertSame('ACH-2026-00001', $purchase->getNumberLabel(), 'She validates her own journal.');

        // chloe has no profile: she validates the bank; ines may not reverse it.
        $ledger->validate([$payment], $people['chloe']);
        $refusal = $this->refusal(fn () => $ledger->reverse($payment, new \DateTimeImmutable('2026-03-15'), $people['ines']));
        self::assertSame(['journal' => 'Votre profil “Comptable fournisseurs” ne permet pas de valider le journal de banque.'], $refusal);
        // A command signs with its name and is never narrowed.
        $ledger->reverse($payment, new \DateTimeImmutable('2026-03-15'), 'test');

        $trail = $this->trail($dossier, AuditAction::EntryValidated);
        self::assertCount(2, $trail);
        [$byInes, $byChloe] = $trail;
        self::assertSame(['Ines', AuditActorRole::Student, DossierProfile::SupplierAccountant, '2026-03-15'], [$byInes->getActorName(), $byInes->getActorRole(), $byInes->getActorProfile(), $byInes->getDossierDate()?->format('Y-m-d')]);
        self::assertSame(['Chloe', AuditActorRole::Student, null], [$byChloe->getActorName(), $byChloe->getActorRole(), $byChloe->getActorProfile()]);
        self::assertSame($dossier->getId(), $byChloe->getDossierId());
        $reversal = $this->trail($dossier, AuditAction::EntryReversed)[0];
        self::assertSame(['test', null], [$reversal->getActorName(), $reversal->getActorRole()]);
    }

    public function testDiscardNeedsToEnterTheJournal(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();
        $ledger = self::getContainer()->get(Ledger::class);
        $payment = $ledger->draft($dossier, $this->payment(), $people['chloe']);

        // theo, « Comptable clients », enters the bank too: he may delete a draft there.
        $ledger->discard($payment, $people['theo']);
        $sale = $ledger->draft($dossier, $this->sale(), $people['theo']);
        self::assertSame(['journal' => 'Votre profil “Comptable fournisseurs” ne permet pas de saisir dans le journal de ventes.'], $this->refusal(fn () => $ledger->discard($sale, $people['ines'])));
    }

    public function testLetteringAsksTheAreaOfTheKey(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();
        $ledger = self::getContainer()->get(Ledger::class);
        $invoice = $ledger->draft($dossier, $this->sale(), 'test');
        $receipt = $ledger->draft($dossier, new EntryDraft('BQ', new \DateTimeImmutable('2026-03-10'), 'VIR-GIRA', 'Règlement Girard', [
            EntryLineDraft::debit('512000', Money::euros('1200.00')),
            EntryLineDraft::credit('411000', Money::euros('1200.00'), '', 'C0019'),
        ]), 'test');
        $ledger->validate([$invoice, $receipt], 'test');
        $ids = [$this->customerLine($invoice)->getId() ?? 0, $this->customerLine($receipt)->getId() ?? 0];
        $lettering = self::getContainer()->get(Lettering::class);

        try {
            $lettering->letter($dossier, $ids, $people['ines']);
            self::fail('A supplier accountant does not letter a customer.');
        } catch (LetteringRefused $refused) {
            self::assertSame('Votre profil “Comptable fournisseurs” ne permet pas de lettrer les comptes des clients.', $refused->getMessage());
        }
        $result = $lettering->letter($dossier, $ids, $people['durand']);
        self::assertSame('A', $result->letter);
        $line = $this->trail($dossier, AuditAction::LinesLettered)[0];
        self::assertSame(['Durand', AuditActorRole::Teacher, null], [$line->getActorName(), $line->getActorRole(), $line->getActorProfile()], 'A teacher’s gesture is signed with their name, as a teacher.');
    }

    public function testTheBrouillardHoldsBackWhatTheProfileDoesNotValidate(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();
        $ledger = self::getContainer()->get(Ledger::class);
        $purchase = $ledger->draft($dossier, $this->purchase(), $people['ines']);
        $payment = $ledger->draft($dossier, $this->payment(), $people['ines']);
        $brouillard = self::getContainer()->get(Brouillard::class);

        $rows = [];
        foreach ($brouillard->rows($dossier, null, null, $people['ines']) as $row) {
            $rows[$row->entry->getDocument()] = [$row->isSelectable(), $row->reason()];
        }
        self::assertSame([true, null], $rows['FA-0001']);
        self::assertSame([false, 'Votre profil “Comptable fournisseurs” ne permet pas de valider le journal de banque.'], $rows['VIR-SCIE']);

        $outcome = $brouillard->validate($dossier, [(int) $purchase->getId(), (int) $payment->getId()], $people['ines']);
        self::assertCount(1, $outcome->validated);
        self::assertSame(1, $outcome->held);
        self::assertFalse($payment->isValidated());
    }

    public function testATeacherGivesTheProfilesAndTheTrailKeepsIt(): void
    {
        self::bootKernel();
        [$dossier, $people] = $this->groupDossier();
        $members = self::getContainer()->get(DossierMemberRepository::class);
        $chloe = $members->findOneFor($dossier, $people['chloe']);
        $ines = $members->findOneFor($dossier, $people['ines']);
        self::assertNotNull($chloe);
        self::assertNotNull($ines);

        $changed = self::getContainer()->get(DossierProfiles::class)->assign($dossier, [
            (int) $chloe->getId() => DossierProfile::ChiefAccountant,
            (int) $ines->getId() => DossierProfile::SupplierAccountant,
        ], $people['durand']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame(1, $changed, 'Ines keeps hers: only Chloé changed.');
        self::assertSame(DossierProfile::ChiefAccountant, $chloe->getProfile());
        $line = $this->trail($dossier, AuditAction::MemberProfileChanged)[0];
        self::assertSame('Profil de Chloe : chef comptable (auparavant : sans profil)', $line->getSummary());
        self::assertSame(AuditActorRole::Teacher, $line->getActorRole());
    }

    public function testNoGestureOfADossierEscapesItsTrail(): void
    {
        self::bootKernel();
        $this->expectExceptionMessage('A gesture in a dossier is recorded by AuditTrail::inDossier().');
        self::getContainer()->get(AuditTrail::class)->record(AuditAction::DossierCompanyChanged, 'test', 'dossier', '12', 'Fiche société modifiée');
    }

    /** @return array<string, string> what the Ledger refused, by key */
    private function refusal(callable $gesture): array
    {
        try {
            $gesture();
        } catch (EntryRefused $refused) {
            return $refused->errors;
        }
        self::fail('The Ledger was expected to refuse.');
    }

    /** @return list<AuditEvent> the dossier's lines of one gesture, oldest first */
    private function trail(Dossier $dossier, AuditAction $action): array
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditEvent::class)->findBy(['dossierId' => $dossier->getId(), 'action' => $action], ['id' => 'ASC']);
    }

    private function customerLine(Entry $entry): EntryLine
    {
        return array_values(array_filter($entry->getLines(), static fn (EntryLine $line): bool => '411000' === $line->getAccount()->getNumber()))[0];
    }

    private function purchase(): EntryDraft
    {
        return new EntryDraft('ACH', new \DateTimeImmutable('2026-03-02'), 'FA-0001', 'Facture Scierie', [
            EntryLineDraft::debit('607000', Money::euros('1000.00')),
            EntryLineDraft::debit('445660', Money::euros('200.00')),
            EntryLineDraft::credit('401000', Money::euros('1200.00'), '', 'F0001'),
        ]);
    }

    private function sale(): EntryDraft
    {
        return new EntryDraft('VTE', new \DateTimeImmutable('2026-03-03'), 'FV-0001', 'Facture Girard', [
            EntryLineDraft::debit('411000', Money::euros('1200.00'), '', 'C0019'),
            EntryLineDraft::credit('706000', Money::euros('1000.00')),
            EntryLineDraft::credit('445710', Money::euros('200.00')),
        ]);
    }

    private function payment(): EntryDraft
    {
        return new EntryDraft('BQ', new \DateTimeImmutable('2026-03-05'), 'VIR-SCIE', 'Règlement Scierie', [
            EntryLineDraft::debit('401000', Money::euros('1200.00'), '', 'F0001'),
            EntryLineDraft::credit('512000', Money::euros('1200.00')),
        ]);
    }
}
