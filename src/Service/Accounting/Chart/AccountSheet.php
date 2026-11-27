<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountNature;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\JournalRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to a dossier's plan by hand (Comptabilité › Paramètres comptables › Plan de
 * comptes): checks an account as typed, names every fault under its field, creates, changes and
 * deletes - each gesture in the audit trail. A number that subdivides no account of the reference
 * chart is **signalled, not refused**: the assistance level that would refuse it arrives with the
 * missions (step 2.8), and until then the dossier warns (design-ux.md § 3.2, « A : signalé »).
 * Runs inside the dossier's context; the caller checks DossierVoter::EDIT and flushes.
 */
final readonly class AccountSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountRepository $accounts,
        private JournalRepository $journals,
        private ReferenceCharts $charts,
        private AuditTrail $auditTrail,
    ) {
    }

    /** True when the draft may be saved; its errors and warning are filled either way. */
    public function check(AccountDraft $draft, Dossier $dossier, ?Account $account = null): bool
    {
        $draft->errors = [];
        $draft->warning = null;
        $draft->number = AccountNumber::normalize($draft->number);
        $draft->label = trim((string) preg_replace('/\s+/u', ' ', $draft->label));

        $fault = AccountNumber::fault($draft->number);
        if (null !== $fault) {
            $draft->errors['number'] = $fault;
        } else {
            $same = $this->accounts->findOneByNumber($dossier, $draft->number);
            if (null !== $same && $same !== $account) {
                $draft->errors['number'] = \sprintf('Le compte %s existe déjà : %s.', $draft->number, $same->getLabel());
            } elseif (null !== $account) {
                $journals = $this->journals->findByCounterpart($account);
                foreach ($journals as $journal) {
                    $nature = $journal->getType()->counterpartNature();
                    if (null !== $nature && AccountNature::of($draft->number) !== $nature) {
                        $draft->errors['number'] = \sprintf('Ce compte est la contrepartie du journal %s : il reste un compte de %s.', $journal->getCode(), mb_strtolower($nature->label()));
                    }
                }
            }
            $draft->warning = $this->outsideChart($dossier, $draft->number);
        }

        if ('' === $draft->label) {
            $draft->errors['label'] = 'Indiquez l’intitulé du compte.';
        } elseif (mb_strlen($draft->label) > 200) {
            $draft->errors['label'] = 'Un intitulé tient en 200 caractères.';
        }

        if ($draft->collective && null === $fault && !AccountNumber::mayBeCollective($draft->number)) {
            $draft->errors['collective'] = 'Seul un compte de tiers (classe 4) est collectif.';
        }

        return [] === $draft->errors;
    }

    /** The warning for a number that subdivides no account of the dossier's reference chart, or null. */
    public function outsideChart(Dossier $dossier, string $number): ?string
    {
        $chart = null === $dossier->getChartCode() ? null : $this->charts->get($dossier->getChartCode());
        if (null === $chart || null !== $chart->rootOf($number)) {
            return null;
        }

        return \sprintf('Le compte %s ne subdivise aucun compte du %s : vérifiez son numéro.', $number, $chart->label);
    }

    /** A checked draft as a new account of the plan. */
    public function create(Dossier $dossier, AccountDraft $draft, User $by): Account
    {
        $account = new Account($dossier, $draft->number, $draft->label, $draft->lettrable, $draft->collective, AccountOrigin::Created);
        $this->entityManager->persist($account);
        $this->auditTrail->record(
            AuditAction::AccountCreated,
            $by,
            'dossier',
            (string) $dossier->getId(),
            \sprintf('Compte %s %s créé dans %s', $draft->number, $draft->label, $dossier->getName()),
            ['dossier' => $dossier->getId(), 'number' => $draft->number],
        );

        return $account;
    }

    /** A checked draft over an account; false when nothing changed (nothing is written). */
    public function update(Account $account, AccountDraft $draft, User $by): bool
    {
        $before = ['number' => $account->getNumber(), 'label' => $account->getLabel(), 'lettrable' => $account->isLettrable(), 'collective' => $account->isCollective()];
        $after = ['number' => $draft->number, 'label' => $draft->label, 'lettrable' => $draft->lettrable, 'collective' => $draft->collective];
        $changed = array_keys(array_filter($after, static fn (string|bool $value, string $field): bool => $value !== $before[$field], \ARRAY_FILTER_USE_BOTH));
        if ([] === $changed) {
            return false;
        }
        $account->describe($draft->number, $draft->label, $draft->lettrable, $draft->collective);
        $this->auditTrail->record(
            AuditAction::AccountChanged,
            $by,
            'dossier',
            (string) $account->getDossier()->getId(),
            $before['number'] === $draft->number
                ? \sprintf('Compte %s modifié', $draft->number)
                : \sprintf('Compte %s renuméroté %s', $before['number'], $draft->number),
            ['dossier' => $account->getDossier()->getId(), 'fields' => $changed, 'before' => $before],
        );

        return true;
    }

    /** Why the account may not leave the plan, or null when it may. */
    public function deletionRefusal(Account $account): ?string
    {
        $journals = $this->journals->findByCounterpart($account);
        if ([] !== $journals) {
            return \sprintf('Ce compte est la contrepartie du journal %s : changez d’abord la contrepartie du journal.', $journals[0]->getCode());
        }

        return null;
    }

    public function delete(Account $account, User $by): void
    {
        if (null !== $this->deletionRefusal($account)) {
            throw new \LogicException('This account is in use.');
        }
        $this->auditTrail->record(
            AuditAction::AccountDeleted,
            $by,
            'dossier',
            (string) $account->getDossier()->getId(),
            \sprintf('Compte %s %s supprimé du plan', $account->getNumber(), $account->getLabel()),
            ['dossier' => $account->getDossier()->getId(), 'number' => $account->getNumber(), 'label' => $account->getLabel()],
        );
        $this->entityManager->remove($account);
    }
}
