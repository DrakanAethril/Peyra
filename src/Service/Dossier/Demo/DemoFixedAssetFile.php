<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Service\Assets\FixedAssetDraft;
use App\Service\Shared\Money;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/fixed_assets.yaml` read and checked for its shape (step 3.4): the
 * demonstration's fixed assets, each a FixedAssetDraft that FixedAssetRules checks like the fiche (a
 * fault there names the asset and installs nothing). Amounts are euros as text, read exactly.
 */
final readonly class DemoFixedAssetFile
{
    /** @param list<FixedAssetDraft> $drafts */
    private function __construct(public array $drafts)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['fixed_assets'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « fixed_assets » est attendue.');
        }
        $drafts = [];
        foreach ($list as $index => $entry) {
            $where = 'fixed_assets['.$index.']';
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException($where.' : un dictionnaire est attendu.');
            }
            $draft = new FixedAssetDraft();
            $draft->code = self::text($entry, 'code', $where);
            $draft->label = self::text($entry, 'label', $where);
            $draft->account = self::text($entry, 'account', $where);
            $draft->depreciationAccount = self::optional($entry, 'depreciation_account', $where) ?? '';
            $draft->supplier = self::optional($entry, 'supplier', $where) ?? '';
            $draft->document = self::optional($entry, 'document', $where) ?? '';
            $draft->acquiredOn = self::text($entry, 'acquired_on', $where);
            $draft->inServiceOn = self::text($entry, 'in_service_on', $where);
            $draft->price = self::cents($entry, 'price', $where);
            $draft->incidentalCosts = self::cents($entry, 'incidental_costs', $where) ?? 0;
            $draft->vat = self::cents($entry, 'vat', $where) ?? 0;
            $draft->residualValue = 0;
            $draft->method = self::text($entry, 'method', $where);
            $years = $entry['years'] ?? null;
            if (null !== $years && !\is_int($years)) {
                throw new \InvalidArgumentException($where.'.years : un nombre entier est attendu.');
            }
            $draft->years = null === $years ? '' : (string) $years;
            $draft->fiscalDeclining = true === ($entry['fiscal_declining'] ?? false);
            $components = $entry['components'] ?? [];
            if (!\is_array($components) || !array_is_list($components)) {
                throw new \InvalidArgumentException($where.'.components : une liste est attendue.');
            }
            foreach ($components as $position => $component) {
                $at = $where.'.components['.$position.']';
                if (!\is_array($component) || !\is_int($component['years'] ?? null)) {
                    throw new \InvalidArgumentException($at.' : un libellé, une valeur et une durée en années sont attendus.');
                }
                $draft->components[] = ['label' => self::text($component, 'label', $at), 'cost' => self::cents($component, 'cost', $at), 'years' => (string) $component['years']];
            }
            $drafts[] = $draft;
        }

        return new self($drafts);
    }

    /** @param array<mixed> $entry */
    private static function cents(array $entry, string $key, string $where): ?int
    {
        $text = self::optional($entry, $key, $where);
        if (null === $text) {
            return null;
        }
        if (1 !== preg_match('/^\d+\.\d{2}$/', $text)) {
            throw new \InvalidArgumentException($where.'.'.$key.' : un montant « 1234.56 » entre guillemets est attendu.');
        }

        return Money::euros($text)->cents;
    }

    /** @param array<mixed> $entry */
    private static function text(array $entry, string $key, string $where): string
    {
        return self::optional($entry, $key, $where) ?? throw new \InvalidArgumentException($where.'.'.$key.' : un texte est attendu.');
    }

    /** @param array<mixed> $entry */
    private static function optional(array $entry, string $key, string $where): ?string
    {
        $value = $entry[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException($where.'.'.$key.' : un texte est attendu.');
        }

        return trim($value);
    }
}
