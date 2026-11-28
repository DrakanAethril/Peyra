<?php

declare(strict_types=1);

namespace App\Service\Shared\Referential;

use App\Enum\Shared\BtsProcess;

/** An activity of the référentiel (A1.2): what a mission aims at and what positioning is decided on. */
final readonly class ReferentialActivity
{
    /**
     * @param list<string>               $exams      codes of ReferentialExam
     * @param list<ReferentialComponent> $components in the text's order
     */
    public function __construct(
        public string $code,
        public BtsProcess $process,
        public string $title,
        public array $exams,
        public array $components,
        public bool $verified,
        public ?string $note,
    ) {
    }
}
