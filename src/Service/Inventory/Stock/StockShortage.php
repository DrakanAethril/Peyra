<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

/** A movement the stock no longer holds when it is recorded (another one took it meanwhile): nothing is written. */
final class StockShortage extends \RuntimeException
{
}
