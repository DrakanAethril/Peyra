<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Entity\Accounting\Entry;

/** What « Valider la sélection » did: the entries validated, those held back, the Ledger's refusal. */
final readonly class BrouillardOutcome
{
    /** @param list<Entry> $validated */
    public function __construct(
        public array $validated,
        public int $held,
        public ?string $fault,
    ) {
    }

    /** One sentence for the status bar. */
    public function message(): string
    {
        if (null !== $this->fault) {
            return 'Aucune écriture validée : '.$this->fault;
        }
        $count = \count($this->validated);
        $numbers = array_map(static fn (Entry $entry): string => (string) $entry->getNumberLabel(), $this->validated);
        sort($numbers);
        $said = match (true) {
            0 === $count => 'Aucune écriture validée.',
            1 === $count => \sprintf('Écriture %s validée.', $numbers[0]),
            default => \sprintf('%d écritures validées, de %s à %s.', $count, $numbers[0], $numbers[$count - 1]),
        };
        if ($this->held > 0) {
            $said .= \sprintf(' %s retenue%s : un contrôle l’arrête ou elle est en cours de modification.', 1 === $this->held ? 'Une écriture' : $this->held.' écritures', 1 === $this->held ? '' : 's');
        }

        return $said;
    }
}
