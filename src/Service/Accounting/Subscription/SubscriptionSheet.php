<?php

declare(strict_types=1);

namespace App\Service\Accounting\Subscription;

use App\Entity\Accounting\EntrySubscription;
use App\Entity\Accounting\EntryTemplate;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Template\TemplateCall;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\FrenchFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to a dossier's subscriptions (Comptabilité › Paramètres comptables › Abonnements):
 * checks a subscription as typed (SubscriptionRules, every fault under its field), creates, changes
 * and deletes it - each gesture in the audit trail. Deleting one touches no entry it generated.
 * Runs inside the dossier's context; the caller checks DossierVoter::EDIT and flushes.
 */
final readonly class SubscriptionSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryTemplateRepository $templates,
        private AccountRepository $accounts,
        private ThirdPartyRepository $thirdParties,
        private PeriodRepository $periods,
        private AuditTrail $auditTrail,
    ) {
    }

    public function check(SubscriptionDraft $draft, Dossier $dossier, ?EntrySubscription $subscription = null): ?SubscriptionFields
    {
        $months = [];
        foreach ($this->periods->findByDossier($dossier) as $period) {
            $months[$period->getStartsOn()->format('Y-m')] = true;
        }

        return SubscriptionRules::check(
            $draft,
            fn (string $code) => $this->templates->findOneByCode($dossier, $code),
            fn (EntryTemplate $template): bool => TemplateCall::asksThirdParty($template, fn (string $number) => $this->accounts->findOneByNumber($dossier, $number)),
            fn (string $code) => $this->thirdParties->findOneByAuxiliaryCode($dossier, $code) ?? $this->thirdParties->findOneByCode($dossier, $code),
            static fn (\DateTimeImmutable $month): bool => isset($months[$month->format('Y-m')]),
            $subscription?->getGenerated() ?? 0,
            $subscription?->getFirstMonth(),
        );
    }

    /**
     * Why a subscription saved earlier no longer reads (its template changed: it now asks a third
     * party, or has a line to type while the occurrences go to the brouillard; its third party
     * gone): the first fault SubscriptionRules finds in it today, or null. Its list says « À revoir ».
     */
    public function faultOf(EntrySubscription $subscription): ?string
    {
        $draft = SubscriptionDraft::of($subscription);

        return null === $this->check($draft, $subscription->getDossier(), $subscription) ? (string) reset($draft->errors) : null;
    }

    public function create(Dossier $dossier, SubscriptionFields $fields, User|string $by): EntrySubscription
    {
        $subscription = new EntrySubscription($dossier, $fields->template, $fields->firstMonth);
        self::write($subscription, $fields);
        $this->entityManager->persist($subscription);
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::EntrySubscriptionCreated,
            $by,
            \sprintf('Abonnement %s créé : modèle %s, %d échéances depuis %s', $fields->label, $fields->template->getCode(), $fields->occurrences, FrenchFormat::period($fields->firstMonth)),
            ['dossier' => $dossier->getId(), 'template' => $fields->template->getCode(), 'occurrences' => $fields->occurrences, 'target' => $fields->target->value],
        );

        return $subscription;
    }

    /** False when nothing changed (nothing is written). */
    public function update(EntrySubscription $subscription, SubscriptionFields $fields, User $by): bool
    {
        $before = self::snapshot($subscription);
        self::write($subscription, $fields);
        if (self::snapshot($subscription) === $before) {
            return false;
        }
        $this->auditTrail->inDossier(
            $subscription->getDossier(),
            AuditAction::EntrySubscriptionChanged,
            $by,
            \sprintf('Abonnement %s modifié', $fields->label),
            ['dossier' => $subscription->getDossier()->getId(), 'subscription' => $subscription->getId(), 'before' => $before],
        );

        return true;
    }

    public function delete(EntrySubscription $subscription, User $by): void
    {
        $this->auditTrail->inDossier(
            $subscription->getDossier(),
            AuditAction::EntrySubscriptionDeleted,
            $by,
            \sprintf('Abonnement %s supprimé (%d échéances générées sur %d)', $subscription->getLabel(), $subscription->getGenerated(), $subscription->getOccurrences()),
            ['dossier' => $subscription->getDossier()->getId(), 'subscription' => $subscription->getId(), 'template' => $subscription->getTemplate()->getCode()],
        );
        $this->entityManager->remove($subscription);
    }

    private static function write(EntrySubscription $subscription, SubscriptionFields $fields): void
    {
        $subscription->describe($fields->label, $fields->template, $fields->amount, $fields->vatRate, $fields->thirdParty, $fields->day, $fields->firstMonth, $fields->occurrences, $fields->pattern, $fields->target);
    }

    /** @return array{label: string, template: string, amount: ?int, rate: ?string, thirdParty: string, day: int, firstMonth: string, occurrences: int, pattern: string, target: string} */
    private static function snapshot(EntrySubscription $subscription): array
    {
        return [
            'label' => $subscription->getLabel(),
            'template' => $subscription->getTemplate()->getCode(),
            'amount' => $subscription->getAmount()?->cents,
            'rate' => $subscription->getVatRate(),
            'thirdParty' => $subscription->getThirdParty(),
            'day' => $subscription->getDayOfMonth(),
            'firstMonth' => $subscription->getFirstMonth()->format('Y-m-d'),
            'occurrences' => $subscription->getOccurrences(),
            'pattern' => $subscription->getDocumentPattern(),
            'target' => $subscription->getTarget()->value,
        ];
    }
}
