<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Entity\Dossier\Company;
use App\Enum\Dossier\LegalForm;
use App\Enum\Dossier\ProfitTaxRegime;
use App\Enum\Dossier\VatRegime;
use App\Service\Shared\Money;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/company.yaml` read and checked whole: the company, the exercises, the date the
 * dossier starts at. A fault names its key and installs nothing.
 *
 * @phpstan-type DemoExercise array{code: string, start: \DateTimeImmutable, end: \DateTimeImmutable, closed: bool}
 */
final readonly class DemoCompanyFile
{
    /** @param list<DemoExercise> $exercises */
    private function __construct(public Company $company, public array $exercises, public \DateTimeImmutable $dossierDate)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException($path.' : un dictionnaire est attendu.');
        }
        $company = self::map($data, 'company');
        $vatNumber = self::string($company, 'vat_number', true);
        $nafCode = self::string($company, 'naf_code', true);
        $street2 = self::string($company, 'street2', true);

        $exercises = [];
        $list = $data['exercises'] ?? null;
        if (!\is_array($list) || [] === $list) {
            throw new \InvalidArgumentException('exercises : une liste d’exercices est attendue.');
        }
        foreach ($list as $index => $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException(\sprintf('exercises[%s] : un dictionnaire est attendu.', $index));
            }
            $exercises[] = [
                'code' => self::string($entry, 'code'),
                'start' => self::date($entry, 'start'),
                'end' => self::date($entry, 'end'),
                'closed' => true === ($entry['closed'] ?? false),
            ];
        }

        return new self(
            new Company(
                self::string($company, 'denomination'),
                LegalForm::from(self::string($company, 'legal_form')),
                Money::euros(self::string($company, 'capital')),
                self::string($company, 'siret'),
                '' === $vatNumber ? null : $vatNumber,
                '' === $nafCode ? null : $nafCode,
                self::string($company, 'activity'),
                self::string($company, 'street'),
                '' === $street2 ? null : $street2,
                self::string($company, 'postal_code'),
                self::string($company, 'city'),
                VatRegime::from(self::string($company, 'vat_regime')),
                ProfitTaxRegime::from(self::string($company, 'profit_tax_regime')),
            ),
            $exercises,
            self::date($data, 'dossier_date'),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            throw new \InvalidArgumentException($key.' : un dictionnaire est attendu.');
        }

        return $value;
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $key, bool $optional = false): string
    {
        $value = $data[$key] ?? null;
        if (null === $value && $optional) {
            return '';
        }
        if (!\is_string($value) && !\is_int($value)) {
            throw new \InvalidArgumentException($key.' : un texte est attendu.');
        }

        return trim((string) $value);
    }

    /** @param array<mixed> $data */
    private static function date(array $data, string $key): \DateTimeImmutable
    {
        $text = self::string($data, $key);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (false === $date || $date->format('Y-m-d') !== $text) {
            throw new \InvalidArgumentException($key.' : une date AAAA-MM-JJ est attendue.');
        }

        return $date;
    }
}
