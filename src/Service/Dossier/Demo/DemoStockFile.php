<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Entity\Inventory\StockMovement;
use App\Service\Inventory\Stock\StockMovementDraft;
use Symfony\Component\Yaml\Yaml;

/**
 * `fixtures/demo/stock.yaml` read and checked for its shape (step 2.5): the stock movements of the
 * demonstration, each a StockMovementDraft that StockMovements checks and records at the install,
 * as the screen does. Quantities and unit costs are text, read exactly.
 */
final readonly class DemoStockFile
{
    /** @param list<StockMovementDraft> $movements */
    private function __construct(public array $movements)
    {
    }

    public static function read(string $path): self
    {
        $data = Yaml::parseFile($path);
        $list = \is_array($data) ? ($data['movements'] ?? null) : null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \InvalidArgumentException($path.' : une liste « movements » est attendue.');
        }
        $movements = [];
        foreach ($list as $index => $entry) {
            $where = 'movements['.$index.']';
            if (!\is_array($entry)) {
                throw new \InvalidArgumentException($where.' : un dictionnaire est attendu.');
            }
            $draft = new StockMovementDraft();
            $draft->article = self::text($entry, 'article', $where);
            $draft->date = self::text($entry, 'date', $where);
            $draft->kind = self::text($entry, 'kind', $where);
            $draft->quantity = self::text($entry, 'quantity', $where);
            $draft->unitCost = self::optional($entry, 'unit_cost', $where) ?? '';
            $draft->location = self::optional($entry, 'location', $where) ?? StockMovement::MAIN_LOCATION;
            $draft->toLocation = self::optional($entry, 'to', $where) ?? '';
            $draft->label = self::text($entry, 'label', $where);
            $movements[] = $draft;
        }

        return new self($movements);
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
        if (!\is_string($value)) {
            throw new \InvalidArgumentException($where.'.'.$key.' : un texte entre guillemets est attendu.');
        }

        return $value;
    }
}
