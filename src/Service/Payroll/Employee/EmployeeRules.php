<?php

declare(strict_types=1);

namespace App\Service\Payroll\Employee;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\Sex;
use App\Enum\Payroll\WorkingTime;
use App\Service\Shared\DateValue;
use App\Service\Shared\DecimalParseError;
use App\Service\Shared\DecimalParser;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * What makes an employee (step 4.2a), pure - the fiche and the demonstration's file go through it:
 *
 * - a matricule of 1 to 10 capitals or digits, the dossier's own; a name, a first name, a sex, a
 *   birth date before the hire; a NIR well formed and agreeing with the sex and the birth date, or
 *   nothing (Nir); a withholding rate from 0 to 100 % or nothing (the neutral rate);
 * - a contract: its type and the day of hire; a fixed-term contract its end and its ground, an
 *   apprenticeship its end and its diploma - the end after the hire; what a type does not name is
 *   dropped;
 * - terms from a day on or after the hire: a job, a status (an apprentice is not « cadre »), the
 *   working time - full time works the establishment's collective monthly time, part time fewer
 *   hours, typed - and a base salary above zero;
 * - an hour of hire (« 8h30 » becomes « 08:30 ») and a trial period as the contract writes it, or
 *   nothing - unless the assistance level asks the DPAE's fields (step 4.2b): then the birth place
 *   and the hour of hire are required.
 */
final class EmployeeRules
{
    /**
     * @param ?string                  $collectiveHours the establishment's monthly time (« 151.67 »), null when no establishment is set
     * @param callable(string): ?string $numberHolder    who else holds a matricule (their name), or null
     * @param bool                      $hiringFields    the DPAE's fields are required (Assistance::requiresHiringDeclarationFields())
     */
    public static function check(EmployeeDraft $draft, ?string $collectiveHours, callable $numberHolder, bool $hiringFields = false): ?EmployeeFields
    {
        $draft->errors = [];
        foreach (['lastName', 'firstName', 'birthPlace', 'trialPeriod', 'nationality', 'street', 'postalCode', 'city', 'fixedTermReason', 'diploma', 'trainingCentre', 'jobTitle', 'classification'] as $field) {
            $draft->{$field} = trim((string) preg_replace('/\s+/u', ' ', (string) $draft->{$field}));
        }
        $draft->registrationNumber = mb_strtoupper((string) preg_replace('/\s+/u', '', $draft->registrationNumber));
        $draft->nir = Nir::normalize($draft->nir);

        if ('' === $draft->registrationNumber) {
            $draft->errors['registrationNumber'] = 'Indiquez le matricule du salarié.';
        } elseif (1 !== preg_match('/^[A-Z0-9]{1,10}$/', $draft->registrationNumber)) {
            $draft->errors['registrationNumber'] = 'Un matricule compte de 1 à 10 lettres ou chiffres, sans accent (S001).';
        } elseif (null !== ($holder = $numberHolder($draft->registrationNumber))) {
            $draft->errors['registrationNumber'] = \sprintf('Le matricule %s est déjà celui de %s.', $draft->registrationNumber, $holder);
        }
        self::text($draft, 'lastName', 80, 'Indiquez le nom du salarié.');
        self::text($draft, 'firstName', 80, 'Indiquez le prénom du salarié.');
        $sex = Sex::tryFrom($draft->sex);
        if (null === $sex) {
            $draft->errors['sex'] = 'Choisissez le sexe du salarié.';
        }
        $birthDate = self::date($draft, 'birthDate', 'Indiquez la date de naissance.');
        self::text($draft, 'birthPlace', 80, $hiringFields ? 'La DPAE indique le lieu de naissance.' : null);
        self::text($draft, 'nationality', 40, 'Indiquez la nationalité.');
        if ('' !== $draft->nir && null !== ($fault = Nir::fault($draft->nir, $sex, $birthDate))) {
            $draft->errors['nir'] = $fault;
        }
        self::text($draft, 'street', 120, null);
        self::text($draft, 'postalCode', 10, null);
        self::text($draft, 'city', 80, null);
        $withholding = self::withholding($draft);

        $type = ContractType::tryFrom($draft->contractType);
        if (null === $type) {
            $draft->errors['contractType'] = 'Choisissez le type de contrat.';
        }
        $hiredOn = self::date($draft, 'hiredOn', 'Indiquez la date d’entrée.');
        $hireTime = self::hireTime($draft, $hiringFields);
        self::text($draft, 'trialPeriod', 40, null);
        if (null !== $birthDate && null !== $hiredOn && $birthDate >= $hiredOn) {
            $draft->errors['birthDate'] = 'La date de naissance précède la date d’entrée.';
        }
        $endsOn = null;
        if (null !== $type && $type->hasEnd()) {
            $endsOn = self::date($draft, 'contractEndsOn', ContractType::FixedTerm === $type ? 'Un CDD indique sa date de fin.' : 'Un contrat d’apprentissage indique sa date de fin.');
            if (null !== $endsOn && null !== $hiredOn && $endsOn <= $hiredOn) {
                $draft->errors['contractEndsOn'] = 'La fin du contrat suit la date d’entrée.';
            }
        } else {
            $draft->contractEndsOn = '';
        }
        if (ContractType::FixedTerm === $type) {
            self::text($draft, 'fixedTermReason', 160, 'Un CDD indique son motif de recours (remplacement, accroissement temporaire d’activité…).');
        } else {
            $draft->fixedTermReason = '';
        }
        if (ContractType::Apprenticeship === $type) {
            self::text($draft, 'diploma', 120, 'Indiquez le diplôme préparé.');
            self::text($draft, 'trainingCentre', 120, null);
        } else {
            $draft->diploma = '';
            $draft->trainingCentre = '';
        }

        $termsFrom = self::date($draft, 'termsFrom', 'Indiquez à compter de quel jour ces conditions s’appliquent.');
        if (null !== $termsFrom && null !== $hiredOn && $termsFrom < $hiredOn) {
            $draft->errors['termsFrom'] = 'Les conditions du contrat s’appliquent à compter de la date d’entrée au plus tôt.';
        }
        self::text($draft, 'jobTitle', 80, 'Indiquez l’emploi occupé.');
        self::text($draft, 'classification', 120, null);
        $executive = match ($draft->status) {
            'executive' => true,
            'non_executive' => false,
            default => null,
        };
        if (null === $executive) {
            $draft->errors['status'] = 'Choisissez le statut : cadre ou non-cadre.';
        } elseif ($executive && ContractType::Apprenticeship === $type) {
            $draft->errors['status'] = 'Un apprenti est non-cadre.';
        }
        $hours = self::hours($draft, $collectiveHours);
        if (null === $draft->baseSalary) {
            $draft->errors['baseSalary'] = 'Indiquez le salaire de base mensuel.';
        } elseif ($draft->baseSalary <= 0) {
            $draft->errors['baseSalary'] = 'Un salaire de base est supérieur à zéro.';
        }

        if ([] !== $draft->errors || null === $sex || null === $birthDate || null === $type || null === $hiredOn || null === $termsFrom
            || null === $executive || null === $hours || null === $draft->baseSalary) {
            return null;
        }

        return new EmployeeFields(
            $draft->registrationNumber,
            $draft->lastName,
            $draft->firstName,
            $sex,
            $birthDate,
            self::orNull($draft->birthPlace),
            $draft->nationality,
            self::orNull($draft->nir),
            self::orNull($draft->street),
            self::orNull($draft->postalCode),
            self::orNull($draft->city),
            $withholding,
            $type,
            $hiredOn,
            $hireTime,
            self::orNull($draft->trialPeriod),
            $endsOn,
            self::orNull($draft->fixedTermReason),
            self::orNull($draft->diploma),
            self::orNull($draft->trainingCentre),
            $termsFrom,
            $draft->jobTitle,
            $draft->classification,
            $executive,
            $hours[0],
            $hours[1],
            Money::cents($draft->baseSalary),
        );
    }

    /** @return ?array{0: WorkingTime, 1: string} the working time and the monthly hours as an exact decimal text */
    private static function hours(EmployeeDraft $draft, ?string $collectiveHours): ?array
    {
        $workingTime = WorkingTime::tryFrom($draft->workingTime);
        if (null === $workingTime) {
            $draft->errors['workingTime'] = 'Choisissez le temps de travail.';

            return null;
        }
        if (null === $collectiveHours) {
            $draft->errors['workingTime'] = 'Renseignez d’abord l’horaire collectif de l’établissement (Paramètres de paie › Établissement).';

            return null;
        }
        if (WorkingTime::FullTime === $workingTime) {
            $draft->monthlyHours = $collectiveHours;

            return [$workingTime, $collectiveHours];
        }
        if ('' === trim($draft->monthlyHours)) {
            $draft->errors['monthlyHours'] = 'Un temps partiel indique son horaire mensuel.';

            return null;
        }
        try {
            $hours = DecimalParser::parse($draft->monthlyHours);
        } catch (DecimalParseError $error) {
            $draft->errors['monthlyHours'] = $error->getMessage();

            return null;
        }
        if ($hours->strippedOfTrailingZeros()->getScale() > 2) {
            $draft->errors['monthlyHours'] = '2 décimales au plus.';

            return null;
        }
        if (!$hours->isPositive() || $hours->isGreaterThanOrEqualTo(BigDecimal::of($collectiveHours))) {
            $draft->errors['monthlyHours'] = \sprintf('Un temps partiel travaille moins que l’horaire collectif (%s h par mois).', str_replace('.', ',', $collectiveHours));

            return null;
        }
        $text = (string) $hours->strippedOfTrailingZeros();
        $draft->monthlyHours = $text;

        return [$workingTime, $text];
    }

    /** « 8h », « 8h30 », « 8:30 », « 08:30 » → « 08:30 »; empty: null (required when the DPAE's fields are). */
    private static function hireTime(EmployeeDraft $draft, bool $required): ?string
    {
        $typed = mb_strtolower(trim($draft->hireTime));
        if ('' === $typed) {
            if ($required) {
                $draft->errors['hireTime'] = 'La DPAE indique l’heure d’embauche.';
            }

            return null;
        }
        if (1 !== preg_match('/^(\d{1,2})\s*[h:]\s*(\d{2})?$/', $typed, $match) || (int) $match[1] > 23 || (int) ($match[2] ?? '0') > 59) {
            $draft->errors['hireTime'] = 'Une heure s’écrit 08:30 ou 8h30.';

            return null;
        }
        $draft->hireTime = \sprintf('%02d:%02d', (int) $match[1], (int) ($match[2] ?? '0'));

        return $draft->hireTime;
    }

    private static function withholding(EmployeeDraft $draft): ?string
    {
        $typed = trim($draft->withholdingRate);
        if ('' === $typed) {
            return null;
        }
        try {
            $rate = DecimalParser::parse($typed);
        } catch (DecimalParseError $error) {
            $draft->errors['withholdingRate'] = $error->getMessage();

            return null;
        }
        if ($rate->isNegative() || $rate->isGreaterThan(100) || $rate->strippedOfTrailingZeros()->getScale() > 2) {
            $draft->errors['withholdingRate'] = 'Un taux de prélèvement est compris entre 0 et 100 %, deux décimales au plus ; laissez vide si aucun taux n’a été transmis.';

            return null;
        }

        return (string) $rate->strippedOfTrailingZeros();
    }

    private static function text(EmployeeDraft $draft, string $field, int $max, ?string $missing): void
    {
        $value = (string) $draft->{$field};
        if ('' === $value && null !== $missing) {
            $draft->errors[$field] = $missing;
        } elseif (mb_strlen($value) > $max) {
            $draft->errors[$field] = \sprintf('%d caractères au plus.', $max);
        }
    }

    private static function date(EmployeeDraft $draft, string $field, string $missing): ?\DateTimeImmutable
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

    private static function orNull(string $text): ?string
    {
        return '' === $text ? null : $text;
    }
}
