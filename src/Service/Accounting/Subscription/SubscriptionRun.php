<?php

declare(strict_types=1);

namespace App\Service\Accounting\Subscription;

use App\Entity\Accounting\EntrySubscription;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\SubscriptionTarget;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntrySubscriptionRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\JournalGrid;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Accounting\Template\TemplateCall;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\FrenchFormat;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Générer jusqu'au » the dossier's date (Paramètres comptables › Abonnements): every occurrence of
 * every subscription whose date has come and that is not generated yet, in order - never the server's
 * clock, the date is the dossier's (DossierClock).
 *
 * Each occurrence is the template called (TemplateCall::lines(), the amount, rate and third party of
 * the subscription, its date and its document), then either appended to the pending work of the grid
 * of its journal and month (JournalGrid::append(): the person saves it with F10) or drafted into the
 * brouillard through the Ledger, after the grid's own checks (JournalGrid::check(): EntryRules, the
 * controls the assistance level blocks on). One occurrence refused - a closed month, a month outside
 * the exercises, a rule - stops its subscription there, and the outcome says why; the others go on.
 *
 * Each occurrence is one transaction with the subscription's row locked: its counter moves with the
 * entry it made, and two tabs generating at once never make an occurrence twice.
 */
final readonly class SubscriptionRun
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntrySubscriptionRepository $subscriptions,
        private AccountRepository $accounts,
        private PeriodRepository $periods,
        private JournalGrid $grid,
        private Ledger $ledger,
        private AuditTrail $auditTrail,
    ) {
    }

    /**
     * What a generation up to `until` would make, per target - what the confirmation bar counts.
     *
     * @return array<string, int> SubscriptionTarget's value => entries
     */
    public function due(Dossier $dossier, \DateTimeImmutable $until): array
    {
        $count = [SubscriptionTarget::Pending->value => 0, SubscriptionTarget::Draft->value => 0];
        foreach ($this->subscriptions->findByDossier($dossier) as $subscription) {
            $count[$subscription->getTarget()->value] += \count(self::ranksDue($subscription, $until));
        }

        return $count;
    }

    /** @return list<int> */
    public static function ranksDue(EntrySubscription $subscription, \DateTimeImmutable $until): array
    {
        return SubscriptionSchedule::dueUntil($subscription->getFirstMonth(), $subscription->getDayOfMonth(), $subscription->getOccurrences(), $subscription->getGenerated(), $until);
    }

    /** The occurrences due by `until`, generated; pending work is the person's own, so it needs a person. */
    public function generate(Dossier $dossier, \DateTimeImmutable $until, User|string $actor, \DateTimeImmutable $now): SubscriptionOutcome
    {
        $outcome = new SubscriptionOutcome();
        foreach ($this->subscriptions->findByDossier($dossier) as $subscription) {
            while ([] !== ($ranks = self::ranksDue($subscription, $until))) {
                $fault = $this->one($dossier, $subscription, $ranks[0], $actor, $now, $outcome);
                if (null !== $fault) {
                    $outcome->faults[] = $fault;
                    break;
                }
            }
        }

        return $outcome;
    }

    /** One occurrence, in its own transaction; the sentence that says why it was refused, or null. */
    private function one(Dossier $dossier, EntrySubscription $subscription, int $rank, User|string $actor, \DateTimeImmutable $now, SubscriptionOutcome $outcome): ?string
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->refresh($subscription, LockMode::PESSIMISTIC_WRITE);
            if ($subscription->getGenerated() >= $rank) {
                // Another tab made it meanwhile: the loop reads the counter again.
                $connection->commit();

                return null;
            }
            $date = SubscriptionSchedule::dateOf($subscription->getFirstMonth(), $subscription->getDayOfMonth(), $rank);
            $document = SubscriptionSchedule::documentOf($subscription->getDocumentPattern(), $date, $rank);
            $refusal = $this->write($dossier, $subscription, $rank, $date, $document, $actor, $now);
            if (null !== $refusal) {
                $connection->rollBack();

                return \sprintf('%s : l’écriture du %s (%s) n’est pas générée, l’abonnement s’arrête à elle. %s', $subscription->getLabel(), FrenchFormat::date($date), $document, $refusal);
            }
            $subscription->countGenerated();
            if (SubscriptionTarget::Pending === $subscription->getTarget()) {
                ++$outcome->pending;
            } else {
                ++$outcome->drafts;
            }
            $this->auditTrail->record(
                AuditAction::EntrySubscriptionGenerated,
                $actor,
                'dossier',
                (string) $dossier->getId(),
                \sprintf('Abonnement %s : écriture %d sur %d générée (%s du %s, %s)', $subscription->getLabel(), $rank, $subscription->getOccurrences(), $document, FrenchFormat::date($date), mb_strtolower($subscription->getTarget()->label())),
                ['dossier' => $dossier->getId(), 'subscription' => $subscription->getId(), 'rank' => $rank, 'date' => $date->format('Y-m-d'), 'document' => $document, 'target' => $subscription->getTarget()->value],
            );
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $failure;
        }

        return null;
    }

    /** The occurrence written where its subscription sends it; the reason it is not, or null. */
    private function write(Dossier $dossier, EntrySubscription $subscription, int $rank, \DateTimeImmutable $date, string $document, User|string $actor, \DateTimeImmutable $now): ?string
    {
        $template = $subscription->getTemplate();
        $period = $this->periods->findAt($dossier, $date);
        if (null === $period) {
            return \sprintf('Le %s n’est dans aucun exercice du dossier.', FrenchFormat::date($date));
        }
        if ($period->getExercise()->isClosed()) {
            return \sprintf('L’exercice %s est clos.', $period->getExercise()->getCode());
        }
        if ($period->isClosed()) {
            return \sprintf('%s est clos.', ucfirst(FrenchFormat::period($period->getStartsOn())));
        }

        $call = TemplateCall::lines(
            $template,
            $date,
            $subscription->getAmount(),
            (string) $subscription->getVatRate(),
            $subscription->getThirdParty(),
            fn (string $number) => $this->accounts->findOneByNumber($dossier, $number),
            true,
        );
        if ([] !== $call['errors']) {
            return \sprintf('Le modèle %s ne s’appelle plus ainsi : %s', $template->getCode(), implode(' ', $call['errors']));
        }
        $group = new GridGroup(\sprintf('s%d-%d', (int) $subscription->getId(), $rank), null, $date->format('Y-m-d'), $document, $call['lines']);

        if (SubscriptionTarget::Pending === $subscription->getTarget()) {
            if (!$actor instanceof User) {
                throw new \LogicException('Pending work is a person\'s own: a command generates into the brouillard only.');
            }
            $this->grid->append($template->getJournal(), $period, $actor, $group, $now);

            return null;
        }

        $check = $this->grid->check($this->grid->context($dossier, $template->getJournal(), $period), $group);
        if (!$check->isWritable()) {
            foreach ($check->controls as $control) {
                if ($control->blocking) {
                    return $control->message;
                }
            }

            $errors = $check->errors;

            return (string) (reset($errors) ?: 'L’écriture est refusée.');
        }
        try {
            $this->ledger->draft($dossier, $check->draft, $actor);
        } catch (EntryRefused $refusal) {
            return implode(' ', $refusal->errors);
        }

        return null;
    }
}
