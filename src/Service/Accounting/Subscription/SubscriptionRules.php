<?php

declare(strict_types=1);

namespace App\Service\Accounting\Subscription;

use App\Entity\Accounting\EntryTemplate;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\SubscriptionTarget;
use App\Enum\Accounting\TemplateBase;
use App\Service\Accounting\Ledger\EntryRules;
use App\Service\Accounting\Template\GuidedEntry;
use App\Service\Accounting\Template\VatRate;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * The one reading of a subscription (« abonnement »), a pure function over four lookups - the
 * fiche and the demonstration's file go through it:
 *
 * - a label; a template of the dossier;
 * - the call's amount and rate when the template asks an amount (none otherwise), its third party
 *   when one of its collective lines asks one (none otherwise);
 * - a day of the month from 1 to 31 (a day the month does not have is its last), a first month of
 *   the dossier's exercises - fixed once an occurrence is generated -, 1 to 120 occurrences, never
 *   fewer than those already generated;
 * - a document pattern of 40 characters at most once filled, with {MM} or {N} so that each
 *   occurrence has its own document ({AAAA} the year; no other brace);
 * - where the occurrences go: the brouillard asks a template whose every line is computed (a line
 *   « À saisir » can only be completed in the grid, from the pending work).
 */
final class SubscriptionRules
{
    public const int MAX_OCCURRENCES = 120;

    /**
     * @param callable(string): ?EntryTemplate      $templateOf     by code
     * @param callable(EntryTemplate): bool         $asksThirdParty whether a call names the third party
     * @param callable(string): ?ThirdParty         $thirdPartyOf   by auxiliary code, then by code
     * @param callable(\DateTimeImmutable): bool    $isMonth        a month of the dossier's exercises
     * @param ?\DateTimeImmutable                   $fixedFirstMonth the first month, once an occurrence is generated
     */
    public static function check(SubscriptionDraft $draft, callable $templateOf, callable $asksThirdParty, callable $thirdPartyOf, callable $isMonth, int $generated = 0, ?\DateTimeImmutable $fixedFirstMonth = null): ?SubscriptionFields
    {
        $draft->errors = [];
        $draft->label = trim((string) preg_replace('/\s+/u', ' ', $draft->label));
        $draft->template = strtoupper(trim($draft->template));
        $draft->thirdParty = strtoupper(trim($draft->thirdParty));
        $draft->pattern = trim($draft->pattern);

        if ('' === $draft->label) {
            $draft->errors['label'] = 'Indiquez l’intitulé de l’abonnement.';
        } elseif (mb_strlen($draft->label) > 80) {
            $draft->errors['label'] = 'Un intitulé d’abonnement tient en 80 caractères.';
        }

        $template = '' === $draft->template ? null : $templateOf($draft->template);
        if ('' === $draft->template) {
            $draft->errors['template'] = 'Choisissez le modèle d’écriture de l’abonnement.';
        } elseif (null === $template) {
            $draft->errors['template'] = \sprintf('Le modèle %s n’existe pas dans le dossier.', $draft->template);
        }

        [$amount, $rate] = null === $template ? [null, null] : self::call($draft, $template);

        $thirdParty = '';
        if (null !== $template && $asksThirdParty($template)) {
            if ('' === $draft->thirdParty) {
                $draft->errors['thirdParty'] = \sprintf('Le modèle %s demande le tiers de l’écriture : indiquez son code auxiliaire.', $template->getCode());
            } elseif (null === $thirdPartyOf($draft->thirdParty)) {
                $draft->errors['thirdParty'] = \sprintf('Aucun tiers du dossier n’a le code %s.', $draft->thirdParty);
            } else {
                $thirdParty = $draft->thirdParty;
            }
        } else {
            $draft->thirdParty = '';
        }

        $day = self::integer($draft->day);
        if ('' === trim($draft->day)) {
            $draft->errors['day'] = 'Indiquez le jour du mois de chaque écriture.';
        } elseif (null === $day || $day < 1 || $day > 31) {
            $draft->errors['day'] = 'Un jour du mois va de 1 à 31 (31 : le dernier jour du mois).';
        }

        $first = 1 === preg_match('/^\d{4}-\d{2}-01$/', $draft->firstMonth) ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $draft->firstMonth) ?: null) : null;
        if (null === $first) {
            $draft->errors['firstMonth'] = 'Choisissez le premier mois de l’abonnement.';
        } elseif (null !== $fixedFirstMonth && $generated > 0 && $first->format('Y-m') !== $fixedFirstMonth->format('Y-m')) {
            $draft->errors['firstMonth'] = \sprintf('Des écritures sont déjà générées depuis %s : le premier mois ne change plus.', FrenchFormat::period($fixedFirstMonth));
            $first = $fixedFirstMonth;
        } elseif (!$isMonth($first)) {
            $draft->errors['firstMonth'] = \sprintf('%s n’est dans aucun exercice du dossier.', ucfirst(FrenchFormat::period($first)));
        }

        $count = self::integer($draft->occurrences);
        if ('' === trim($draft->occurrences)) {
            $draft->errors['occurrences'] = 'Indiquez le nombre d’échéances.';
        } elseif (null === $count || $count < 1 || $count > self::MAX_OCCURRENCES) {
            $draft->errors['occurrences'] = \sprintf('Un abonnement compte de 1 à %d échéances.', self::MAX_OCCURRENCES);
        } elseif ($count < $generated) {
            $draft->errors['occurrences'] = \sprintf('%d échéances sont déjà générées : l’abonnement en compte au moins autant.', $generated);
        }

        self::pattern($draft, $count);

        $target = SubscriptionTarget::tryFrom($draft->target);
        if (null === $target) {
            $draft->errors['target'] = 'Choisissez où vont les écritures : saisie en attente ou brouillard.';
        } elseif (SubscriptionTarget::Draft === $target && null !== $template && !GuidedEntry::offers($template)) {
            $draft->errors['target'] = \sprintf('Le modèle %s a une ligne « À saisir » : ses écritures vont en saisie en attente, où le montant se complète dans la grille.', $template->getCode());
        }

        if ([] !== $draft->errors || null === $template || null === $day || null === $first || null === $count || null === $target) {
            return null;
        }

        return new SubscriptionFields($draft->label, $template, $amount, $rate, $thirdParty, $day, $first, $count, $draft->pattern, $target);
    }

    /**
     * The amount and the rate of the call, when the template asks an amount; cleared otherwise.
     *
     * @return array{0: ?Money, 1: ?string}
     */
    private static function call(SubscriptionDraft $draft, EntryTemplate $template): array
    {
        if (!$template->asksAmount()) {
            $draft->amount = null;
            $draft->rate = '';

            return [null, null];
        }
        $amount = null;
        if (null === $draft->amount || $draft->amount <= 0) {
            $draft->errors['amount'] = \sprintf('Indiquez %s de chaque écriture.', ($template->getBase() ?? TemplateBase::Gross)->phrase());
        } else {
            $amount = Money::cents($draft->amount);
        }
        $rate = VatRate::parse('' === trim($draft->rate) ? (string) $template->getVatRate() : $draft->rate);
        if (null === $rate) {
            $draft->errors['rate'] = 'Un taux de TVA s’écrit en pourcentage, de 0 à 100 : 20, 5,5.';

            return [$amount, null];
        }
        $draft->rate = str_replace('.', ',', VatRate::canonical($rate));

        return [$amount, VatRate::canonical($rate)];
    }

    private static function pattern(SubscriptionDraft $draft, ?int $count): void
    {
        if ('' === $draft->pattern) {
            $draft->errors['pattern'] = 'Indiquez la pièce de chaque écriture : LOY-{AAAA}-{MM}.';

            return;
        }
        $rest = str_replace(SubscriptionSchedule::TOKENS, '', $draft->pattern);
        if (str_contains($rest, '{') || str_contains($rest, '}')) {
            $draft->errors['pattern'] = 'Une pièce ne connaît que {AAAA} (l’année), {MM} (le mois) et {N} (le rang de l’échéance).';
        } elseif (!str_contains($draft->pattern, '{MM}') && !str_contains($draft->pattern, '{N}')) {
            $draft->errors['pattern'] = 'Chaque écriture a sa pièce : mettez {MM} (le mois) ou {N} (le rang) dans la pièce.';
        } elseif (mb_strlen(str_replace(SubscriptionSchedule::TOKENS, ['0000', '00', str_repeat('0', \strlen((string) ($count ?? self::MAX_OCCURRENCES)))], $draft->pattern)) > EntryRules::DOCUMENT_MAX) {
            $draft->errors['pattern'] = \sprintf('Une fois remplie, la pièce tient en %d caractères.', EntryRules::DOCUMENT_MAX);
        }
    }

    private static function integer(string $typed): ?int
    {
        $text = trim($typed);

        return 1 === preg_match('/^\d{1,4}$/', $text) ? (int) $text : null;
    }
}
