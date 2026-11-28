<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryLine;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryLineRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Shared\Money;

/**
 * The readings of Comptabilité › Consultation › Lettrage (lot 1, step 1.6): the accounts and third
 * parties that still have lines to letter, and the face-à-face of one. Every figure is a sum of
 * lines read here (balances are never stored). Runs in the dossier's context.
 */
final readonly class LetteringBoard
{
    public function __construct(
        private EntryLineRepository $lines,
        private AccountRepository $accounts,
        private ThirdPartyRepository $thirdParties,
    ) {
    }

    /**
     * What is typed, read as a lettering account: a lettrable general account, a third party's code
     * or auxiliary code - on its own collective, or on `on` (« 419100 ») -; null with the sentence
     * of the refusal otherwise.
     *
     * @return array{0: ?LetteringKey, 1: ?string}
     */
    public function key(Dossier $dossier, string $typed, string $on = ''): array
    {
        $code = mb_strtoupper(trim($typed));
        if (1 === preg_match('/^\d+$/', $code)) {
            $account = $this->accounts->findOneByNumber($dossier, $code);
            if (null === $account) {
                return [null, \sprintf('Ni compte ni tiers ne répond à %s. F4 pour chercher.', $code)];
            }
            if (!$account->isLettrable()) {
                return [null, \sprintf('Le compte %s n’est pas lettrable.', $code)];
            }
            if ($account->isCollective()) {
                return [null, \sprintf('Le compte %s est collectif : on lettre les lignes d’un tiers. Choisissez-le dans la liste.', $code)];
            }

            return [LetteringKey::of($account, null), null];
        }

        $thirdParty = $this->thirdParties->findOneByAuxiliaryCode($dossier, $code) ?? $this->thirdParties->findOneByCode($dossier, $code);
        if (null === $thirdParty) {
            return [null, \sprintf('Ni compte ni tiers ne répond à %s. F4 pour chercher.', $code)];
        }
        $account = '' !== $on ? $this->accounts->findOneByNumber($dossier, $on) : $thirdParty->getAccount();
        if (null === $account) {
            return [null, \sprintf('Le tiers %s n’est tenu sur aucun compte : il n’a rien à lettrer.', $thirdParty->getCode())];
        }
        if (!$account->isLettrable()) {
            return [null, \sprintf('Le compte %s du tiers %s n’est pas lettrable.', $account->getNumber(), $thirdParty->getCode())];
        }

        return [LetteringKey::of($account, $thirdParty), null];
    }

    /**
     * The accounts (third parties on a collective) with validated lines still unlettered or in a
     * partial group, by account number then third party code; narrowed to one account when asked.
     *
     * @return list<LetteringRow>
     */
    public function rows(?Account $account = null): array
    {
        /** @var array<string, array{key: LetteringKey, lines: int, partial: array<string, true>, debit: Money, credit: Money}> $rows */
        $rows = [];
        foreach ($this->lines->findValidatedOnLettrableAccounts() as $line) {
            if (null !== $account && $line->getAccount()->getId() !== $account->getId()) {
                continue;
            }
            $letter = $line->getLetter();
            if (null !== $letter && !LetterSequence::isPartial($letter)) {
                continue;
            }
            $key = LetteringKey::ofLine($line);
            $id = $key->account->getId().'-'.($key->thirdParty?->getId() ?? 0);
            $rows[$id] ??= ['key' => $key, 'lines' => 0, 'partial' => [], 'debit' => Money::zero(), 'credit' => Money::zero()];
            ++$rows[$id]['lines'];
            if (null !== $letter) {
                $rows[$id]['partial'][$letter] = true;
            }
            $rows[$id]['debit'] = $rows[$id]['debit']->plus($line->getDebit());
            $rows[$id]['credit'] = $rows[$id]['credit']->plus($line->getCredit());
        }

        $list = array_map(static fn (array $row): LetteringRow => new LetteringRow($row['key'], $row['lines'], \count($row['partial']), $row['debit'], $row['credit']), array_values($rows));
        usort($list, static fn (LetteringRow $a, LetteringRow $b): int => [$a->key->account->getNumber(), $a->key->code()] <=> [$b->key->account->getNumber(), $b->key->code()]);

        return $list;
    }

    /** The face-à-face of an account: open lines, or every validated line with `all`. */
    public function view(Dossier $dossier, LetteringKey $key, bool $all): LetteringView
    {
        $lines = $this->lines->findOnAccount($key->account, $key->thirdParty, true);
        $balance = Money::zero();
        $openDebit = Money::zero();
        $openCredit = Money::zero();
        $openLines = 0;
        $letters = [];
        $partial = [];
        $debits = [];
        $credits = [];
        foreach ($lines as $line) {
            $balance = $balance->plus($line->getDebit())->minus($line->getCredit());
            $letter = $line->getLetter();
            if (null !== $letter) {
                $letters[mb_strtoupper($letter)] = true;
            }
            $open = null === $letter || LetterSequence::isPartial($letter);
            if ($open) {
                ++$openLines;
                $openDebit = $openDebit->plus($line->getDebit());
                $openCredit = $openCredit->plus($line->getCredit());
                if (null !== $letter) {
                    $partial[$letter] = true;
                }
            }
            if ($open || $all) {
                if ($line->getDebit()->isPositive()) {
                    $debits[] = $line;
                } else {
                    $credits[] = $line;
                }
            }
        }

        return new LetteringView(
            $key,
            array_keys($letters),
            $debits,
            $credits,
            $balance,
            $openDebit,
            $openCredit,
            $openLines,
            \count($letters) - \count($partial),
            \count($partial),
            $this->lines->countDraftsOnAccount($key->account, $key->thirdParty),
            null === $key->thirdParty ? [] : $this->accountsOf($key->thirdParty),
        );
    }

    /**
     * The lettrable accounts a third party's validated lines sit on, its own first.
     *
     * @return list<Account>
     */
    private function accountsOf(ThirdParty $thirdParty): array
    {
        $accounts = [];
        $own = $thirdParty->getAccount();
        if (null !== $own && $own->isLettrable()) {
            $accounts[(int) $own->getId()] = $own;
        }
        foreach ($this->lines->lettrableAccountsOf($thirdParty) as $account) {
            $accounts[(int) $account->getId()] ??= $account;
        }

        return array_values($accounts);
    }

    /**
     * The automatic lettering's groups with the letter each would take, in order after the
     * letters the account used - what the preview shows and the face-à-face marks « Proposé A ».
     *
     * @param list<list<EntryLine>> $groups
     * @param list<string>          $used
     *
     * @return list<array{letter: string, lines: list<EntryLine>}>
     */
    public static function lettered(array $groups, array $used): array
    {
        $lettered = [];
        foreach ($groups as $group) {
            $letter = LetterSequence::next($used);
            $used[] = $letter;
            $lettered[] = ['letter' => $letter, 'lines' => $group];
        }

        return $lettered;
    }
}
