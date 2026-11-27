<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

/** One line of an imported plan, as ChartImport reads it and what it will do with it. */
final readonly class ChartImportLine
{
    public const string CREATE = 'create';
    public const string UPDATE = 'update';
    public const string SAME = 'same';
    public const string REFUSED = 'refused';

    public function __construct(
        public int $line,
        public string $number,
        public string $label,
        public ?bool $lettrable,
        public ?bool $collective,
        public string $action,
        public ?string $message = null,
    ) {
    }
}
