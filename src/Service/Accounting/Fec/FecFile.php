<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec;

/**
 * A dossier's FEC for an exercise: its name, its text, what it holds (entries, lines), what it
 * leaves out (the brouillard), and its conformity check.
 */
final readonly class FecFile
{
    public function __construct(
        public string $name,
        public string $content,
        public int $entries,
        public int $lines,
        public int $drafts,
        public FecReport $report,
    ) {
    }
}
