<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Enum\Accounting\JournalType;
use App\Service\Accounting\Chart\AccountNumber;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/accounts.yaml` and `journals.yaml` read and checked whole: the accounts the
 * demonstration dossier adds to (or names differently in) the reference chart, and its journals.
 * A fault names its key and installs nothing.
 *
 * @phpstan-type DemoAccount array{number: string, label: string}
 * @phpstan-type DemoJournal array{code: string, label: string, type: JournalType, counterpart: ?string}
 */
final readonly class DemoChartFile
{
    /**
     * @param list<DemoAccount> $accounts
     * @param list<DemoJournal> $journals
     */
    private function __construct(public array $accounts, public array $journals)
    {
    }

    public static function read(string $directory): self
    {
        $accounts = [];
        foreach (self::list($directory.'/accounts.yaml', 'accounts') as $index => $entry) {
            $number = self::text($entry, 'number', 'accounts['.$index.']');
            if (null !== AccountNumber::fault($number)) {
                throw new \InvalidArgumentException(\sprintf('accounts[%d].number : %s', $index, AccountNumber::fault($number)));
            }
            $accounts[] = ['number' => $number, 'label' => self::text($entry, 'label', 'accounts['.$index.']')];
        }
        $journals = [];
        foreach (self::list($directory.'/journals.yaml', 'journals') as $index => $entry) {
            $where = 'journals['.$index.']';
            $type = JournalType::tryFrom(self::text($entry, 'type', $where)) ?? throw new \InvalidArgumentException($where.'.type : type de journal inconnu.');
            $counterpart = $entry['counterpart'] ?? null;
            if (null !== $counterpart && !\is_string($counterpart)) {
                throw new \InvalidArgumentException($where.'.counterpart : un numéro de compte entre guillemets est attendu.');
            }
            $journals[] = ['code' => self::text($entry, 'code', $where), 'label' => self::text($entry, 'label', $where), 'type' => $type, 'counterpart' => $counterpart];
        }

        return new self($accounts, $journals);
    }

    /** @return list<array<mixed>> */
    private static function list(string $path, string $key): array
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data[$key] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « '.$key.' » est attendue.');
        }
        $entries = [];
        foreach ($list as $index => $entry) {
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException(\sprintf('%s[%d] : un dictionnaire est attendu.', $key, $index));
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param array<mixed> $entry */
    private static function text(array $entry, string $key, string $where): string
    {
        $value = $entry[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException($where.'.'.$key.' : un texte est attendu.');
        }

        return trim($value);
    }
}
