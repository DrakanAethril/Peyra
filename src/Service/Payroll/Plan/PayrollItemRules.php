<?php

declare(strict_types=1);

namespace App\Service\Payroll\Plan;

use App\Entity\Payroll\PayrollBody;
use App\Entity\Payroll\PayrollRate;
use App\Enum\Payroll\HeadcountCondition;
use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollCeiling;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollRateSource;
use App\Enum\Payroll\PayrollStatusCondition;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\SalaryCondition;
use App\Service\Shared\AmountParseError;
use App\Service\Shared\AmountParser;
use App\Service\Shared\DecimalParseError;
use App\Service\Shared\DecimalParser;
use Brick\Math\BigDecimal;

/**
 * What makes an item of the plan de paie (lot 4, step 4.1), field by field - pure, shared by the
 * screen (PayrollItemSheet, which adds what reads the database: a code already taken, the body, a
 * regulatory value that does not read) and the standard plan's installer:
 *
 * - a code of four digits, a label, a family, a place in the payslip (the code's number by default);
 * - a base the family knows (PayrollItemKind::bases()), cut by a ceiling only when it is the gross
 *   salary; only an element of gross pay or a contribution comes off what it adds to, and only an
 *   element of gross pay that adds is a benefit in kind;
 * - each side's rate from a source that side, family and base accept
 *   (PayrollRateSource::allowedOnEmployeeSide() / allowedOnEmployerSide()), with what the source
 *   needs - a regulatory key of the social domain and its path, a percent above 0 up to 100 with
 *   four decimals at most, an amount;
 * - a contribution has a rate on one side at least, its body and its line of the clarified payslip;
 *   nothing else has either; what is not entered as an amount has its rate;
 * - its account in the payroll's entry (step 4.5), digits only, or none for the default.
 */
final class PayrollItemRules
{
    private const string KEY = '/^social(\.[a-z0-9_]+)+$/';
    private const string PATH = '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/';
    /** An account number of the dossier's chart: digits, ten at most. */
    public const string ACCOUNT = '/^[1-8]\d{2,9}$/';

    /**
     * @param PayrollBody|null $body the body the draft names, found by the caller (null when it names none)
     *
     * @phpstan-impure it writes the draft's errors
     */
    public static function check(PayrollItemDraft $draft, ?PayrollBody $body): ?PayrollItemSpec
    {
        $draft->errors = [];
        $draft->code = trim($draft->code);
        $draft->label = trim((string) preg_replace('/\s+/u', ' ', $draft->label));
        $draft->sortOrder = trim($draft->sortOrder);
        foreach (['employeeKey', 'employeePath', 'employerKey', 'employerPath', 'account'] as $field) {
            $draft->{$field} = trim($draft->{$field});
        }

        if (1 !== preg_match('/^\d{4}$/', $draft->code)) {
            $draft->errors['code'] = '' === $draft->code ? 'Indiquez le code de la rubrique.' : 'Un code de rubrique compte quatre chiffres (2200).';
        }
        if ('' === $draft->label) {
            $draft->errors['label'] = 'Indiquez l’intitulé de la rubrique.';
        } elseif (mb_strlen($draft->label) > 80) {
            $draft->errors['label'] = 'Un intitulé de rubrique tient en 80 caractères.';
        }
        $sortOrder = null;
        if ('' === $draft->sortOrder) {
            $sortOrder = isset($draft->errors['code']) ? null : (int) $draft->code;
        } elseif (1 === preg_match('/^\d{1,4}$/', $draft->sortOrder)) {
            $sortOrder = (int) $draft->sortOrder;
        } else {
            $draft->errors['sortOrder'] = 'L’ordre est un nombre de 0 à 9999.';
        }

        $kind = PayrollItemKind::tryFrom($draft->kind);
        if (null === $kind) {
            $draft->errors['kind'] = 'Choisissez la famille de la rubrique.';
        }
        $base = PayrollBase::tryFrom($draft->base);
        if (null === $base) {
            $draft->errors['base'] = 'Choisissez l’assiette de la rubrique.';
        } elseif (null !== $kind && !\in_array($base, $kind->bases(), true)) {
            $draft->errors['base'] = \sprintf('Une rubrique « %s » ne repose pas sur « %s ».', mb_strtolower($kind->label()), mb_strtolower($base->label()));
        }
        $ceiling = PayrollCeiling::tryFrom($draft->ceiling);
        if (null === $ceiling) {
            $draft->errors['ceiling'] = 'Choisissez le plafonnement.';
        } elseif (PayrollCeiling::None !== $ceiling && null !== $base && !$base->takesCeiling()) {
            $draft->errors['ceiling'] = 'Seul le salaire brut se plafonne : choisissez « Sans plafond ».';
        }
        if ($draft->subtracts && null !== $kind && !\in_array($kind, [PayrollItemKind::Gross, PayrollItemKind::Contribution], true)) {
            $draft->errors['subtracts'] = 'Seuls un élément de brut (une absence) et une cotisation (une réduction) viennent en déduction.';
        }
        if ($draft->benefitInKind && null !== $kind && (PayrollItemKind::Gross !== $kind || $draft->subtracts)) {
            $draft->errors['benefitInKind'] = 'Seul un élément de brut qui s’ajoute au salaire est un avantage en nature.';
        }

        $employee = null === $kind || null === $base ? null : self::side($draft, 'employee', $kind, $base);
        $employer = null === $kind || null === $base ? null : self::side($draft, 'employer', $kind, $base);

        $status = PayrollStatusCondition::tryFrom($draft->status);
        if (null === $status) {
            $draft->errors['status'] = 'Choisissez les salariés concernés.';
        }
        $headcount = HeadcountCondition::tryFrom($draft->headcount);
        if (null === $headcount) {
            $draft->errors['headcount'] = 'Choisissez la condition d’effectif.';
        }
        $salary = SalaryCondition::tryFrom($draft->salary);
        if (null === $salary) {
            $draft->errors['salary'] = 'Choisissez la condition de tranche.';
        }

        $line = '' === $draft->line ? null : PayslipLine::tryFrom($draft->line);
        $unknownLine = '' !== $draft->line && null === $line;
        if ($unknownLine) {
            $draft->errors['line'] = 'Choisissez la ligne du bulletin.';
        }
        if (null !== $kind && $kind->isContribution()) {
            if (null !== $employee && null !== $employer && $employee->isNone() && $employer->isNone()) {
                $draft->errors['employeeSource'] = 'Une cotisation a un taux salarial, un taux patronal ou les deux.';
            }
            if (null === $body) {
                $draft->errors['body'] = 'Indiquez l’organisme à qui la cotisation est versée.';
            }
            if (null === $line && !$unknownLine) {
                $draft->errors['line'] = 'Indiquez la ligne du bulletin clarifié qui regroupe la cotisation.';
            }
        } elseif (null !== $kind) {
            if (null !== $body) {
                $draft->errors['body'] = 'Seule une cotisation est versée à un organisme.';
            }
            if (null !== $line) {
                $draft->errors['line'] = 'Seule une cotisation figure sur une ligne du bulletin clarifié.';
            }
            if (null !== $base && null !== $employee && $employee->isNone() && !\in_array($base, [PayrollBase::EnteredAmount, PayrollBase::BenefitsInKind, PayrollBase::EntryExitDays, PayrollBase::Dismissal, PayrollBase::ConventionalBreak], true)) {
                $draft->errors['employeeSource'] = \sprintf('Une rubrique sur « %s » a son taux.', mb_strtolower($base->label()));
            }
        }

        if ('' !== $draft->account && 1 !== preg_match(self::ACCOUNT, $draft->account)) {
            $draft->errors['account'] = 'Un numéro de compte s’écrit en chiffres (641100).';
        }

        if ([] !== $draft->errors || null === $kind || null === $base || null === $ceiling || null === $employee || null === $employer
            || null === $status || null === $headcount || null === $salary || null === $sortOrder) {
            return null;
        }

        return new PayrollItemSpec($draft->code, $draft->label, $kind, $sortOrder, $draft->subtracts, $base, $ceiling, $employee, $employer, $status, $headcount, $salary, $body, $line, $draft->benefitInKind, '' === $draft->account ? null : $draft->account);
    }

    /** @param 'employee'|'employer' $side */
    private static function side(PayrollItemDraft $draft, string $side, PayrollItemKind $kind, PayrollBase $base): ?PayrollRate
    {
        $field = $side.'Source';
        $source = PayrollRateSource::tryFrom($draft->{$side.'Source'});
        if (null === $source) {
            $draft->errors[$field] = 'Choisissez l’origine du taux.';

            return null;
        }
        $allowed = 'employee' === $side ? $source->allowedOnEmployeeSide($kind, $base) : $source->allowedOnEmployerSide($kind, $base);
        if (!$allowed) {
            $draft->errors[$field] = 'employer' === $side && !$kind->isContribution()
                ? 'Seule une cotisation a une part patronale.'
                : \sprintf('« %s » ne convient pas à une rubrique « %s » sur « %s ».', $source->label(), mb_strtolower($kind->label()), mb_strtolower($base->label()));

            return null;
        }

        $key = null;
        $path = null;
        if ($source->needsReference()) {
            $key = (string) $draft->{$side.'Key'};
            $path = (string) $draft->{$side.'Path'};
            if (1 !== preg_match(self::KEY, $key)) {
                $draft->errors[$side.'Key'] = '' === $key ? 'Indiquez la clé de la valeur réglementaire (social.contrib.health).' : 'Une clé de valeur sociale s’écrit social.nom (social.contrib.health).';
            }
            if ('' !== $path && 1 !== preg_match(self::PATH, $path)) {
                $draft->errors[$side.'Path'] = 'Le chemin s’écrit en minuscules, ses parties séparées par des points (employer_rate, 0.employee_rate).';
            }
            $path = '' === $path ? null : $path;
        }
        $percent = null;
        $amount = null;
        $value = trim($draft->{$side.'Value'});
        if ($source->needsPercent()) {
            try {
                $decimal = DecimalParser::parse($value);
                if (!$decimal->isPositive() || $decimal->isGreaterThan(100)) {
                    $draft->errors[$side.'Value'] = 'Un taux est compris entre 0 et 100 %.';
                } elseif ($decimal->strippedOfTrailingZeros()->getScale() > 4) {
                    $draft->errors[$side.'Value'] = 'Un taux a quatre décimales au plus (0,0160).';
                } else {
                    $percent = (string) BigDecimal::of($decimal)->strippedOfTrailingZeros();
                }
            } catch (DecimalParseError $error) {
                $draft->errors[$side.'Value'] = $error->getMessage();
            }
        } elseif ($source->needsAmount()) {
            try {
                $amount = AmountParser::parse($value);
                if ($amount->isNegative()) {
                    $draft->errors[$side.'Value'] = 'Un montant de rubrique est positif.';
                    $amount = null;
                }
            } catch (AmountParseError $error) {
                $draft->errors[$side.'Value'] = $error->getMessage();
            }
        }
        if (isset($draft->errors[$side.'Key']) || isset($draft->errors[$side.'Path']) || isset($draft->errors[$side.'Value'])) {
            return null;
        }

        return new PayrollRate($source, $key, $path, $percent, $amount);
    }
}
