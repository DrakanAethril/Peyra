<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Assets\FixedAsset;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\JournalType;
use App\Enum\Assets\AssetOrigin;
use App\Enum\Assets\AssetPostingKind;
use App\Enum\Assets\DepreciationMethod;
use App\Enum\Assets\DisposalKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Assets\FixedAssetRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Gateway\EntryProposal;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Assets\Depreciation\DepreciationPlan;
use App\Service\Assets\Disposal\DisposalCalculation;
use App\Service\Assets\Disposal\VatRegularisation;
use App\Service\Assets\Dotation\DotationLine;
use App\Service\Document\Processing\PieceCheck;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Document\Processing\ProposedEntryCheck;
use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * How the fixed assets reach the books (step 3.4b; design-ux.md § 3.2 « Dotations », « Cessions »):
 * never by themselves - their entries (FixedAssetEntries, from the plan) go to AccountingGateway, and
 * the dossier's behaviour decides, as for the payroll (PayrollPosting): « Proposer » shows the entry and
 * the person passes it to the brouillard; « Comptabiliser » writes the acquisition and the way out when
 * they are recorded, the dotations from their screen; « Laisser saisir » shows the journal to enter it
 * in. Nothing is proposed after the dossier's date.
 *
 * - the **acquisition** (or the production) of an asset entered in the exercise of the dossier's date,
 *   under its pièce: not proposed while an entry of that pièce stands, whoever wrote it;
 * - the **dotations** up to the dossier's date (DOT-AAAA-MM-JJ): for each asset, its plan up to that day
 *   less its plan up to the last day dotations were passed for it in the exercise - the pièces the fiche
 *   keeps (`postings`) and that still stand in the books say which; with the dérogatoire, and the
 *   impairment's movement once the dossier's date is the exercise's last day; an asset gone by then is
 *   left to its way out;
 * - the **way out** (CES-<code>): the dotation from that last day to its own, the asset out, the price,
 *   the reversals, the VAT given back.
 *
 * A fiche whose pièces have entries in the books is no longer deleted (carriesEntries). Runs inside the
 * dossier's context; the caller flushes.
 */
final readonly class FixedAssetPostings
{
    public function __construct(
        private AccountingGateway $gateway,
        private JournalRepository $journals,
        private EntryRepository $entries,
        private AccountRepository $accountRepository,
        private ExerciseRepository $exercises,
        private FixedAssetRepository $assets,
        private ProposedEntryCheck $entryCheck,
        private FixedAssetPlans $plans,
        private RegulatoryReader $regulatory,
    ) {
    }

    public function behaviour(Dossier $dossier): AccountingBehaviour
    {
        return $this->gateway->behaviourFor($dossier, AccountingModule::Assets);
    }

    /** The general journal the dotations and the ways out go to: the dossier's first « opérations diverses » one. */
    public function generalJournal(Dossier $dossier): ?Journal
    {
        return $this->journals->findByType($dossier, JournalType::General)[0] ?? null;
    }

    public function accounts(Dossier $dossier): FixedAssetAccounts
    {
        $plan = [];
        foreach ($this->accountRepository->findPlan($dossier) as $account) {
            $plan[$account->getNumber()] = $account;
        }

        return new FixedAssetAccounts(static fn (string $number): ?Account => $plan[$number] ?? null);
    }

    /** @return list<Entry> every entry of that pièce, by date: what the screens list, whoever wrote it */
    public function entries(Dossier $dossier, string $document): array
    {
        return '' === $document ? [] : $this->entries->findByDocumentReference($dossier, $document);
    }

    /** @return list<Entry> the entries of that pièce still standing - neither a reversal nor reversed */
    public function standing(Dossier $dossier, string $document): array
    {
        return array_values(array_filter(
            $this->entries($dossier, $document),
            fn (Entry $entry): bool => EntryOrigin::Reversal !== $entry->getOrigin() && null === $this->entries->findReversalOf($entry),
        ));
    }

    /** Whether the books hold an entry of one of the fiche's pièces: its acquisition, its dotations, its way out. */
    public function carriesEntries(FixedAsset $asset): bool
    {
        $documents = array_unique(array_filter([$asset->getDocument(), ...array_column($asset->getPostings(), 'document')]));
        foreach ($documents as $document) {
            if ([] !== $this->entries($asset->getDossier(), $document)) {
                return true;
            }
        }

        return false;
    }

    public function check(Dossier $dossier, PieceProposal $proposal): PieceCheck
    {
        return $this->entryCheck->check($dossier, $proposal);
    }

    // --- Acquisition ---------------------------------------------------------------------------

    public function acquisitionDocument(FixedAsset $asset): string
    {
        return '' !== $asset->getDocument() ? $asset->getDocument() : 'IMM-'.$asset->getCode();
    }

    /** The day an acquisition is entered: its invoice's for a purchase, its putting into service for a production. */
    public static function acquisitionDay(FixedAsset $asset): \DateTimeImmutable
    {
        return AssetOrigin::Production === $asset->getOrigin() ? $asset->getInServiceOn() : $asset->getAcquiredOn();
    }

    /**
     * The acquisition's entry, for an asset entered in the exercise of the dossier's date and by it;
     * null for one entered before (its value came with the opening balance) or after.
     */
    public function acquisitionProposal(Dossier $dossier, FixedAsset $asset): ?PieceProposal
    {
        $day = self::acquisitionDay($asset);
        $exercise = $this->exercises->findAt($dossier, $dossier->getDossierDate());
        if (null === $exercise || $day < $exercise->getStartsOn() || $day > $dossier->getDossierDate()) {
            return null;
        }
        $accounts = $this->accounts($dossier);
        $label = mb_substr($asset->getLabel(), 0, 250);
        if (AssetOrigin::Production === $asset->getOrigin()) {
            $journal = $this->generalJournal($dossier);

            return null === $journal ? null : FixedAssetEntries::production($journal->getCode(), $day, $this->acquisitionDocument($asset), $label, $asset->getAccount()->getNumber(), $asset->getCost(), $accounts->production($asset->getAccount()->getNumber()));
        }
        $journal = $this->journals->findByType($dossier, JournalType::Purchases)[0] ?? null;
        if (null === $journal) {
            return null;
        }
        $supplier = $asset->getSupplier();
        $auxiliary = $supplier?->getAuxiliaryCode();
        $cell = null !== $auxiliary && null !== $supplier->getAccount() ? $auxiliary : ($supplier?->getAccount()?->getNumber() ?? FixedAssetAccounts::ASSET_SUPPLIERS);
        $thirdParty = null !== $auxiliary || null === $supplier ? '' : $supplier->getCode();

        return FixedAssetEntries::acquisition($journal->getCode(), $day, $this->acquisitionDocument($asset), $label, $asset->getAccount()->getNumber(), $asset->getCost(), $asset->getVat(), $cell, $thirdParty);
    }

    /** « Passer l'écriture au brouillard »: the acquisition, unless an entry of its pièce stands. */
    public function postAcquisition(Dossier $dossier, FixedAsset $asset, User|string $actor): PieceCheck
    {
        $proposal = $this->acquisitionProposal($dossier, $asset);
        if (null === $proposal || AccountingBehaviour::LetEnter === $this->behaviour($dossier) || [] !== $this->standing($dossier, $proposal->document)) {
            throw new \LogicException('This acquisition is not posted from here.');
        }
        $check = $this->record($dossier, $proposal, $actor);
        if ($check->posted) {
            $asset->recordPosting(AssetPostingKind::Acquisition, $proposal->document, self::acquisitionDay($asset));
        }

        return $check;
    }

    // --- Dotations -----------------------------------------------------------------------------

    /** The day the dotations go up to: the dossier's date, within its exercise. */
    public function dotationExercise(Dossier $dossier): ?Exercise
    {
        return $this->exercises->findAt($dossier, $dossier->getDossierDate());
    }

    public static function dotationDocument(\DateTimeImmutable $through): string
    {
        return 'DOT-'.$through->format('Y-m-d');
    }

    /**
     * The last day dotations were passed for the asset in the exercise - by the dotations' screen or with
     * its way out (unless `withDisposal` is false) -, as the pièces the fiche keeps and that still stand
     * say; null when none was.
     */
    public function lastPassed(Dossier $dossier, FixedAsset $asset, Exercise $exercise, bool $withDisposal = true): ?\DateTimeImmutable
    {
        $last = null;
        $kinds = $withDisposal ? [AssetPostingKind::Dotation->value, AssetPostingKind::Disposal->value] : [AssetPostingKind::Dotation->value];
        foreach ($asset->getPostings() as $posting) {
            if (!\in_array($posting['kind'], $kinds, true)) {
                continue;
            }
            $on = \DateTimeImmutable::createFromFormat('!Y-m-d', $posting['on']) ?: null;
            if (null === $on || !$exercise->contains($on) || (null !== $last && $on <= $last) || [] === $this->standing($dossier, $posting['document'])) {
                continue;
            }
            $last = $on;
        }

        return $last;
    }

    /** Whether the impairment of the exercise's end was passed already. */
    private function impairmentPassed(Dossier $dossier, FixedAsset $asset, Exercise $exercise): bool
    {
        foreach ($asset->getPostings() as $posting) {
            if (AssetPostingKind::Impairment->value === $posting['kind'] && $posting['on'] === $exercise->getEndsOn()->format('Y-m-d') && [] !== $this->standing($dossier, $posting['document'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What each asset is given from the last day dotations were passed for it to `through` (the dossier's
     * date), and the impairment's movement when `through` is the exercise's last day.
     *
     * @return list<DotationLine>
     */
    public function dotationLines(Dossier $dossier, Exercise $exercise, \DateTimeImmutable $through): array
    {
        $accounts = $this->accounts($dossier);
        $lines = [];
        $dayBefore = $exercise->getStartsOn()->modify('-1 day');
        foreach ($this->assets->findByDossier($dossier) as $asset) {
            if ($asset->isDisposedBy($through) || self::acquisitionDay($asset) > $through) {
                continue;
            }
            $number = $asset->getAccount()->getNumber();
            $last = $this->lastPassed($dossier, $asset, $exercise);
            $from = null === $last ? $exercise->getStartsOn() : $last->modify('+1 day');
            $dotation = Money::zero();
            $derogatory = null;
            if (DepreciationMethod::None !== $asset->getMethod() && (null === $last || $last < $through)) {
                $now = $this->plans->plan($dossier, $asset, $through)->rowAt($exercise->getEndsOn());
                $before = null === $last ? null : $this->plans->plan($dossier, $asset, $last)->rowAt($exercise->getEndsOn());
                $dotation = ($now->dotation ?? Money::zero())->minus($before->dotation ?? Money::zero());
                if ($asset->isFiscalDeclining()) {
                    $derogatory = ($now->derogatory ?? Money::zero())->minus($before->derogatory ?? Money::zero());
                }
            }
            $impairment = null;
            if ($through->format('Y-m-d') === $exercise->getEndsOn()->format('Y-m-d') && !$this->impairmentPassed($dossier, $asset, $exercise)) {
                $balances = $asset->impairmentBalances();
                $impairment = (DepreciationPlan::impairmentAt($balances, $through) ?? Money::zero())->minus(DepreciationPlan::impairmentAt($balances, $dayBefore) ?? Money::zero());
            }
            $line = new DotationLine(
                $asset->getCode(),
                $asset->getLabel(),
                max($from, $asset->getInServiceOn()),
                $through,
                $dotation,
                $accounts->dotation($number),
                $asset->getDepreciationAccount()?->getNumber(),
                $derogatory,
                $impairment,
                $accounts->impairment($number),
                $accounts->impairmentDotation($number),
                $accounts->impairmentReprise($number),
            );
            if (!$line->isEmpty()) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @param list<DotationLine> $lines */
    public function dotationProposal(Dossier $dossier, array $lines, \DateTimeImmutable $through): ?PieceProposal
    {
        $journal = $this->generalJournal($dossier);

        return [] === $lines || null === $journal ? null : FixedAssetEntries::dotations($journal->getCode(), $through, self::dotationDocument($through), 'Dotations aux amortissements au '.FrenchFormat::date($through), $lines);
    }

    /** « Comptabiliser les dotations »: up to the dossier's date, through the gateway; the fiches keep the pièce. */
    public function postDotations(Dossier $dossier, User|string $actor): PieceCheck
    {
        $exercise = $this->dotationExercise($dossier);
        if (null === $exercise || AccountingBehaviour::LetEnter === $this->behaviour($dossier)) {
            throw new \LogicException('No dotations are posted from here.');
        }
        $through = min($dossier->getDossierDate(), $exercise->getEndsOn());
        $lines = $this->dotationLines($dossier, $exercise, $through);
        $proposal = $this->dotationProposal($dossier, $lines, $through) ?? throw new \LogicException('No dotation to post.');
        $check = $this->record($dossier, $proposal, $actor);
        if ($check->posted) {
            $codes = array_column(array_map(static fn (DotationLine $line): array => ['code' => $line->code, 'impairment' => null !== $line->impairment && !$line->impairment->isZero()], $lines), 'impairment', 'code');
            foreach ($this->assets->findByDossier($dossier) as $asset) {
                if (!\array_key_exists($asset->getCode(), $codes)) {
                    continue;
                }
                $asset->recordPosting(AssetPostingKind::Dotation, $proposal->document, $through);
                if ($codes[$asset->getCode()]) {
                    $asset->recordPosting(AssetPostingKind::Impairment, $proposal->document, $exercise->getEndsOn());
                }
            }
        }

        return $check;
    }

    // --- Way out -------------------------------------------------------------------------------

    public static function disposalDocument(FixedAsset $asset): string
    {
        return 'CES-'.$asset->getCode();
    }

    /** What the way out comes to (null for an asset still kept, or gone outside the dossier's exercises). */
    public function disposalCalculation(Dossier $dossier, FixedAsset $asset): ?DisposalCalculation
    {
        $on = $asset->getDisposedOn();
        $exercise = null === $on ? null : $this->exercises->findAt($dossier, $on);
        if (null === $on || null === $exercise) {
            return null;
        }
        $last = $this->lastPassed($dossier, $asset, $exercise, false) ?? $exercise->getStartsOn()->modify('-1 day');
        if ($last > $on) {
            $last = $on;
        }
        $before = Money::zero();
        $complement = Money::zero();
        $derogatory = Money::zero();
        if (DepreciationMethod::None !== $asset->getMethod()) {
            $planBefore = $this->plans->plan($dossier, $asset, $last);
            $before = $planBefore->cumulativeAt($last);
            $complement = $this->plans->plan($dossier, $asset)->cumulativeAt($on)->minus($before);
            $standing = $planBefore->rowAt($last)?->derogatoryBalance;
            $derogatory = null !== $standing && $standing->isPositive() ? $standing : Money::zero();
        }
        $impairment = DepreciationPlan::impairmentAt($asset->impairmentBalances(), $on->modify('-1 day')) ?? Money::zero();
        $regularisation = Money::zero();
        if (DisposalKind::Sale === $asset->getDisposalKind() && $asset->getDisposalVat()->isZero() && $asset->getVat()->isPositive()) {
            $regularisation = VatRegularisation::reversal($asset->getVat(), $asset->getAcquiredOn(), $on, $this->regularisationYears($asset, $on));
        }

        return new DisposalCalculation($asset->getCost(), $before, $complement, $impairment, $derogatory, $asset->getDisposalPrice(), $asset->getDisposalVat(), $regularisation);
    }

    /** The years of the VAT regularisation period, read on the day of the way out. */
    public function regularisationYears(FixedAsset $asset, \DateTimeImmutable $on): int
    {
        try {
            return $this->regulatory->at($on)->integer('fiscal.vat.asset_regularisation_years', VatRegularisation::isBuilding($asset->getAccount()->getNumber()) ? 'building' : 'movable');
        } catch (MissingRegulatoryValue) {
            return 0;
        }
    }

    /** The way out's entry, once the dossier's date reaches its day. */
    public function disposalProposal(Dossier $dossier, FixedAsset $asset): ?PieceProposal
    {
        $on = $asset->getDisposedOn();
        $calculation = $this->disposalCalculation($dossier, $asset);
        $journal = $this->generalJournal($dossier);
        if (null === $on || null === $calculation || null === $journal || $on > $dossier->getDossierDate()) {
            return null;
        }
        $accounts = $this->accounts($dossier);
        $number = $asset->getAccount()->getNumber();
        $buyer = $asset->getBuyer();
        $auxiliary = $buyer?->getAuxiliaryCode();
        $cell = null !== $auxiliary && null !== $buyer->getAccount() ? $auxiliary : ($buyer?->getAccount()?->getNumber() ?? FixedAssetAccounts::RECEIVABLE);
        $thirdParty = null !== $auxiliary || null === $buyer ? '' : $buyer->getCode();
        $label = mb_substr(($asset->getDisposalKind() ?? DisposalKind::Sale)->label().' '.$asset->getCode().' '.$asset->getLabel(), 0, 250);

        return FixedAssetEntries::disposal($journal->getCode(), $on, self::disposalDocument($asset), $label, $calculation, [
            'asset' => $number,
            'depreciation' => $asset->getDepreciationAccount()?->getNumber(),
            'dotation' => $accounts->dotation($number),
            'impairment' => $accounts->impairment($number),
            'impairmentReprise' => $accounts->impairmentReprise($number),
            'bookValueOut' => $accounts->bookValueOut(),
            'salePrice' => $accounts->salePrice(),
        ], $cell, $thirdParty);
    }

    /** « Passer l'écriture au brouillard »: the way out, unless an entry of its pièce stands. */
    public function postDisposal(Dossier $dossier, FixedAsset $asset, User|string $actor): PieceCheck
    {
        $proposal = $this->disposalProposal($dossier, $asset);
        $on = $asset->getDisposedOn();
        if (null === $proposal || null === $on || AccountingBehaviour::LetEnter === $this->behaviour($dossier) || [] !== $this->standing($dossier, $proposal->document)) {
            throw new \LogicException('This way out is not posted from here.');
        }
        $check = $this->record($dossier, $proposal, $actor);
        if ($check->posted) {
            $asset->recordPosting(AssetPostingKind::Disposal, $proposal->document, $on);
        }

        return $check;
    }

    /**
     * Under « Comptabiliser », what a gesture on the fiche asks for is written at once when the grid's
     * checks let it (the acquisition at its creation, the way out when it is recorded); else it waits on
     * the screen, as under « Proposer ».
     */
    public function postAtOnce(Dossier $dossier, FixedAsset $asset, AssetPostingKind $kind, User|string $actor): void
    {
        if (AccountingBehaviour::Post !== $this->behaviour($dossier)) {
            return;
        }
        $proposal = AssetPostingKind::Disposal === $kind ? $this->disposalProposal($dossier, $asset) : $this->acquisitionProposal($dossier, $asset);
        if (null === $proposal || [] !== $this->standing($dossier, $proposal->document)) {
            return;
        }
        // Refused by the grid's checks, it waits on the screen, which says why.
        AssetPostingKind::Disposal === $kind ? $this->postDisposal($dossier, $asset, $actor) : $this->postAcquisition($dossier, $asset, $actor);
    }

    private function record(Dossier $dossier, PieceProposal $proposal, User|string $actor): PieceCheck
    {
        $check = $this->check($dossier, $proposal);
        if (!$check->isWritable() || null === $check->grid) {
            return $check;
        }
        try {
            $this->gateway->record($dossier, new EntryProposal(AccountingModule::Assets, $check->grid->draft, $proposal->document), $actor);
        } catch (EntryRefused $refused) {
            $check->errors = [...$check->errors, ...$refused->errors];

            return $check;
        }
        $check->posted = true;

        return $check;
    }
}
