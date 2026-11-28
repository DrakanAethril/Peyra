<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Entity\Inventory\StockMovement;
use App\Enum\Inventory\StockMovementKind;

/** A stock movement as a person typed it, before StockMovementRules checks it, with the error of each field. */
final class StockMovementDraft
{
    /** A StockMovementKind's value. */
    public string $kind = StockMovementKind::Exit->value;
    public string $article = '';
    /** « 2026-03-31 », the date field's hidden value. */
    public string $date = '';
    public string $quantity = '';
    /** An entry's unit cost excluding tax. */
    public string $unitCost = '';
    public string $location = StockMovement::MAIN_LOCATION;
    public string $toLocation = '';
    public string $label = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
