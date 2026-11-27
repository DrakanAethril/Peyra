<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Service\Accounting\Template\TemplateDraft;
use App\Service\Accounting\Template\TemplateLineDraft;
use App\Service\Shared\Money;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/templates.yaml` read and checked for its shape: the demonstration's entry
 * templates, each a TemplateDraft that TemplateRules checks like the fiche (a fault there names the
 * template and installs nothing). Fixed amounts are euros as text, read exactly.
 */
final readonly class DemoTemplateFile
{
    /** @param list<TemplateDraft> $drafts */
    private function __construct(public array $drafts)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['templates'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « templates » est attendue.');
        }

        $drafts = [];
        foreach ($list as $index => $entry) {
            $where = 'templates['.$index.']';
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException($where.' : un dictionnaire est attendu.');
            }
            $draft = new TemplateDraft();
            $draft->code = self::text($entry, 'code', $where);
            $draft->label = self::text($entry, 'label', $where);
            $draft->journal = self::text($entry, 'journal', $where);
            $draft->base = self::optional($entry, 'base', $where) ?? '';
            $draft->rate = self::optional($entry, 'rate', $where) ?? '';
            $lines = $entry['lines'] ?? null;
            if (!\is_array($lines) || !array_is_list($lines)) {
                throw new \InvalidArgumentException($where.'.lines : une liste est attendue.');
            }
            foreach ($lines as $position => $line) {
                $at = $where.'.lines['.$position.']';
                if (!\is_array($line)) {
                    throw new \InvalidArgumentException($at.' : un dictionnaire est attendu.');
                }
                $fixed = self::optional($line, 'fixed', $at);
                if (null !== $fixed && 1 !== preg_match('/^\d+\.\d{2}$/', $fixed)) {
                    throw new \InvalidArgumentException($at.'.fixed : un montant « 1234.56 » entre guillemets est attendu.');
                }
                $draft->lines[] = new TemplateLineDraft(
                    self::text($line, 'account', $at),
                    self::optional($line, 'third_party', $at) ?? '',
                    self::optional($line, 'label', $at) ?? '',
                    self::text($line, 'side', $at),
                    self::text($line, 'amount', $at),
                    null === $fixed ? null : Money::euros($fixed)->cents,
                );
            }
            $drafts[] = $draft;
        }

        return new self($drafts);
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
