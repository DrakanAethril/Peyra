<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Entity\Accounting\Account;
use App\Entity\ThirdParty\PaymentTerms;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\PaymentTermKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Chart\AccountNumber;

/**
 * What makes a third party valid, field by field - the one reading of the form (ThirdPartySheet)
 * and of each CSV line (ThirdPartyImport). The draft's texts come back normalized, every fault is
 * named under its field. The dossier is reached through three callables: the plan (an account by
 * number), and who already holds a code or an auxiliary code (null when nobody else does).
 */
final class ThirdPartyRules
{
    /**
     * @param callable(string): ?Account $accountOf
     * @param callable(string): ?string  $codeHolder      the name of another third party holding that code
     * @param callable(string): ?string  $auxiliaryHolder the name of another third party holding that auxiliary code
     */
    public static function check(ThirdPartyDraft $draft, callable $accountOf, callable $codeHolder, callable $auxiliaryHolder): ?ThirdPartyFields
    {
        $draft->errors = [];
        $draft->notice = null;
        self::normalize($draft);

        $fault = ThirdPartyCode::fault($draft->code);
        if (null !== $fault) {
            $draft->errors['code'] = $fault;
        } elseif (null !== ($holder = $codeHolder($draft->code))) {
            $draft->errors['code'] = \sprintf('Le code %s est déjà celui de %s.', $draft->code, $holder);
        }

        $kind = ThirdPartyKind::tryFrom($draft->kind);
        if (null === $kind) {
            $draft->errors['kind'] = 'Choisissez la nature du tiers.';
        }

        if ('' === $draft->name) {
            $draft->errors['name'] = 'Indiquez le nom du tiers.';
        } elseif (mb_strlen($draft->name) > 120) {
            $draft->errors['name'] = 'Un nom tient en 120 caractères.';
        }

        $account = null;
        if ('' !== $draft->account) {
            $account = null === AccountNumber::fault($draft->account) ? $accountOf($draft->account) : null;
            if (null === $account) {
                $draft->errors['account'] = 1 === preg_match('/[A-Z]/', $draft->account)
                    ? 'Indiquez ici le compte collectif (401000) ; le code auxiliaire (401SCIE) a son propre champ.'
                    : \sprintf('Le compte %s n’est pas dans le plan du dossier.', $draft->account);
            } elseif (null !== $kind && !\in_array($account->getNature(), $kind->accountNatures(), true)) {
                $draft->errors['account'] = $kind->accountRule();
                $account = null;
            }
        }

        $auxiliary = null;
        if (null !== $account && $account->isCollective() && ('' !== $draft->auxiliaryCode || !isset($draft->errors['name']))) {
            // Left empty, the code is proposed from the name: nothing to say about it until the name is given.
            if ('' === $draft->auxiliaryCode) {
                $draft->auxiliaryCode = AuxiliaryCode::suggest($account->getNumber(), $draft->name, static fn (string $code): bool => null !== $auxiliaryHolder($code));
                $draft->notice = \sprintf('Code auxiliaire %s attribué sur le compte %s.', $draft->auxiliaryCode, $account->getNumber());
            }
            $fault = AuxiliaryCode::fault($draft->auxiliaryCode);
            if (null !== $fault) {
                $draft->errors['auxiliaryCode'] = $fault;
            } elseif (null !== ($holder = $auxiliaryHolder($draft->auxiliaryCode))) {
                $draft->errors['auxiliaryCode'] = \sprintf('Le code auxiliaire %s est déjà celui de %s.', $draft->auxiliaryCode, $holder);
            } else {
                $auxiliary = $draft->auxiliaryCode;
            }
        } elseif ('' !== $draft->auxiliaryCode && !isset($draft->errors['account'])) {
            $draft->errors['auxiliaryCode'] = 'Seul un tiers tenu sur un compte collectif (401000, 411000) a un code auxiliaire.';
        }

        self::checkIdentity($draft);
        self::checkAddress($draft);
        $terms = self::terms($draft);

        $method = PaymentMethod::tryFrom($draft->paymentMethod);
        if (null === $method) {
            $draft->errors['paymentMethod'] = 'Choisissez le mode de règlement.';
        }
        if (mb_strlen($draft->note) > 500) {
            $draft->errors['note'] = 'Une note tient en 500 caractères.';
        }

        if ([] !== $draft->errors || null === $kind || null === $terms || null === $method) {
            return null;
        }

        return new ThirdPartyFields($kind, $account, $auxiliary, $terms, $method);
    }

    private static function normalize(ThirdPartyDraft $draft): void
    {
        $draft->code = ThirdPartyCode::normalize($draft->code);
        $draft->name = self::oneLine($draft->name);
        $draft->account = mb_strtoupper(AccountNumber::normalize($draft->account));
        $draft->auxiliaryCode = AuxiliaryCode::normalize($draft->auxiliaryCode);
        $draft->siren = self::compact($draft->siren);
        $draft->vatNumber = mb_strtoupper(self::compact($draft->vatNumber));
        $draft->street = self::oneLine($draft->street);
        $draft->street2 = self::oneLine($draft->street2);
        $draft->postalCode = self::oneLine($draft->postalCode);
        $draft->city = self::oneLine($draft->city);
        $draft->country = mb_strtoupper(self::compact($draft->country));
        $draft->country = '' === $draft->country ? 'FR' : $draft->country;
        $draft->contactName = self::oneLine($draft->contactName);
        $draft->email = self::compact($draft->email);
        $draft->phone = self::oneLine($draft->phone);
        $draft->termDays = self::compact($draft->termDays);
        $draft->termDay = self::compact($draft->termDay);
        $draft->note = trim(str_replace("\r\n", "\n", $draft->note));
    }

    private static function checkIdentity(ThirdPartyDraft $draft): void
    {
        if ($draft->individual && '' !== $draft->siren) {
            $draft->errors['siren'] = 'Un particulier n’a pas de SIREN.';
        } elseif ('' !== $draft->siren && 1 !== preg_match('/^\d{9}$/', $draft->siren)) {
            $draft->errors['siren'] = 'Un SIREN compte 9 chiffres.';
        }
        if ('' === $draft->vatNumber) {
            return;
        }
        if ($draft->individual) {
            $draft->errors['vatNumber'] = 'Un particulier n’a pas de numéro de TVA intracommunautaire.';
        } elseif (str_starts_with($draft->vatNumber, 'FR')) {
            if (1 !== preg_match('/^FR[0-9A-Z]{2}\d{9}$/', $draft->vatNumber)) {
                $draft->errors['vatNumber'] = 'Un numéro de TVA français s’écrit FR, deux caractères de clé, puis les 9 chiffres du SIREN.';
            } elseif ('' !== $draft->siren && !isset($draft->errors['siren']) && substr($draft->vatNumber, 4) !== $draft->siren) {
                $draft->errors['vatNumber'] = 'Le numéro de TVA reprend le SIREN du tiers.';
            }
        } elseif (1 !== preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $draft->vatNumber)) {
            $draft->errors['vatNumber'] = 'Un numéro de TVA intracommunautaire s’écrit le code du pays (DE, IT…) suivi de 2 à 13 lettres ou chiffres.';
        }
    }

    private static function checkAddress(ThirdPartyDraft $draft): void
    {
        foreach (['street' => 120, 'street2' => 120, 'city' => 80, 'contactName' => 120] as $field => $max) {
            if (mb_strlen($draft->{$field}) > $max) {
                $draft->errors[$field] = \sprintf('Ce champ tient en %d caractères.', $max);
            }
        }
        if (1 !== preg_match('/^[A-Z]{2}$/', $draft->country)) {
            $draft->errors['country'] = 'Le pays s’écrit en deux lettres (FR, DE, BE).';
        }
        if ('' !== $draft->postalCode) {
            if ('FR' === $draft->country && 1 !== preg_match('/^\d{5}$/', $draft->postalCode)) {
                $draft->errors['postalCode'] = 'Un code postal français compte 5 chiffres.';
            } elseif (mb_strlen($draft->postalCode) > 10) {
                $draft->errors['postalCode'] = 'Un code postal tient en 10 caractères.';
            }
        }
        if ('' !== $draft->email && (mb_strlen($draft->email) > 180 || false === filter_var($draft->email, \FILTER_VALIDATE_EMAIL))) {
            $draft->errors['email'] = 'Cette adresse électronique n’est pas valable (nom@domaine.fr).';
        }
        if ('' !== $draft->phone && 1 !== preg_match('/^[0-9 +().\-]{4,30}$/', $draft->phone)) {
            $draft->errors['phone'] = 'Un numéro de téléphone s’écrit avec des chiffres, des espaces et +.';
        }
    }

    private static function terms(ThirdPartyDraft $draft): ?PaymentTerms
    {
        $kind = PaymentTermKind::tryFrom($draft->termKind);
        if (null === $kind) {
            $draft->errors['termKind'] = 'Choisissez les conditions de règlement.';

            return null;
        }
        $days = null;
        if ($kind->needsDays()) {
            $days = 1 === preg_match('/^\d{1,3}$/', $draft->termDays) ? (int) $draft->termDays : null;
            if (null === $days || $days < 1 || $days > PaymentTerms::MAX_DAYS) {
                $draft->errors['termDays'] = \sprintf('Indiquez un nombre de jours, de 1 à %d.', PaymentTerms::MAX_DAYS);

                return null;
            }
        } else {
            $draft->termDays = '';
        }
        $day = null;
        if ($kind->needsDayOfMonth()) {
            $day = 1 === preg_match('/^\d{1,2}$/', $draft->termDay) ? (int) $draft->termDay : null;
            if (null === $day || $day < 1 || $day > 31) {
                $draft->errors['termDay'] = 'Indiquez le jour du mois, de 1 à 31.';

                return null;
            }
        } else {
            $draft->termDay = '';
        }

        return new PaymentTerms($kind, $days, $day);
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function compact(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }
}
