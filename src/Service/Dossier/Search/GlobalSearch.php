<?php

declare(strict_types=1);

namespace App\Service\Dossier\Search;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Entry;
use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Document\DocumentRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Chart\AccountSearch;
use App\Service\Accounting\Consultation\ConsultationWindow;
use App\Service\Accounting\Consultation\EntryNumber;
use App\Service\Accounting\Consultation\EntrySearch;
use App\Service\Accounting\Consultation\EntrySearchCriteria;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Accounting\Ledger\Totals;
use App\Service\Shared\AmountParseError;
use App\Service\Shared\AmountParser;
use App\Service\Shared\FrenchFormat;
use App\Service\ThirdParty\ThirdPartySearch;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The global search of a dossier (Ctrl K, design-ux.md § 2.1), read inside its context and never
 * outside it: on an empty field, the screens opened last (RecentScreens), completed up to five by
 * the first screens of the accounting; otherwise, by group - the catalogue's screens (SearchRanking), the accounts
 * (number's start or label's words, as F4), the third parties (code, auxiliary code, name), the
 * pièces (their read number's start, their third party's name, their total's exact amount), the
 * entries (a validated entry's number, a document's start, a line's exact amount, over every
 * exercise of the dossier), the actions. An account or a third party opens its consultation, an
 * entry its fiche, both with the way back to the screen the search was made from (`back`); a pièce
 * opens its sheet.
 */
final readonly class GlobalSearch
{
    public const int SCREENS = 6;
    public const int ACCOUNTS = 5;
    public const int THIRD_PARTIES = 5;
    public const int DOCUMENTS = 5;
    public const int ENTRIES = 5;
    public const int ACTIONS = 4;

    /** Accounts or third parties read before the best are kept: F4's rule finds, the ranking orders. */
    private const int CANDIDATES = 200;

    public function __construct(
        private SearchScreens $catalogue,
        private RecentScreens $recent,
        private AccountRepository $accounts,
        private ThirdPartyRepository $thirdParties,
        private ExerciseRepository $exercises,
        private EntrySearch $entries,
        private DocumentRepository $documents,
        private LedgerReader $ledger,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param ?string $back a path of the dossier (ReturnPath::of), given to what the lines open
     *
     * @return list<SearchSection>
     */
    public function search(Dossier $dossier, string $query, bool $canEdit, bool $guidedOpen, ?string $back): array
    {
        $query = trim($query);
        $screens = $this->catalogue->screens($dossier, $canEdit, $guidedOpen);
        if ('' === $query) {
            return $this->empty($dossier, $screens);
        }

        $sections = [
            self::section(SearchGroup::Screens, \array_slice(self::ranked($screens, $query), 0, self::SCREENS)),
            self::section(SearchGroup::Accounts, $this->accounts($dossier, $query, $back)),
            self::section(SearchGroup::ThirdParties, $this->thirdParties($dossier, $query, $back)),
            self::section(SearchGroup::Documents, $this->documents($dossier, $query)),
            self::section(SearchGroup::Entries, $this->entries($dossier, $query, $back)),
            self::section(SearchGroup::Actions, \array_slice(self::ranked($this->catalogue->actions($dossier, $canEdit), $query), 0, self::ACTIONS)),
        ];

        return array_values(array_filter($sections));
    }

    /**
     * @param list<SearchItem> $screens
     *
     * @return list<SearchSection>
     */
    private function empty(Dossier $dossier, array $screens): array
    {
        $byKey = [];
        foreach ($screens as $screen) {
            $byKey[$screen->key] ??= $screen;
        }
        $recent = [];
        foreach ($this->recent->keys((int) $dossier->getId()) as $key) {
            if (isset($byKey[$key])) {
                $recent[] = $byKey[$key]->in(SearchGroup::Recent);
            }
        }
        $shown = array_map(static fn (SearchItem $item): string => $item->key, $recent);
        $first = [];
        foreach (SearchScreens::FIRST_SCREENS as $key) {
            if (isset($byKey[$key]) && !\in_array($key, $shown, true) && \count($recent) + \count($first) < RecentScreens::KEPT) {
                $first[] = $byKey[$key];
            }
        }

        return array_values(array_filter([self::section(SearchGroup::Recent, $recent), self::section(SearchGroup::Screens, $first)]));
    }

    /**
     * The lines that answer, the best first, the catalogue's order between equals.
     *
     * @param list<SearchItem> $items
     *
     * @return list<SearchItem>
     */
    private static function ranked(array $items, string $query): array
    {
        $scored = [];
        foreach ($items as $index => $item) {
            $rank = SearchRanking::rank($query, $item->label, $item->terms, $item->code);
            if (null !== $rank) {
                $marked = null === $item->code || $rank > SearchRanking::CODE_START ? $item : $item->marked(mb_strlen(AccountSearch::fold($query)));
                $scored[] = [$rank, $index, $marked];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($scored, 2);
    }

    /**
     * The best of what F4's rule found - a word's start before a word merely held (« ach »: the
     * purchases before « rattachées ») -, the rule's order between equals.
     *
     * @template T
     *
     * @param list<T>             $found
     * @param callable(T): ?int   $rank
     *
     * @return list<T>
     */
    private static function best(array $found, callable $rank, int $limit): array
    {
        $scored = [];
        foreach ($found as $index => $item) {
            $scored[] = [$rank($item) ?? SearchRanking::HELD + 1, $index, $item];
        }
        usort($scored, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column(\array_slice($scored, 0, $limit), 2);
    }

    /** @return list<SearchItem> */
    private function accounts(Dossier $dossier, string $query, ?string $back): array
    {
        $found = self::best(
            AccountSearch::filter($this->accounts->findPlan($dossier), static fn (Account $a): array => ['number' => $a->getNumber(), 'label' => $a->getLabel()], $query, self::CANDIDATES),
            static fn (Account $a): ?int => SearchRanking::rank($query, $a->getLabel(), '', $a->getNumber()),
            self::ACCOUNTS,
        );
        if ([] === $found) {
            return [];
        }
        $today = $dossier->getDossierDate();
        $balances = $this->ledger->accountTotals($dossier, $this->ledger->exerciseStart($dossier, $today), $today, EntryScope::WithDrafts);
        $digits = (string) preg_replace('/\s+/u', '', $query);
        $mark = 1 === preg_match('/^\d+$/', $digits) ? \strlen($digits) : 0;

        return array_map(fn (Account $account): SearchItem => new SearchItem(
            SearchGroup::Accounts,
            $account->getLabel(),
            $this->consultation($dossier, $account->getNumber(), $back),
            code: $account->getNumber(),
            aside: self::balance($balances[(int) $account->getId()] ?? null),
            codeMark: $mark,
        ), $found);
    }

    /** @return list<SearchItem> */
    private function thirdParties(Dossier $dossier, string $query, ?string $back): array
    {
        $found = self::best(
            ThirdPartySearch::filter($this->thirdParties->findByDossier($dossier), static fn (ThirdParty $t): array => ['code' => $t->getCode(), 'auxiliary' => $t->getAuxiliaryCode(), 'name' => $t->getName()], $query, self::CANDIDATES),
            static fn (ThirdParty $t): ?int => SearchRanking::rank($query, $t->getName(), $t->getCode(), $t->getAuxiliaryCode() ?? $t->getCode()),
            self::THIRD_PARTIES,
        );
        if ([] === $found) {
            return [];
        }
        $today = $dossier->getDossierDate();
        $owed = $this->ledger->thirdPartyTotals($dossier, $this->ledger->exerciseStart($dossier, $today), $today, EntryScope::WithDrafts);

        $typed = mb_strtoupper((string) preg_replace('/\s+/u', '', $query));

        return array_map(function (ThirdParty $thirdParty) use ($dossier, $back, $owed, $typed): SearchItem {
            $code = $thirdParty->getAuxiliaryCode() ?? $thirdParty->getCode();
            $account = $thirdParty->getAccount()?->getNumber();

            return new SearchItem(
                SearchGroup::ThirdParties,
                $thirdParty->getName(),
                $this->consultation($dossier, $code, $back),
                $thirdParty->getKind()->label().(null === $account ? '' : ' · '.$account),
                $code,
                self::balance($owed[(int) $thirdParty->getId()] ?? null),
                codeMark: str_starts_with($code, $typed) ? \strlen($typed) : 0,
            );
        }, $found);
    }

    /**
     * The pièces whose read number starts with what is typed (case aside), whose third party's name
     * holds its words, or whose total including tax is the amount typed; the latest arrived first.
     *
     * @return list<SearchItem>
     */
    private function documents(Dossier $dossier, string $query): array
    {
        $amount = null;
        if (1 === preg_match('/^[\d\s.,−+-]+$/u', $query) && 1 === preg_match('/\d/', $query)) {
            try {
                $amount = AmountParser::parse($query)->abs();
            } catch (AmountParseError) {
            }
        }
        $typed = mb_strtoupper((string) preg_replace('/\s+/u', '', $query));
        $words = array_values(array_filter(explode(' ', AccountSearch::fold($query))));
        $found = [];
        foreach ($this->documents->findByDossier($dossier) as $document) {
            $number = mb_strtoupper($document->getNumber() ?? '');
            $name = AccountSearch::fold($document->getThirdParty()?->getName() ?? '');
            $byNumber = '' !== $number && '' !== $typed && str_starts_with($number, $typed);
            $byName = '' !== $name && [] !== $words && [] === array_filter($words, static fn (string $word): bool => !str_contains(' '.$name, ' '.$word));
            $byAmount = null !== $amount && null !== $document->getTotalIncludingTax() && $document->getTotalIncludingTax()->equals($amount);
            if ($byNumber || $byName || $byAmount) {
                $found[] = $document;
            }
            if (\count($found) >= self::DOCUMENTS) {
                break;
            }
        }

        return array_map(fn (Document $document): SearchItem => new SearchItem(
            SearchGroup::Documents,
            $document->getThirdParty()?->getName() ?? $document->getFileName(),
            $this->urls->generate('app_document', ['dossier' => $dossier->getId(), 'id' => $document->getId()]),
            $document->getType()->label().' · '.$document->getState()->label().(null === $document->getDocumentDate() ? '' : ' · '.$document->getDocumentDate()->format('d/m/Y')),
            $document->getNumber(),
            null === $document->getTotalIncludingTax() ? null : FrenchFormat::amount($document->getTotalIncludingTax()),
            codeMark: '' !== $typed && str_starts_with(mb_strtoupper($document->getNumber() ?? ''), $typed) ? mb_strlen($typed) : 0,
        ), $found);
    }

    /** @return list<SearchItem> */
    private function entries(Dossier $dossier, string $query, ?string $back): array
    {
        $exercises = $this->exercises->findByDossier($dossier);
        if ([] === $exercises) {
            return [];
        }
        $starts = array_map(static fn ($exercise): \DateTimeImmutable => $exercise->getStartsOn(), $exercises);
        $ends = array_map(static fn ($exercise): \DateTimeImmutable => $exercise->getEndsOn(), $exercises);
        $window = ConsultationWindow::of(min($starts), max($ends));

        $searches = [];
        $number = EntryNumber::parse($query);
        if (null !== $number) {
            $criteria = new EntrySearchCriteria($window);
            $criteria->number = $number;
            $searches[] = $criteria;
        } elseif (1 === preg_match('/^\S{2,40}$/u', $query) && 1 !== preg_match('/^[\d\s.,−-]+$/u', $query)) {
            $criteria = new EntrySearchCriteria($window);
            $criteria->document = $query;
            $searches[] = $criteria;
        }
        if (1 === preg_match('/^[\d\s.,−+-]+$/u', $query) && 1 === preg_match('/\d/', $query)) {
            try {
                $criteria = new EntrySearchCriteria($window);
                $criteria->amount = AmountParser::parse($query)->abs();
                $searches[] = $criteria;
            } catch (AmountParseError) {
            }
        }

        $found = [];
        foreach ($searches as $criteria) {
            foreach ($this->entries->first($criteria, self::ENTRIES) as $entry) {
                $found[(int) $entry->getId()] ??= $entry;
            }
        }

        return array_map(fn (Entry $entry): SearchItem => new SearchItem(
            SearchGroup::Entries,
            str_contains(mb_strtolower($entry->getLabel()), mb_strtolower($entry->getDocument())) ? $entry->getLabel() : $entry->getDocument().' · '.$entry->getLabel(),
            $this->entryHref($dossier, $entry, $back),
            \sprintf('%s · %s', $entry->getNumberLabel() ?? 'Brouillard', $entry->getEntryDate()->format('d/m/Y')),
            $entry->getJournal()->getCode(),
            FrenchFormat::amount($entry->totalDebit()),
        ), \array_slice(array_values($found), 0, self::ENTRIES));
    }

    private function consultation(Dossier $dossier, string $account, ?string $back): string
    {
        return $this->urls->generate('app_accounting_consultation', ['dossier' => $dossier->getId(), 'account' => $account] + (null === $back ? [] : ['return' => $back]));
    }

    private function entryHref(Dossier $dossier, Entry $entry, ?string $back): string
    {
        return $this->urls->generate('app_accounting_entry_show', ['dossier' => $dossier->getId(), 'id' => $entry->getId()] + (null === $back ? [] : ['return' => $back]));
    }

    /** @param list<SearchItem> $items */
    private static function section(SearchGroup $group, array $items): ?SearchSection
    {
        return [] === $items ? null : new SearchSection($group, $items);
    }

    /** « 3 248,40 C », or nothing for what nothing moved. */
    private static function balance(?Totals $totals): ?string
    {
        return null === $totals || $totals->isZero() ? null : FrenchFormat::balance($totals->balance());
    }
}
