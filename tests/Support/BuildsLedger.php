<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\DossierKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\AccountRepository;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Dossier\DossierContext;
use App\Service\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A dossier ready for entries, for a kernel test: a template of the common library with its
 * exercise 2026 (date 15/03/2026), the PCG 2026, the journals ACH, VTE, BQ (512000), OD and AN, and
 * three third parties - F0001 on 401000 (401SCIE), C0019 on 411000 (411GIRA), A0001 on 455100 -
 * entered in its context. In a file of its own: ParaTest hands out files.
 */
trait BuildsLedger
{
    use SavesDossiers;

    private function ledgerDossier(): Dossier
    {
        $dossier = $this->savedDossier(DossierKind::Template, null);
        $this->installPlan($dossier);
        $this->prepareLedger($dossier);

        return $dossier;
    }

    /** The journals and the three third parties added to a dossier that has its plan, its context entered. */
    private function prepareLedger(Dossier $dossier): void
    {
        $container = static::getContainer();
        $container->get(DossierContext::class)->enter($dossier);
        $entityManager = $container->get(EntityManagerInterface::class);
        $accounts = $container->get(AccountRepository::class);
        $account = static fn (string $number) => $accounts->findOneByNumber($dossier, $number) ?? throw new \LogicException($number);
        foreach ([['ACH', 'Achats', JournalType::Purchases, null], ['VTE', 'Ventes', JournalType::Sales, null], ['BQ', 'Banque', JournalType::Bank, '512000'], ['OD', 'Opérations diverses', JournalType::General, null], ['AN', 'À-nouveaux', JournalType::Opening, null]] as [$code, $label, $type, $counterpart]) {
            $entityManager->persist(new Journal($dossier, $code, $label, $type, null === $counterpart ? null : $account($counterpart)));
        }
        foreach ([['F0001', ThirdPartyKind::Supplier, 'Scierie du Haut-Forez', '401000', '401SCIE'], ['C0019', ThirdPartyKind::Customer, 'Girard, Jean et Anne', '411000', '411GIRA'], ['A0001', ThirdPartyKind::Other, 'Vasseur, Bernard', '455100', null]] as [$code, $kind, $name, $number, $auxiliary]) {
            $thirdParty = new ThirdParty($dossier, $code, $kind, $name);
            $thirdParty->keepOn($account($number), $auxiliary);
            $entityManager->persist($thirdParty);
        }
        $entityManager->flush();
    }

    /**
     * An entry written through the Ledger: each line « account amount » with D or C, a third
     * party after a slash (« 401000/F0001 C 4158.12 »).
     *
     * @param list<string> $lines
     */
    private function entry(Dossier $dossier, string $journal, string $date, string $document, array $lines, bool $validated = false, string $label = 'Écriture de test'): Entry
    {
        $drafts = [];
        foreach ($lines as $line) {
            [$account, $side, $amount] = explode(' ', $line);
            [$number, $thirdParty] = array_pad(explode('/', $account), 2, '');
            $money = Money::euros($amount);
            $drafts[] = 'D' === $side ? EntryLineDraft::debit($number, $money, '', $thirdParty) : EntryLineDraft::credit($number, $money, '', $thirdParty);
        }
        $ledger = static::getContainer()->get(Ledger::class);
        $entry = $ledger->draft($dossier, new EntryDraft($journal, new \DateTimeImmutable($date), $document, $label, $drafts), 'test');
        if ($validated) {
            $ledger->validate([$entry], 'test');
        }

        return $entry;
    }
}
