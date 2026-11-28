<?php

declare(strict_types=1);

namespace App\Service\Document\Preparation;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Audit\AuditAction;
use App\Enum\Document\DocumentType;
use App\Enum\Document\SlipNature;
use App\Enum\Document\SlipPlace;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Document\DocumentRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Accounting\Template\VatRate;
use App\Service\Audit\AuditTrail;
use App\Service\Document\Documents;
use App\Service\Document\Processing\PieceParty;
use App\Service\Document\Processing\PieceProcessing;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use App\Service\Shared\Storage\DossierFiles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of a pièce's preparation (step 2.2b; design-ux.md § 3.5, « mode préparation »): in
 * a template, on an open pièce, the teacher slips errors into what the student will receive - its
 * read data, its proposed entry, or both - takes one back, fixes an account of the right proposal
 * (the generator chooses none), and makes the pièce arrive twice. The right values and the errors
 * are kept on the pièce (AnswerKey), the wrong ones written where the student reads (SlippedErrors
 * applies them); the student's screens never read the answer key. Every gesture goes to the
 * template's trail. Read inside the dossier's context; the caller flushes, except when a second
 * arrival is withdrawn (its file goes once its row is gone).
 */
final readonly class PiecePreparation
{
    /** The rates of fiscal.vat.rates, in the order suggested. */
    private const array RATES = ['normal', 'intermediate', 'reduced', 'super_reduced'];

    public function __construct(
        private ThirdPartyRepository $thirdParties,
        private AccountRepository $accounts,
        private DocumentRepository $documents,
        private Documents $door,
        private RegulatoryReader $regulatory,
        private DossierFiles $files,
        private AuditTrail $trail,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** A pièce of a template still open; whoever may change the template prepares it. */
    public static function isPreparable(Document $document): bool
    {
        return $document->getDossier()->isTemplate() && $document->getState()->isOpen();
    }

    /** The right values and the errors: the one kept, else the pièce as it stands (no error). */
    public function answerKey(Document $document): AnswerKey
    {
        $stored = $document->getAnswerKey();
        if (null !== $stored) {
            return AnswerKey::fromArray($stored);
        }
        $proposal = $document->getProposal();

        return new AnswerKey(PieceReading::of($document), null === $proposal ? null : PieceProposal::fromArray($proposal));
    }

    /** « Glisser une erreur »: null once slipped, else why not. */
    public function slip(Document $document, string $target, SlipPlace $place, string $wrong, User $actor): ?string
    {
        $this->assertPreparable($document);
        $key = $this->answerKey($document);
        $lookups = $this->lookups($document->getDossier());
        $refusal = SlippedErrors::refusal($key, $target, $place, $wrong, $lookups['accountExists'], $lookups['partyOf']);
        $nature = SlippedErrors::nature($target, $key->proposal);
        if (null !== $refusal || null === $nature) {
            return $refusal ?? 'Cette valeur ne reçoit pas d’erreur.';
        }
        $this->write($document, $key->with(new SlippedError($target, $nature, $place, $wrong)), $lookups);
        $this->trail->inDossier($document->getDossier(), AuditAction::DocumentErrorSlipped, $actor, \sprintf('Erreur glissée dans la pièce %s : %s, %s.', $this->name($document), mb_strtolower($nature->label()), mb_strtolower($place->label())), ['document' => $document->getId(), 'target' => $target, 'place' => $place->value]);

        return null;
    }

    /** « Retirer l'erreur »: the right value comes back where the student reads; null once done. */
    public function remove(Document $document, string $target, User $actor): ?string
    {
        $this->assertPreparable($document);
        $key = $this->answerKey($document);
        $error = $key->errorOn($target);
        if (null === $error) {
            return 'Aucune erreur n’est glissée sur cette valeur.';
        }
        $this->write($document, $key->without($target), $this->lookups($document->getDossier()));
        $this->trail->inDossier($document->getDossier(), AuditAction::DocumentErrorRemoved, $actor, \sprintf('Erreur retirée de la pièce %s : %s.', $this->name($document), mb_strtolower($error->nature->label())), ['document' => $document->getId(), 'target' => $target]);

        return null;
    }

    /**
     * The account of a line of the right proposal (an account number, or a third party's
     * auxiliary code); refused while an error shows in the entry - it is drawn from these lines.
     */
    public function fixAccount(Document $document, int $line, string $cell, User $actor): ?string
    {
        $this->assertPreparable($document);
        $key = $this->answerKey($document);
        if ($key->touchesProposal()) {
            return 'Retirez d’abord les erreurs glissées dans l’écriture : elles s’appuient sur ses lignes.';
        }
        $old = $key->proposal->lines[$line] ?? null;
        if (null === $key->proposal || null === $old) {
            return 'Cette ligne n’est pas dans l’écriture proposée.';
        }
        $cell = strtoupper(trim($cell));
        $dossier = $document->getDossier();
        if (1 === preg_match('/^\d+$/', $cell)) {
            if (null === $this->accounts->findOneByNumber($dossier, $cell)) {
                return 'Choisissez un compte du plan du dossier.';
            }
            $fixed = new GridLine($cell, '', $old->label, $old->debit, $old->credit, $old->due);
        } elseif ('' !== $cell && null !== $this->thirdParties->findOneByAuxiliaryCode($dossier, $cell)) {
            $fixed = new GridLine($cell, '', $old->label, $old->debit, $old->credit, $old->due);
        } else {
            return 'Choisissez un compte du plan du dossier ou le code auxiliaire d’un tiers.';
        }
        $lines = $key->proposal->lines;
        $lines[$line] = $fixed;
        $proposal = $key->proposal;
        $this->write($document, $key->withProposal(new PieceProposal($proposal->journal, $proposal->date, $proposal->document, $proposal->label, $lines, $proposal->basis)), $this->lookups($dossier));
        $this->trail->inDossier($dossier, AuditAction::DocumentProposalFixed, $actor, \sprintf('Écriture juste de la pièce %s : ligne %d sur %s.', $this->name($document), $line + 1, $cell), ['document' => $document->getId(), 'line' => $line, 'account' => $cell]);

        return null;
    }

    /** « Doublon »: the pièce arrives a second time, as it really is, without the errors slipped into the first. */
    public function duplicate(Document $document, User $actor): ?string
    {
        $this->assertPreparable($document);
        if (null !== $document->getDuplicateOf()) {
            return 'Cette pièce est déjà la seconde arrivée d’une autre.';
        }
        if ([] !== $this->documents->findDuplicatesOf($document)) {
            return 'La pièce arrive déjà deux fois.';
        }
        $second = $this->door->secondArrival($document, $actor);
        $key = $this->answerKey($document);
        $this->write($second, new AnswerKey($key->reading, $key->proposal), $this->lookups($document->getDossier()));
        $this->trail->inDossier($document->getDossier(), AuditAction::DocumentDuplicateAdded, $actor, \sprintf('Doublon glissé : la pièce %s arrive deux fois.', $this->name($document)), ['document' => $document->getId()]);

        return null;
    }

    /** The doublon taken back: its second arrival deleted, file included, while nobody processed it. Flushes. */
    public function withdrawDuplicate(Document $document, User $actor): ?string
    {
        $this->assertPreparable($document);
        $seconds = $this->documents->findDuplicatesOf($document);
        if ([] === $seconds) {
            return 'La pièce n’arrive qu’une fois.';
        }
        foreach ($seconds as $second) {
            if (!$second->getState()->isOpen()) {
                return 'La seconde arrivée est déjà comptabilisée ou écartée : elle reste.';
            }
        }
        $keys = [];
        foreach ($seconds as $second) {
            $keys[] = $second->getFileKey();
            $this->entityManager->remove($second);
        }
        $this->trail->inDossier($document->getDossier(), AuditAction::DocumentDuplicateWithdrawn, $actor, \sprintf('Doublon retiré : la pièce %s n’arrive plus qu’une fois.', $this->name($document)), ['document' => $document->getId()]);
        $this->entityManager->flush();
        $this->files->deleteKeys((int) $document->getDossier()->getId(), $keys);

        return null;
    }

    /**
     * What the screen shows: the right read data and proposal, each value an error may fall on, the
     * wrong values beside the right ones, the errors, the doublon.
     */
    public function view(Document $document): PreparationView
    {
        $key = $this->answerKey($document);
        $dossier = $document->getDossier();
        $labels = $this->labels($dossier);
        $targets = SlippedErrors::targets($key->reading, $key->proposal);
        $mark = function (string $target) use ($key, $targets, $labels): array {
            $error = $key->errorOn($target);

            return [
                'target' => \in_array($target, $targets, true) ? $target : null,
                'wrong' => null === $error ? null : $this->shown($target, $error->wrong, $key, $labels),
                'place' => $error?->place->label(),
            ];
        };

        $reading = $key->reading;
        $rows = [
            ['label' => 'Nature', 'value' => DocumentType::tryFrom($reading->type)?->label() ?? '—', 'target' => null, 'wrong' => null, 'place' => null],
            ['label' => 'Tiers', 'value' => null === $reading->thirdParty ? '—' : $this->shown(SlippedErrors::THIRD_PARTY, $reading->thirdParty, $key, $labels)] + $mark(SlippedErrors::THIRD_PARTY),
            ['label' => 'Numéro', 'value' => $reading->number ?? '—', 'target' => null, 'wrong' => null, 'place' => null],
            ['label' => 'Date', 'value' => self::day($reading->date), 'target' => null, 'wrong' => null, 'place' => null],
            ['label' => 'Échéance', 'value' => self::day($reading->dueDate), 'target' => null, 'wrong' => null, 'place' => null],
        ];
        foreach ($reading->vatLines as $index => $line) {
            $target = 'vat.'.$index.'.rate';
            $rows[] = ['label' => 'Taux de TVA', 'value' => FrenchFormat::percent($line->rate)] + $mark($target);
            $rows[] = ['label' => 'Base HT · TVA', 'value' => FrenchFormat::amount($line->base).' · '.FrenchFormat::amount($line->vat), 'target' => null, 'wrong' => null, 'place' => null];
        }
        foreach ([SlippedErrors::TOTAL_EXCLUDING_TAX => 'Total HT', SlippedErrors::TOTAL_VAT => 'Total TVA', SlippedErrors::TOTAL_INCLUDING_TAX => 'Total TTC'] as $target => $label) {
            $value = $reading->value($target);
            $rows[] = ['label' => $label, 'value' => null === $value ? '—' : FrenchFormat::amount(Money::cents((int) $value))] + $mark($target);
        }

        // What the student receives, line by line, beside the right lines: every error that shows in the entry.
        $received = SlippedErrors::apply($key, $this->lookups($dossier)['partyOf'])[1];
        $lines = [];
        foreach ($key->proposal->lines ?? [] as $index => $line) {
            $got = $received->lines[$index] ?? $line;
            $amount = $mark('line.'.$index.'.amount');
            $lines[] = [
                'index' => $index,
                'account' => $line->account,
                'accountLabel' => $labels[strtoupper($line->account)] ?? '',
                'debit' => $line->debit,
                'credit' => $line->credit,
                'accountTarget' => 'line.'.$index.'.account',
                'wrongAccount' => $got->account !== $line->account ? $got->account : null,
                'amountTarget' => $amount['target'],
                'wrongAmount' => SlippedErrors::amountOf($got) !== SlippedErrors::amountOf($line) ? FrenchFormat::amount(Money::cents(SlippedErrors::amountOf($got))) : null,
            ];
        }

        $errors = [];
        foreach ($key->errors as $error) {
            $errors[] = [
                'target' => $error->target,
                'label' => $this->targetLabel($error->target, $key),
                'nature' => $error->nature->label(),
                'right' => $this->shown($error->target, SlippedErrors::rightValue($error->target, $key->reading, $key->proposal) ?? '', $key, $labels),
                'wrong' => $this->shown($error->target, $error->wrong, $key, $labels),
                'place' => $error->place->label(),
            ];
        }

        return new PreparationView($key, $rows, $lines, $errors, $this->documents->findDuplicatesOf($document), $document->getDuplicateOf());
    }

    /**
     * The panel « Glisser une erreur » for one value: its nature, the right value, the places, the
     * three suggestions; null when no error may fall on it.
     */
    public function panel(Document $document, string $target): ?SlipPanel
    {
        $key = $this->answerKey($document);
        $nature = SlippedErrors::nature($target, $key->proposal);
        $right = SlippedErrors::rightValue($target, $key->reading, $key->proposal);
        $dossier = $document->getDossier();
        $lookups = $this->lookups($dossier);
        $labels = $this->labels($dossier);
        $line = SlippedErrors::lineOf($target);
        $fixable = null !== $line && str_ends_with($target, '.account') && isset($key->proposal->lines[$line]) && !$key->touchesProposal();
        $slippable = null !== $right && '' !== $right && \in_array($target, SlippedErrors::targets($key->reading, $key->proposal), true) && null === $key->errorOn($target) ? $nature : null;
        if (null === $slippable && !$fixable) {
            return null;
        }
        $suggestions = null === $slippable ? [] : $this->suggestions($dossier, $target, $slippable, (string) $right, $key, $lookups['parties']);
        $parties = [];
        if (SlipNature::ThirdParty === $slippable) {
            foreach ($this->partySuggestions($target, (string) $right, $lookups['parties'], \PHP_INT_MAX) as $value) {
                $parties[] = ['value' => $value, 'label' => $this->shown($target, $value, $key, $labels)];
            }
        }

        return new SlipPanel(
            $target,
            $this->targetLabel($target, $key),
            $slippable,
            null === $right || '' === $right ? '—' : $this->shown($target, $right, $key, $labels),
            null === $slippable ? [] : SlippedErrors::places($target, $key->reading, $key->proposal, $lookups['partyOf']),
            array_map(fn (string $value): array => ['value' => $value, 'label' => $this->shown($target, $value, $key, $labels)], $suggestions),
            $parties,
            $fixable ? $line : null,
            $fixable ? ($key->proposal->lines[(int) $line]->account ?? '') : '',
        );
    }

    /** @return list<Document> */
    public function duplicatesOf(Document $document): array
    {
        return $this->documents->findDuplicatesOf($document);
    }

    /**
     * @param array<string, ThirdParty> $parties
     *
     * @return list<string>
     */
    private function suggestions(Dossier $dossier, string $target, SlipNature $nature, string $right, AnswerKey $key, array $parties): array
    {
        $reading = $key->reading;

        return match ($nature) {
            SlipNature::Amount => array_map('strval', SlipSuggestions::amounts((int) $right, match ($target) {
                SlippedErrors::TOTAL_INCLUDING_TAX => $reading->totalExcludingTax?->cents,
                SlippedErrors::TOTAL_EXCLUDING_TAX => $reading->totalIncludingTax?->cents,
                default => null,
            })),
            SlipNature::VatRate => SlipSuggestions::rates($right, $this->ratesInForce($dossier)),
            SlipNature::Account => SlipSuggestions::accounts($right, array_map(static fn ($account): string => $account->getNumber(), $this->accounts->findPlan($dossier))),
            SlipNature::ThirdParty => $this->partySuggestions($target, $right, $parties),
        };
    }

    /**
     * Third parties of the same kind kept on an account, nearest name first, by their code (on a
     * Compte cell too: the right one is then the party whose auxiliary code the cell holds).
     *
     * @param array<string, ThirdParty> $parties by code
     *
     * @return list<string>
     */
    private function partySuggestions(string $target, string $right, array $parties, int $limit = SlipSuggestions::COUNT): array
    {
        $byCell = !SlippedErrors::isReadingTarget($target);
        $own = null;
        foreach ($parties as $party) {
            if (($byCell ? $party->getAuxiliaryCode() : $party->getCode()) === $right) {
                $own = $party;
            }
        }
        $names = [];
        foreach ($parties as $party) {
            if (null === PieceParty::of($party) || (null !== $own && $party->getKind() !== $own->getKind())) {
                continue;
            }
            $names[$party->getCode()] = $party->getName();
        }
        if (null !== $own) {
            $names[$own->getCode()] = $own->getName();
        }

        return SlipSuggestions::thirdParties(null === $own ? $right : $own->getCode(), $names, $limit);
    }

    /** @return list<string> the canonical VAT rates in force at the dossier's date; none when none is installed */
    private function ratesInForce(Dossier $dossier): array
    {
        $snapshot = $this->regulatory->at($dossier->getDossierDate());
        if (!$snapshot->has('fiscal.vat.rates')) {
            return [];
        }
        $rates = [];
        foreach (self::RATES as $name) {
            try {
                $rates[] = VatRate::canonical($snapshot->decimal('fiscal.vat.rates', $name));
            } catch (MissingRegulatoryValue) {
                continue;
            }
        }

        return $rates;
    }

    /**
     * What the student receives written on the pièce: the read data and the proposal with the
     * errors on, the answer key kept while there is one.
     *
     * @param array{parties: array<string, ThirdParty>, partyOf: callable(string): ?PieceParty, accountExists: callable(string): bool} $lookups
     */
    private function write(Document $document, AnswerKey $key, array $lookups): void
    {
        [$reading, $proposal] = SlippedErrors::apply($key, $lookups['partyOf']);
        $document->read(
            DocumentType::tryFrom($reading->type) ?? $document->getType(),
            null === $reading->thirdParty ? null : ($lookups['parties'][$reading->thirdParty] ?? null),
            $reading->number,
            self::date($reading->date),
            self::date($reading->dueDate),
            $reading->orderReference,
            $reading->vatLines,
            $reading->totalExcludingTax,
            $reading->totalVat,
            $reading->totalIncludingTax,
        );
        if (null !== $proposal) {
            // Made from these read data, as far as the student's screen knows: no banner says they changed.
            $document->propose((new PieceProposal($proposal->journal, $proposal->date, $proposal->document, $proposal->label, $proposal->lines, PieceProcessing::basis($document)))->toArray());
        }
        $document->keepAnswerKey([] === $key->errors ? null : $key->toArray());
    }

    /**
     * @return array{parties: array<string, ThirdParty>, partyOf: callable(string): ?PieceParty, accountExists: callable(string): bool}
     */
    private function lookups(Dossier $dossier): array
    {
        $parties = [];
        foreach ($this->thirdParties->findByDossier($dossier) as $party) {
            $parties[$party->getCode()] = $party;
        }
        $numbers = [];
        foreach ($this->accounts->findPlan($dossier) as $account) {
            $numbers[$account->getNumber()] = true;
        }

        return [
            'parties' => $parties,
            'partyOf' => static fn (string $code): ?PieceParty => isset($parties[$code]) ? PieceParty::of($parties[$code]) : null,
            'accountExists' => static fn (string $number): bool => isset($numbers[$number]),
        ];
    }

    /** @return array<string, string> account numbers' labels, third parties' names by code and by auxiliary code */
    private function labels(Dossier $dossier): array
    {
        $labels = [];
        foreach ($this->accounts->findPlan($dossier) as $account) {
            $labels[$account->getNumber()] = $account->getLabel();
        }
        foreach ($this->thirdParties->findByDossier($dossier) as $party) {
            $labels[$party->getCode()] = $party->getName();
            if (null !== $party->getAuxiliaryCode()) {
                $labels[$party->getAuxiliaryCode()] = $party->getName();
            }
        }

        return $labels;
    }

    /**
     * A value as the screen writes it: « F0001 · Scierie… », « 601000 · Achats… », « 1 234,56 », « 20 % ».
     *
     * @param array<string, string> $labels
     */
    private function shown(string $target, string $value, AnswerKey $key, array $labels): string
    {
        return match (SlippedErrors::nature($target, $key->proposal)) {
            SlipNature::Amount => FrenchFormat::amount(Money::cents((int) $value)),
            SlipNature::VatRate => null === VatRate::parse($value) ? $value : FrenchFormat::percent($value),
            SlipNature::Account, SlipNature::ThirdParty => trim($value.(isset($labels[$value]) ? ' · '.$labels[$value] : '')),
            null => $value,
        };
    }

    private function targetLabel(string $target, AnswerKey $key): string
    {
        $line = SlippedErrors::lineOf($target);
        if (null !== $line) {
            return (str_ends_with($target, '.account') ? 'Compte' : 'Montant').' · ligne '.($line + 1).' de l’écriture';
        }
        if (1 === preg_match('/^vat\.(\d)\.rate$/', $target, $match)) {
            return \count($key->reading->vatLines) > 1 ? 'Taux de TVA · ligne '.((int) $match[1] + 1) : 'Taux de TVA';
        }

        return match ($target) {
            SlippedErrors::THIRD_PARTY => 'Tiers',
            SlippedErrors::TOTAL_EXCLUDING_TAX => 'Total HT',
            SlippedErrors::TOTAL_VAT => 'Total TVA',
            SlippedErrors::TOTAL_INCLUDING_TAX => 'Total TTC',
            default => $target,
        };
    }

    private function assertPreparable(Document $document): void
    {
        if (!self::isPreparable($document)) {
            throw new \LogicException('Only an open pièce of a template is prepared.');
        }
    }

    private function name(Document $document): string
    {
        return $document->getNumber() ?? $document->getFileName();
    }

    private static function date(?string $day): ?\DateTimeImmutable
    {
        return null === $day ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $day) ?: null);
    }

    private static function day(?string $day): string
    {
        $date = self::date($day);

        return null === $date ? '—' : FrenchFormat::date($date);
    }
}
