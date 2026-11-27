<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Service\Accounting\Subscription\SubscriptionDraft;
use App\Service\Shared\Money;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/subscriptions.yaml` read and checked for its shape: the demonstration's
 * subscriptions, each a SubscriptionDraft that SubscriptionRules checks like the fiche (a fault
 * there names the subscription and installs nothing). Amounts are euros as text, read exactly.
 */
final readonly class DemoSubscriptionFile
{
    /** @param list<SubscriptionDraft> $drafts */
    private function __construct(public array $drafts)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['subscriptions'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « subscriptions » est attendue.');
        }

        $drafts = [];
        foreach ($list as $index => $entry) {
            $where = 'subscriptions['.$index.']';
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException($where.' : un dictionnaire est attendu.');
            }
            $amount = self::optional($entry, 'amount', $where);
            if (null !== $amount && 1 !== preg_match('/^\d+\.\d{2}$/', $amount)) {
                throw new \InvalidArgumentException($where.'.amount : un montant « 1234.56 » entre guillemets est attendu.');
            }
            $month = self::text($entry, 'first_month', $where);
            if (1 !== preg_match('/^\d{4}-\d{2}$/', $month)) {
                throw new \InvalidArgumentException($where.'.first_month : un mois « AAAA-MM » entre guillemets est attendu.');
            }
            $draft = new SubscriptionDraft();
            $draft->label = self::text($entry, 'label', $where);
            $draft->template = self::text($entry, 'template', $where);
            $draft->amount = null === $amount ? null : Money::euros($amount)->cents;
            $draft->rate = self::optional($entry, 'rate', $where) ?? '';
            $draft->thirdParty = self::optional($entry, 'third_party', $where) ?? '';
            $draft->day = (string) self::integer($entry, 'day', $where);
            $draft->firstMonth = $month.'-01';
            $draft->occurrences = (string) self::integer($entry, 'occurrences', $where);
            $draft->pattern = self::text($entry, 'pattern', $where);
            $draft->target = self::text($entry, 'target', $where);
            $drafts[] = $draft;
        }

        return new self($drafts);
    }

    /** @param array<mixed> $entry */
    private static function integer(array $entry, string $key, string $where): int
    {
        $value = $entry[$key] ?? null;
        if (!\is_int($value)) {
            throw new \InvalidArgumentException($where.'.'.$key.' : un nombre entier est attendu.');
        }

        return $value;
    }

    /** @param array<mixed> $entry */
    private static function text(array $entry, string $key, string $where): string
    {
        return self::optional($entry, $key, $where) ?? throw new \InvalidArgumentException($where.'.'.$key.' : un texte est attendu.');
    }

    /** @param array<mixed> $entry */
    private static function optional(array $entry, string $key, string $where): ?string
    {
        $value = $entry[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException($where.'.'.$key.' : un texte est attendu.');
        }

        return trim($value);
    }
}
