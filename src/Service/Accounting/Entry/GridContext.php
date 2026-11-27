<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Period;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AssistanceLevel;

/**
 * What the checks of one grid need, read once per request (JournalGrid::context()): the journal
 * and the month entered, the dossier's plan, third parties and months, the documents the journal
 * already carries in the exercise, the assistance level. Checking a hundred groups then reads the
 * database once.
 */
final class GridContext
{
    /** @var array<string, Account> by number (as a string key) */
    private array $accounts = [];

    /** @var array<string, ThirdParty> by auxiliary code, then by code */
    private array $thirdParties = [];

    /**
     * @param list<Account>                                            $accounts
     * @param list<ThirdParty>                                         $thirdParties
     * @param list<Period>                                             $periods      every month of the dossier
     * @param list<array{id: int, document: string, label: string}> $documents    the entries of the journal in the exercise
     */
    public function __construct(
        public readonly Dossier $dossier,
        public readonly Journal $journal,
        public readonly Period $period,
        public readonly AssistanceLevel $level,
        array $accounts,
        array $thirdParties,
        public readonly array $periods,
        public readonly array $documents,
    ) {
        foreach ($accounts as $account) {
            $this->accounts['n'.$account->getNumber()] = $account;
        }
        foreach ($thirdParties as $thirdParty) {
            $this->thirdParties['c'.$thirdParty->getCode()] = $thirdParty;
        }
        foreach ($thirdParties as $thirdParty) {
            if (null !== $thirdParty->getAuxiliaryCode()) {
                $this->thirdParties['c'.$thirdParty->getAuxiliaryCode()] = $thirdParty;
            }
        }
    }

    public function account(string $number): ?Account
    {
        return $this->accounts['n'.$number] ?? null;
    }

    /** By auxiliary code first (« 401SCIE »), then by the third party's own code (« F0001 »). */
    public function thirdParty(string $code): ?ThirdParty
    {
        return $this->thirdParties['c'.$code] ?? null;
    }

    public function periodAt(\DateTimeImmutable $day): ?Period
    {
        $day = $day->setTime(0, 0);
        foreach ($this->periods as $period) {
            if ($day >= $period->getStartsOn() && $day <= $period->getEndsOn()) {
                return $period;
            }
        }

        return null;
    }

    public function counterpart(): ?string
    {
        return $this->journal->getCounterpartAccount()?->getNumber();
    }
}
