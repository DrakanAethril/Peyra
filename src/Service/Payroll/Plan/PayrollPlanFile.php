<?php

declare(strict_types=1);

namespace App\Service\Payroll\Plan;

use App\Enum\Payroll\PayrollBodyKind;
use Symfony\Component\Yaml\Yaml;

/**
 * A list of plan de paie items written in YAML - the standard plan (`fixtures/payroll/
 * standard-plan.yaml`) and the demonstration's own items - read and checked for its shape. Each
 * item becomes a PayrollItemDraft that PayrollItemRules checks like the fiche, plus the kind of
 * body a contribution goes to (the installer finds the dossier's body of that kind):
 *
 *     - code: '2200'                      # four digits, in quotes
 *       label: Vieillesse plafonnée
 *       kind: contribution                # PayrollItemKind
 *       order: 2200                       # optional: the code's number
 *       subtracts: false                  # optional
 *       benefit_in_kind: false            # optional: an element of gross pay received in kind
 *       base: gross                       # PayrollBase
 *       ceiling: tranche_1                # optional: none
 *       employee: {source: regulatory, key: social.contrib.old_age_capped, path: employee_rate}
 *       employer: {source: fixed, value: '1.5'}   # optional: none; value is a percent or euros
 *       status: all                       # optional, PayrollStatusCondition
 *       headcount: all                    # optional, HeadcountCondition
 *       salary: always                    # optional, SalaryCondition
 *       body: urssaf                      # a contribution's: PayrollBodyKind
 *       line: social_security_capped      # a contribution's: PayslipLine
 *       account: '641200'                 # optional: its account in the payroll's entry, else PayrollAccounts' default
 */
final class PayrollPlanFile
{
    private const array FIELDS = ['code', 'label', 'kind', 'order', 'subtracts', 'benefit_in_kind', 'base', 'ceiling', 'employee', 'employer', 'status', 'headcount', 'salary', 'body', 'line', 'account'];

    /**
     * The standard plan's file: its default body names by kind and its items.
     *
     * @return array{bodies: array<string, string>, items: list<array{draft: PayrollItemDraft, body: ?PayrollBodyKind}>}
     */
    public static function readStandard(string $path): array
    {
        $data = Yaml::parseFile($path);
        if (!\is_array($data) || !\is_array($data['bodies'] ?? null) || !\is_array($data['items'] ?? null)) {
            throw new \InvalidArgumentException(basename($path).' : « bodies » et « items » sont attendus.');
        }
        $bodies = [];
        foreach ($data['bodies'] as $kind => $name) {
            if (null === PayrollBodyKind::tryFrom((string) $kind) || !\is_string($name) || '' === $name) {
                throw new \InvalidArgumentException(basename($path).' : bodies.'.$kind.' : un type d’organisme et son nom sont attendus.');
            }
            $bodies[(string) $kind] = $name;
        }

        return ['bodies' => $bodies, 'items' => self::items($data['items'], basename($path))];
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<array{draft: PayrollItemDraft, body: ?PayrollBodyKind}>
     */
    public static function items(array $list, string $where): array
    {
        if (!array_is_list($list)) {
            throw new \InvalidArgumentException($where.' : une liste de rubriques est attendue.');
        }
        $items = [];
        foreach ($list as $index => $entry) {
            $at = $where.' : items['.$index.']';
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException($at.' : un dictionnaire est attendu.');
            }
            foreach (array_keys($entry) as $field) {
                if (!\in_array($field, self::FIELDS, true)) {
                    throw new \InvalidArgumentException(\sprintf('%s : champ inconnu « %s ».', $at, $field));
                }
            }
            $draft = new PayrollItemDraft();
            $draft->code = self::text($entry, 'code', $at);
            $draft->label = self::text($entry, 'label', $at);
            $draft->kind = self::text($entry, 'kind', $at);
            $order = $entry['order'] ?? null;
            if (null !== $order && !\is_int($order)) {
                throw new \InvalidArgumentException($at.'.order : un nombre entier est attendu.');
            }
            $draft->sortOrder = null === $order ? '' : (string) $order;
            $subtracts = $entry['subtracts'] ?? false;
            if (!\is_bool($subtracts)) {
                throw new \InvalidArgumentException($at.'.subtracts : true ou false est attendu.');
            }
            $draft->subtracts = $subtracts;
            $inKind = $entry['benefit_in_kind'] ?? false;
            if (!\is_bool($inKind)) {
                throw new \InvalidArgumentException($at.'.benefit_in_kind : true ou false est attendu.');
            }
            $draft->benefitInKind = $inKind;
            $draft->base = self::text($entry, 'base', $at);
            $draft->ceiling = self::text($entry, 'ceiling', $at, 'none');
            [$draft->employeeSource, $draft->employeeKey, $draft->employeePath, $draft->employeeValue] = self::side($entry, 'employee', $at);
            [$draft->employerSource, $draft->employerKey, $draft->employerPath, $draft->employerValue] = self::side($entry, 'employer', $at);
            $draft->status = self::text($entry, 'status', $at, 'all');
            $draft->headcount = self::text($entry, 'headcount', $at, 'all');
            $draft->salary = self::text($entry, 'salary', $at, 'always');
            $draft->line = self::text($entry, 'line', $at, '');
            $draft->account = self::text($entry, 'account', $at, '');
            $bodyKind = self::text($entry, 'body', $at, '');
            $body = '' === $bodyKind ? null : (PayrollBodyKind::tryFrom($bodyKind) ?? throw new \InvalidArgumentException($at.'.body : type d’organisme inconnu « '.$bodyKind.' ».'));
            $items[] = ['draft' => $draft, 'body' => $body];
        }

        return $items;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array{string, string, string, string}
     */
    private static function side(array $entry, string $side, string $at): array
    {
        $rate = $entry[$side] ?? null;
        if (null === $rate) {
            return ['none', '', '', ''];
        }
        if (!\is_array($rate)) {
            throw new \InvalidArgumentException($at.'.'.$side.' : un dictionnaire {source, key, path, value} est attendu.');
        }
        foreach (array_keys($rate) as $field) {
            if (!\in_array($field, ['source', 'key', 'path', 'value'], true)) {
                throw new \InvalidArgumentException(\sprintf('%s.%s : champ inconnu « %s ».', $at, $side, $field));
            }
        }

        return [self::text($rate, 'source', $at.'.'.$side), self::text($rate, 'key', $at.'.'.$side, ''), self::text($rate, 'path', $at.'.'.$side, ''), self::text($rate, 'value', $at.'.'.$side, '')];
    }

    /** @param array<mixed> $entry */
    private static function text(array $entry, string $field, string $at, ?string $default = null): string
    {
        $value = $entry[$field] ?? $default;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('%s.%s : un texte est attendu (entre guillemets s’il est fait de chiffres).', $at, $field));
        }

        return $value;
    }
}
