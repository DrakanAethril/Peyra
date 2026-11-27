<?php

declare(strict_types=1);

namespace App\Service\Accounting\Subscription;

use App\Entity\Accounting\EntrySubscription;

/**
 * A subscription as a person typed it, before SubscriptionRules checks it, with the error of each
 * field: `label`, `template`, `amount`, `rate`, `thirdParty`, `day`, `firstMonth`, `occurrences`,
 * `pattern`, `target`.
 */
final class SubscriptionDraft
{
    public string $label = '';
    /** The template's code. */
    public string $template = '';
    /** In cents, as the amount field gives it; null when empty or not a number. */
    public ?int $amount = null;
    /** As typed (« 20 », « 5,5 »). */
    public string $rate = '';
    public string $thirdParty = '';
    public string $day = '';
    /** The first month, its first day as « AAAA-MM-01 ». */
    public string $firstMonth = '';
    public string $occurrences = '';
    public string $pattern = '';
    /** SubscriptionTarget's value. */
    public string $target = '';

    /** @var array<string, string> by key */
    public array $errors = [];

    public static function of(EntrySubscription $subscription): self
    {
        $draft = new self();
        $draft->label = $subscription->getLabel();
        $draft->template = $subscription->getTemplate()->getCode();
        $draft->amount = $subscription->getAmount()?->cents;
        $draft->rate = null === $subscription->getVatRate() ? '' : str_replace('.', ',', $subscription->getVatRate());
        $draft->thirdParty = $subscription->getThirdParty();
        $draft->day = (string) $subscription->getDayOfMonth();
        $draft->firstMonth = $subscription->getFirstMonth()->format('Y-m-d');
        $draft->occurrences = (string) $subscription->getOccurrences();
        $draft->pattern = $subscription->getDocumentPattern();
        $draft->target = $subscription->getTarget()->value;

        return $draft;
    }

    public function error(string $key): ?string
    {
        return $this->errors[$key] ?? null;
    }
}
