<?php

declare(strict_types=1);

namespace App\Service\Document\Processing;

/**
 * One line of a pièce's « Contrôles avant validation » (maquette 1): what the tool checked and
 * found right, what it found wrong - stopping the posting at levels G and B -, or what it leaves to
 * the student (the mandatory mentions).
 */
final readonly class PieceControl
{
    public function __construct(
        public string $message,
        public bool $passed,
        public bool $blocking = false,
    ) {
    }
}
