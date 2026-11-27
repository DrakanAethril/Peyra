<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Enum\Accounting\EntryOrigin;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Shared\Money;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/entries.yaml` read and checked for its shape: the demonstration's entries, each
 * an EntryDraft the Ledger checks like any other (a fault there names the entry and installs
 * nothing), with its origin and whether it is validated. Amounts are euros as text, read exactly.
 *
 * @phpstan-type DemoEntry array{draft: EntryDraft, origin: EntryOrigin, validated: bool}
 */
final readonly class DemoEntryFile
{
    /** @param list<DemoEntry> $entries */
    private function __construct(public array $entries)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['entries'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « entries » est attendue.');
        }

        $entries = [];
        foreach ($list as $index => $entry) {
            $where = 'entries['.$index.']';
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException($where.' : un dictionnaire est attendu.');
            }
            $lines = $entry['lines'] ?? null;
            if (!\is_array($lines) || !array_is_list($lines)) {
                throw new \InvalidArgumentException($where.'.lines : une liste est attendue.');
            }
            $drafts = [];
            foreach ($lines as $position => $line) {
                $at = $where.'.lines['.$position.']';
                if (!\is_array($line)) {
                    throw new \InvalidArgumentException($at.' : un dictionnaire est attendu.');
                }
                $drafts[] = new EntryLineDraft(
                    self::text($line, 'account', $at),
                    self::optional($line, 'third_party', $at) ?? '',
                    self::optional($line, 'label', $at) ?? '',
                    self::amount($line, 'debit', $at),
                    self::amount($line, 'credit', $at),
                    self::date($line, 'due', $at, false),
                );
            }
            $origin = EntryOrigin::tryFrom(self::optional($entry, 'origin', $where) ?? 'manual') ?? throw new \InvalidArgumentException($where.'.origin : origine inconnue.');
            $validated = $entry['validated'] ?? false;
            if (!\is_bool($validated)) {
                throw new \InvalidArgumentException($where.'.validated : true ou false est attendu.');
            }
            $entries[] = [
                'draft' => new EntryDraft(self::text($entry, 'journal', $where), self::date($entry, 'date', $where, true), self::text($entry, 'document', $where), self::text($entry, 'label', $where), $drafts),
                'origin' => $origin,
                'validated' => $validated,
            ];
        }

        return new self($entries);
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

    /** @param array<mixed> $entry */
    private static function amount(array $entry, string $key, string $where): Money
    {
        $value = self::optional($entry, $key, $where);
        if (null === $value) {
            return Money::zero();
        }
        if (1 !== preg_match('/^\d+\.\d{2}$/', $value)) {
            throw new \InvalidArgumentException($where.'.'.$key.' : un montant « 1234.56 » entre guillemets est attendu.');
        }

        return Money::euros($value);
    }

    /** @param array<mixed> $entry */
    private static function date(array $entry, string $key, string $where, bool $required): ?\DateTimeImmutable
    {
        $value = self::optional($entry, $key, $where);
        if (null === $value) {
            return $required ? throw new \InvalidArgumentException($where.'.'.$key.' : une date est attendue.') : null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($where.'.'.$key.' : une date AAAA-MM-JJ entre guillemets est attendue.');
        }

        return $date;
    }
}
