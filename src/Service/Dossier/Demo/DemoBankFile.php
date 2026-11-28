<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Service\Shared\DateValue;
use App\Service\Shared\Money;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/bank.yaml` read and checked for its shape: the demonstration's assignment rules
 * and its bank statements - a journal, a number, its days, whether it is reconciled, the documents
 * the bank has not passed yet and the bank's lines the books do not hold. What they mean is
 * BankRules', BankStatements' and Pointings' to decide.
 */
final readonly class DemoBankFile
{
    /**
     * @param list<array{pattern: string, account: string, vatRate: string, label: string}> $rules
     * @param list<array{journal: string, number: int, from: \DateTimeImmutable, to: \DateTimeImmutable, reconciled: bool, omit: list<string>, extra: list<array{date: \DateTimeImmutable, label: string, amount: Money}>}> $statements
     */
    private function __construct(public array $rules, public array $statements)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        if (!\is_array($data) || !\is_array($data['rules'] ?? null) || !\is_array($data['statements'] ?? null)) {
            throw new \InvalidArgumentException($path.' : des listes « rules » et « statements » sont attendues.');
        }
        $text = static function (mixed $value, string $where): string {
            if (!\is_string($value) || '' === trim($value)) {
                throw new \InvalidArgumentException($where.' : un texte est attendu.');
            }

            return trim($value);
        };
        $day = static fn (mixed $value, string $where): \DateTimeImmutable => DateValue::parse($text($value, $where)) ?? throw new \InvalidArgumentException($where.' : une date AAAA-MM-JJ est attendue.');

        $rules = [];
        foreach (array_values($data['rules']) as $index => $rule) {
            $where = 'rules['.$index.']';
            if (!\is_array($rule)) {
                throw new \InvalidArgumentException($where.' : un dictionnaire est attendu.');
            }
            $rules[] = [
                'pattern' => $text($rule['pattern'] ?? null, $where.'.pattern'),
                'account' => $text($rule['account'] ?? null, $where.'.account'),
                'vatRate' => isset($rule['vat_rate']) ? $text($rule['vat_rate'], $where.'.vat_rate') : '',
                'label' => isset($rule['label']) ? $text($rule['label'], $where.'.label') : '',
            ];
        }

        $statements = [];
        foreach (array_values($data['statements']) as $index => $statement) {
            $where = 'statements['.$index.']';
            if (!\is_array($statement) || !\is_int($statement['number'] ?? null) || !\is_bool($statement['reconciled'] ?? null)) {
                throw new \InvalidArgumentException($where.' : un numéro entier et « reconciled » sont attendus.');
            }
            $omit = [];
            foreach (\is_array($statement['omit'] ?? null) ? array_values($statement['omit']) : [] as $position => $document) {
                $omit[] = $text($document, $where.'.omit['.$position.']');
            }
            $extra = [];
            foreach (\is_array($statement['extra'] ?? null) ? array_values($statement['extra']) : [] as $position => $line) {
                $at = $where.'.extra['.$position.']';
                if (!\is_array($line)) {
                    throw new \InvalidArgumentException($at.' : un dictionnaire est attendu.');
                }
                $extra[] = ['date' => $day($line['date'] ?? null, $at.'.date'), 'label' => $text($line['label'] ?? null, $at.'.label'), 'amount' => Money::euros($text($line['amount'] ?? null, $at.'.amount'))];
            }
            $statements[] = [
                'journal' => $text($statement['journal'] ?? null, $where.'.journal'),
                'number' => $statement['number'],
                'from' => $day($statement['from'] ?? null, $where.'.from'),
                'to' => $day($statement['to'] ?? null, $where.'.to'),
                'reconciled' => $statement['reconciled'],
                'omit' => $omit,
                'extra' => $extra,
            ];
        }

        return new self($rules, $statements);
    }
}
