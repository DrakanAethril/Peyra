<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Enum\Payroll\PayrollBodyKind;
use App\Service\Payroll\Plan\PayrollItemDraft;
use App\Service\Payroll\Plan\PayrollPlanFile;
use App\Service\Payroll\Settings\PayrollEstablishmentDraft;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/payroll.yaml` read and checked for its shape (step 4.1): the establishment as a
 * PayrollEstablishmentDraft, the collecting bodies, and the dossier's own rubriques (read by
 * PayrollPlanFile) - each checked later by the rules of its fiche.
 */
final readonly class DemoPayrollFile
{
    /**
     * @param list<array{kind: PayrollBodyKind, name: string, reference: string}> $bodies
     * @param list<array{draft: PayrollItemDraft, body: ?PayrollBodyKind}>         $items
     */
    private function __construct(public PayrollEstablishmentDraft $establishment, public array $bodies, public array $items)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        if (!\is_array($data) || !\is_array($data['establishment'] ?? null) || !\is_array($data['bodies'] ?? null) || !\is_array($data['items'] ?? null)) {
            throw new \InvalidArgumentException('payroll.yaml : « establishment », « bodies » et « items » sont attendus.');
        }
        $establishment = new PayrollEstablishmentDraft();
        foreach (['headcount' => 'headcount', 'work_accident_rate' => 'workAccidentRate', 'mobility_rate' => 'mobilityRate', 'monthly_hours' => 'monthlyHours'] as $field => $property) {
            $value = $data['establishment'][$field] ?? null;
            if (null === $value && 'mobility_rate' === $field) {
                continue;
            }
            if (!\is_string($value)) {
                throw new \InvalidArgumentException('payroll.yaml : establishment.'.$field.' : un nombre entre guillemets est attendu.');
            }
            $establishment->{$property} = $value;
        }
        $bodies = [];
        foreach ($data['bodies'] as $index => $body) {
            $kind = \is_array($body) ? PayrollBodyKind::tryFrom(\is_string($body['kind'] ?? null) ? $body['kind'] : '') : null;
            $name = \is_array($body) ? ($body['name'] ?? null) : null;
            $reference = \is_array($body) ? ($body['reference'] ?? '') : null;
            if (null === $kind || !\is_string($name) || !\is_string($reference)) {
                throw new \InvalidArgumentException('payroll.yaml : bodies['.$index.'] : {kind, name, reference} est attendu.');
            }
            $bodies[] = ['kind' => $kind, 'name' => $name, 'reference' => $reference];
        }

        return new self($establishment, $bodies, PayrollPlanFile::items($data['items'], 'payroll.yaml'));
    }
}
