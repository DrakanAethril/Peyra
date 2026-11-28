<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Shared\DateValue;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/payslips.yaml` read whole (review 4.7a of lot 4): the months of pay the demonstration
 * validates and closes, in order, each with the elements entered on its payslips by registration number
 * and the rubriques removed from them by hand, whether its entry is posted, and the days its salaries and its contributions are paid. DemoPayroll
 * writes them through the payroll's own doors. A fault of shape names its month.
 */
final readonly class DemoPayslipFile
{
    /**
     * @param list<array{month: \DateTimeImmutable, elements: array<string, PayslipElements>, removed: array<string, list<string>>, post: bool, salariesPaidOn: ?\DateTimeImmutable, contributionsPaidOn: ?\DateTimeImmutable}> $months
     */
    private function __construct(public array $months)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['months'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « months » est attendue.');
        }
        $months = [];
        foreach ($list as $index => $entry) {
            $where = \sprintf('%s : months[%d]', $path, $index);
            $month = \is_array($entry) && \is_string($entry['month'] ?? null) ? DateValue::parse($entry['month'].'-01') : null;
            if (!\is_array($entry) || null === $month) {
                throw new \InvalidArgumentException($where.' : un mois « month » (« 2026-01 ») est attendu.');
            }
            $elements = [];
            $removed = [];
            $byEmployee = $entry['elements'] ?? [];
            if (!\is_array($byEmployee)) {
                throw new \InvalidArgumentException($where.' : « elements » est une table par matricule.');
            }
            foreach ($byEmployee as $number => $fields) {
                $elements[(string) $number] = self::elements(\is_array($fields) ? $fields : [], $where.' : '.$number);
                $codes = \is_array($fields) ? ($fields['removed'] ?? []) : [];
                if (!\is_array($codes) || !array_is_list($codes) || [] !== array_filter($codes, static fn (mixed $code): bool => !\is_string($code))) {
                    throw new \InvalidArgumentException($where.' : '.$number.' : « removed » est une liste de codes de rubrique.');
                }
                if ([] !== $codes) {
                    $removed[(string) $number] = array_values(array_filter($codes, 'is_string'));
                }
            }
            $months[] = [
                'month' => $month,
                'elements' => $elements,
                'removed' => $removed,
                'post' => true === ($entry['post'] ?? false),
                'salariesPaidOn' => self::day($entry['salaries_paid_on'] ?? null, $where),
                'contributionsPaidOn' => self::day($entry['contributions_paid_on'] ?? null, $where),
            ];
        }

        return new self($months);
    }

    /** @param array<array-key, mixed> $fields */
    private static function elements(array $fields, string $where): PayslipElements
    {
        $read = static function (string $kind) use ($fields, $where): array {
            $values = $fields[$kind] ?? [];
            if (!\is_array($values)) {
                throw new \InvalidArgumentException($where.' : « '.$kind.' » est une table par rubrique.');
            }
            $out = [];
            foreach ($values as $code => $value) {
                try {
                    $out[(string) $code] = BigDecimal::of(\is_string($value) ? $value : '');
                } catch (MathException) {
                    throw new \InvalidArgumentException(\sprintf('%s : %s %s : un nombre écrit en texte est attendu.', $where, $kind, $code));
                }
            }

            return $out;
        };

        return new PayslipElements($read('quantities'), array_map(static fn (BigDecimal $amount): Money => Money::euros($amount), $read('amounts')), $read('rates'));
    }

    private static function day(mixed $value, string $where): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        return (\is_string($value) ? DateValue::parse($value) : null) ?? throw new \InvalidArgumentException($where.' : une date « 2026-01-30 » est attendue.');
    }
}
