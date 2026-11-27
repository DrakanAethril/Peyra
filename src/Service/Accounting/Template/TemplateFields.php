<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use App\Entity\Accounting\Journal;
use App\Enum\Accounting\EntrySide;
use App\Enum\Accounting\TemplateAmount;
use App\Enum\Accounting\TemplateBase;
use App\Service\Shared\Money;

/** What TemplateRules accepted: the template ready to be written. */
final readonly class TemplateFields
{
    /** @param list<array{account: string, thirdParty: string, label: string, side: EntrySide, amount: TemplateAmount, fixed: ?Money}> $lines */
    public function __construct(
        public string $code,
        public string $label,
        public Journal $journal,
        public ?TemplateBase $base,
        public ?string $vatRate,
        public array $lines,
    ) {
    }
}
