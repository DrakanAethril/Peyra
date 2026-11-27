<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Repository\Accounting\AccountRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Accounting\Ledger\Totals;

/**
 * The consultation of an account or a third party (lot 1, step 1.5; design-ux.md § 3.2): finds
 * what is typed - a number, an auxiliary code, a third party's code - and reads its movements
 * through LedgerReader, the window opened on what it carried since its exercise began. Runs in the
 * dossier's context.
 */
final readonly class AccountStatements
{
    public function __construct(
        private AccountRepository $accounts,
        private ThirdPartyRepository $thirdParties,
        private LedgerReader $reader,
    ) {
    }

    /** The account or third party typed, or null when nothing of the dossier answers it. */
    public function subject(Dossier $dossier, string $typed): ?ConsultedSubject
    {
        $code = mb_strtoupper(trim($typed));
        if ('' === $code) {
            return null;
        }
        if (1 === preg_match('/^\d+$/', $code)) {
            $account = $this->accounts->findOneByNumber($dossier, $code);

            return null === $account ? null : ConsultedSubject::account($account);
        }
        $thirdParty = $this->thirdParties->findOneByAuxiliaryCode($dossier, $code) ?? $this->thirdParties->findOneByCode($dossier, $code);

        return null === $thirdParty ? null : ConsultedSubject::thirdParty($thirdParty);
    }

    /** The first day of the exercise the dossier's date falls in, or that date when none holds it. */
    public function defaultFrom(Dossier $dossier): \DateTimeImmutable
    {
        return $this->reader->exerciseStart($dossier, $dossier->getDossierDate()) ?? $dossier->getDossierDate();
    }

    public function statement(Dossier $dossier, ConsultedSubject $subject, ConsultationWindow $window, EntryScope $scope): AccountStatement
    {
        $from = $window->from;
        $start = $this->reader->exerciseStart($dossier, $from) ?? $from;
        $hasOpening = $start < $from;
        $opening = Totals::zero();
        if ($hasOpening) {
            $eve = $from->modify('-1 day');
            $opening = null !== $subject->account
                ? $this->reader->accountBalance($subject->account, $start, $eve, $scope)
                : $this->reader->thirdPartyBalance($this->thirdParty($subject), $start, $eve, $scope);
        }
        $movements = null !== $subject->account
            ? $this->reader->movements($subject->account, $from, $window->to, $scope, $opening)
            : $this->reader->thirdPartyMovements($this->thirdParty($subject), $from, $window->to, $scope, $opening);

        return new AccountStatement($subject, $window, $scope, $opening, $hasOpening, $movements);
    }

    private function thirdParty(ConsultedSubject $subject): ThirdParty
    {
        return $subject->thirdParty ?? throw new \LogicException('A third party is consulted.');
    }
}
