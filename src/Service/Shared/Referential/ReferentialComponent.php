<?php

declare(strict_types=1);

namespace App\Service\Shared\Referential;

/** A component of an activity (A1.2.1), with the exams that assess it - its activity's unless the text says otherwise. */
final readonly class ReferentialComponent
{
    /** @param list<string> $exams codes of ReferentialExam */
    public function __construct(
        public string $code,
        public string $title,
        public array $exams,
        public ?string $note,
    ) {
    }
}
