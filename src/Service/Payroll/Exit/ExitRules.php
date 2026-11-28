<?php

declare(strict_types=1);

namespace App\Service\Payroll\Exit;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\ExitReason;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;

/**
 * What makes an employee's exit (step 4.2c), pure - the Fin de contrat tab goes through it, and so
 * does a change of the fiche once an exit is recorded (EmployeeSheet):
 *
 * - a reason the contract allows (ExitReason::allows(): a resignation, a dismissal, a conventional
 *   termination or a retirement end an open-ended contract; a term or an early termination a
 *   fixed-term contract or an apprenticeship; the trial period any of them);
 * - a last day, on or after the day of hire and not before the latest terms of the contract;
 * - a contract reaching its term ends on the day it names, without a notification; any other
 *   reason has its notification day (the letter, the convention), between the hire and the last
 *   day, and an early end of a contract with a term comes before that term.
 */
final class ExitRules
{
    /** @param \DateTimeImmutable $latestTermsFrom the day the latest terms of the contract start */
    public static function check(ExitDraft $draft, ContractType $contract, \DateTimeImmutable $hiredOn, ?\DateTimeImmutable $contractEndsOn, \DateTimeImmutable $latestTermsFrom): ?ExitFields
    {
        $draft->errors = [];
        $reason = ExitReason::tryFrom(trim($draft->reason));
        if (null === $reason) {
            $draft->errors['reason'] = 'Choisissez le motif de la sortie.';
        } elseif (!$reason->allows($contract)) {
            $draft->errors['reason'] = \sprintf('« %s » ne met pas fin à un %s.', $reason->label(), $contract->label());
        }
        $exitOn = self::date($draft, 'exitOn', 'Indiquez le dernier jour du contrat.');
        if (null !== $exitOn && $exitOn < $hiredOn) {
            $draft->errors['exitOn'] = \sprintf('Le dernier jour du contrat suit l’entrée du %s.', FrenchFormat::date($hiredOn));
        } elseif (null !== $exitOn && $exitOn < $latestTermsFrom) {
            $draft->errors['exitOn'] = \sprintf('Les conditions du contrat changent le %s, après ce dernier jour.', FrenchFormat::date($latestTermsFrom));
        }

        $notifiedOn = null;
        if (null !== $reason && $reason->isTerm()) {
            $draft->notifiedOn = '';
            if (null !== $exitOn && null !== $contractEndsOn && $exitOn != $contractEndsOn && !isset($draft->errors['exitOn'])) {
                $draft->errors['exitOn'] = \sprintf('Le contrat prend fin à son terme, le %s ; une sortie à une autre date est une rupture anticipée.', FrenchFormat::date($contractEndsOn));
            }
        } elseif (null !== $reason) {
            $notifiedOn = self::date($draft, 'notifiedOn', 'Indiquez le jour où la sortie a été notifiée.');
            if (null !== $notifiedOn && $notifiedOn < $hiredOn) {
                $draft->errors['notifiedOn'] = \sprintf('La notification suit l’entrée du %s.', FrenchFormat::date($hiredOn));
            } elseif (null !== $notifiedOn && null !== $exitOn && $notifiedOn > $exitOn) {
                $draft->errors['notifiedOn'] = 'La notification précède le dernier jour du contrat.';
            }
            if (null !== $exitOn && null !== $contractEndsOn && $contract->hasEnd() && $exitOn >= $contractEndsOn && !isset($draft->errors['exitOn'])) {
                $draft->errors['exitOn'] = \sprintf('Une rupture anticipée précède le terme du contrat, le %s.', FrenchFormat::date($contractEndsOn));
            }
        }

        if ([] !== $draft->errors || null === $reason || null === $exitOn) {
            return null;
        }

        return new ExitFields($reason, $notifiedOn, $exitOn);
    }

    private static function date(ExitDraft $draft, string $field, string $missing): ?\DateTimeImmutable
    {
        $text = trim((string) $draft->{$field});
        if ('' === $text) {
            $draft->errors[$field] = $missing;

            return null;
        }
        $date = DateValue::parse($text);
        if (null === $date) {
            $draft->errors[$field] = 'Cette date n’existe pas.';
        }

        return $date;
    }
}
