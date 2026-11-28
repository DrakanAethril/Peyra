<?php

declare(strict_types=1);

namespace App\Service\Shared\Referential;

/** A professional exam of the BTS CG (E41, E42, E5, E6), as the règlement d'examen defines it. */
final readonly class ReferentialExam
{
    public function __construct(
        public string $code,
        public string $unit,
        public string $title,
        public int $coefficient,
        public string $form,
        public bool $onWorkstation,
        public bool $verified,
    ) {
    }
}
