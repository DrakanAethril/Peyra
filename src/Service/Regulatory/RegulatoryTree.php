<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * The value of a regulatory parameter, as Peyra keeps it: a number, a text, or a structure
 * (a map, a list of brackets) whose leaves are numbers or texts. Pure.
 *
 * **No float ever**: a number is kept as the exact decimal text it was written with (« 6.9 »,
 * « 0.3193 », « 48060 »), and the stored JSON (`RegulatoryValue::$value`) holds that text as a
 * JSON string, so that decoding it can never yield a float. A leaf whose text is a plain decimal
 * is a number; any other text is a text - a date of a value is « 2027-01-01 », a text.
 *
 * @phpstan-type Node string|bool|null|array<array-key, mixed>
 */
final class RegulatoryTree
{
    private const string NUMBER = '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/';

    /**
     * A value read from a millésime file (Symfony YAML, dates parsed as DateTime) as a tree:
     * integers and floats become their exact decimal text, a date its ISO day.
     *
     * @return Node
     *
     * @throws \InvalidArgumentException on anything that is neither a scalar, a date nor an array
     */
    public static function fromYaml(mixed $value): string|bool|array|null
    {
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            return self::floatText($value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (\is_string($value) || \is_bool($value) || null === $value) {
            return $value;
        }
        if (\is_array($value)) {
            $tree = [];
            foreach ($value as $name => $child) {
                $tree[$name] = self::fromYaml($child);
            }

            return $tree;
        }

        throw new \InvalidArgumentException(\sprintf('A regulatory value holds numbers, texts, dates, maps and lists, not %s.', get_debug_type($value)));
    }

    /** @param Node $tree */
    public static function encode(string|bool|array|null $tree): string
    {
        return json_encode($tree, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * The stored text back as a tree; it was written by encode(), so it holds no JSON number.
     *
     * @return Node
     */
    public static function decode(string $json): string|bool|array|null
    {
        return self::fromJson(json_decode($json, true, 64, \JSON_THROW_ON_ERROR));
    }

    /**
     * Every leaf, depth first, in the order the structure is written.
     *
     * @param Node $tree
     *
     * @return list<RegulatoryLeaf>
     */
    public static function leaves(string|bool|array|null $tree): array
    {
        $leaves = [];
        self::collect($tree, [], $leaves);

        return $leaves;
    }

    /**
     * The same structure with its leaves replaced, in the order of leaves().
     *
     * @param Node                   $tree
     * @param list<string|bool|null> $values one per leaf
     *
     * @return Node
     */
    public static function withLeaves(string|bool|array|null $tree, array $values): string|bool|array|null
    {
        if (\count($values) !== \count(self::leaves($tree))) {
            throw new \InvalidArgumentException('One value per leaf of the structure.');
        }
        $index = 0;

        return self::replace($tree, $values, $index);
    }

    public static function isNumber(string|bool|null $leaf): bool
    {
        return \is_string($leaf) && 1 === preg_match(self::NUMBER, $leaf);
    }

    /** @param Node $tree */
    public static function isStructure(string|bool|array|null $tree): bool
    {
        return \is_array($tree);
    }

    /**
     * The node at a path (map keys, list indexes), or null when the path leads nowhere.
     *
     * @param Node                 $tree
     * @param list<int|string>     $path
     *
     * @return Node
     */
    public static function at(string|bool|array|null $tree, array $path): string|bool|array|null
    {
        $node = $tree;
        foreach ($path as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }
            $node = self::fromJson($node[$segment]);
        }

        return $node;
    }

    /**
     * A list of brackets - maps of plain values, such as {from, to, rate} - as a table to print: the
     * keys met, in order, as columns, one row per item, an absent key as null (the open end of the
     * last bracket). Null for any other shape.
     *
     * @param Node $tree
     *
     * @return array{columns: list<string>, rows: list<list<string|bool|null>>}|null
     */
    public static function asTable(string|bool|array|null $tree): ?array
    {
        if (!\is_array($tree) || [] === $tree || !array_is_list($tree)) {
            return null;
        }
        $columns = [];
        foreach ($tree as $item) {
            if (!\is_array($item) || [] === $item || array_is_list($item)) {
                return null;
            }
            foreach ($item as $name => $cell) {
                if (!\is_string($cell) && !\is_bool($cell) && null !== $cell) {
                    return null;
                }
                if (!\in_array((string) $name, $columns, true)) {
                    $columns[] = (string) $name;
                }
            }
        }
        $rows = [];
        foreach ($tree as $item) {
            $row = [];
            foreach ($columns as $column) {
                $cell = \is_array($item) ? ($item[$column] ?? null) : null;
                $row[] = \is_string($cell) || \is_bool($cell) ? $cell : null;
            }
            $rows[] = $row;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /** « 6.9 » for 6.90, « 0.3193 », « 0.00001 »: the shortest decimal that reads back as that float. */
    private static function floatText(float $value): string
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('A regulatory number is finite.');
        }
        try {
            return BigDecimal::of(json_encode($value, \JSON_THROW_ON_ERROR))->strippedOfTrailingZeros()->toString();
        } catch (MathException $error) {
            throw new \InvalidArgumentException($error->getMessage(), previous: $error);
        }
    }

    /** @return Node */
    private static function fromJson(mixed $value): string|bool|array|null
    {
        if (\is_string($value) || \is_bool($value) || null === $value) {
            return $value;
        }
        if (\is_array($value)) {
            $tree = [];
            foreach ($value as $name => $child) {
                $tree[$name] = self::fromJson($child);
            }

            return $tree;
        }
        if (\is_int($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(\sprintf('A stored regulatory value holds no %s.', get_debug_type($value)));
    }

    /**
     * @param Node             $node
     * @param list<int|string> $path
     * @param list<RegulatoryLeaf> $leaves
     */
    private static function collect(string|bool|array|null $node, array $path, array &$leaves): void
    {
        if (!\is_array($node)) {
            $leaves[] = new RegulatoryLeaf($path, $node);

            return;
        }
        foreach ($node as $name => $child) {
            self::collect(self::fromJson($child), [...$path, $name], $leaves);
        }
    }

    /**
     * @param Node                   $node
     * @param list<string|bool|null> $values
     *
     * @return Node
     */
    private static function replace(string|bool|array|null $node, array $values, int &$index): string|bool|array|null
    {
        if (!\is_array($node)) {
            return $values[$index++];
        }
        $tree = [];
        foreach ($node as $name => $child) {
            $tree[$name] = self::replace(self::fromJson($child), $values, $index);
        }

        return $tree;
    }
}
