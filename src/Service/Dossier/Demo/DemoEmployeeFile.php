<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Service\Payroll\Employee\EmployeeDraft;
use App\Service\Shared\Money;
use Brick\Math\RoundingMode;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/employees.yaml` read whole (step 4.2a): each employee as the drafts a person would
 * have typed in the fiche - the first at the hire, then one per later terms, from its own day.
 * DemoInstaller passes each through EmployeeSheet, the form's own door. A fault of shape names its
 * entry and installs nothing.
 */
final readonly class DemoEmployeeFile
{
    private const array TEXTS = [
        'number' => 'registrationNumber', 'last_name' => 'lastName', 'first_name' => 'firstName', 'sex' => 'sex',
        'birth_date' => 'birthDate', 'birth_place' => 'birthPlace', 'nationality' => 'nationality', 'nir' => 'nir',
        'street' => 'street', 'postal_code' => 'postalCode', 'city' => 'city', 'withholding_rate' => 'withholdingRate',
    ];

    private const array CONTRACT = [
        'type' => 'contractType', 'hired_on' => 'hiredOn', 'hire_time' => 'hireTime', 'trial' => 'trialPeriod', 'ends_on' => 'contractEndsOn', 'reason' => 'fixedTermReason',
        'diploma' => 'diploma', 'training_centre' => 'trainingCentre',
    ];

    private const array TERMS = [
        'from' => 'termsFrom', 'job' => 'jobTitle', 'classification' => 'classification', 'status' => 'status',
        'time' => 'workingTime', 'hours' => 'monthlyHours',
    ];

    /** @param list<list<EmployeeDraft>> $employees each employee's drafts, the hire first */
    private function __construct(public array $employees)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['employees'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « employees » est attendue.');
        }
        $employees = [];
        foreach ($list as $index => $entry) {
            $where = 'employees['.$index.']';
            if (!\is_array($entry) || !\is_array($entry['contract'] ?? null) || !\is_array($entry['terms'] ?? null) || [] === $entry['terms'] || !array_is_list($entry['terms'])) {
                throw new \InvalidArgumentException($where.' : un salarié avec « contract » et une liste « terms » est attendu.');
            }
            $base = new EmployeeDraft();
            foreach ($entry as $key => $value) {
                if ('contract' === $key || 'terms' === $key) {
                    continue;
                }
                if (!\is_string($key) || !isset(self::TEXTS[$key]) || !\is_string($value)) {
                    throw new \InvalidArgumentException(\sprintf('%s.%s : clé inconnue ou valeur qui n’est pas un texte.', $where, \is_string($key) ? $key : (string) json_encode($key)));
                }
                $base->{self::TEXTS[$key]} = $value;
            }
            self::fill($base, $entry['contract'], self::CONTRACT, $where.'.contract');
            $drafts = [];
            foreach ($entry['terms'] as $rank => $terms) {
                if (!\is_array($terms)) {
                    throw new \InvalidArgumentException(\sprintf('%s.terms[%d] : un dictionnaire est attendu.', $where, $rank));
                }
                $draft = clone $base;
                $salary = $terms['salary'] ?? null;
                unset($terms['salary']);
                self::fill($draft, $terms, self::TERMS, \sprintf('%s.terms[%d]', $where, $rank));
                if (!\is_string($salary) || 1 !== preg_match('/^\d+\.\d{2}$/', $salary)) {
                    throw new \InvalidArgumentException(\sprintf('%s.terms[%d].salary : un montant entre guillemets est attendu (« 2400.00 »).', $where, $rank));
                }
                $draft->baseSalary = Money::euros($salary, RoundingMode::Unnecessary)->cents;
                $drafts[] = $draft;
            }
            $employees[] = $drafts;
        }

        return new self($employees);
    }

    /**
     * @param array<mixed>          $values
     * @param array<string, string> $map    YAML key → draft property
     */
    private static function fill(EmployeeDraft $draft, array $values, array $map, string $where): void
    {
        foreach ($values as $key => $value) {
            if (!\is_string($key) || !isset($map[$key]) || !\is_string($value)) {
                throw new \InvalidArgumentException(\sprintf('%s.%s : clé inconnue ou valeur qui n’est pas un texte.', $where, \is_string($key) ? $key : (string) json_encode($key)));
            }
            $draft->{$map[$key]} = $value;
        }
    }
}
