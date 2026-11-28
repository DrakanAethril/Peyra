<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

/**
 * What a gesture on several dossiers at once did (Classe › Dossiers, a distribution): the dossiers
 * it reached, and each one it left with the sentence that says why - one refusal never stops the
 * others.
 */
final readonly class RestoreBatchOutcome
{
    /**
     * @param int                                         $done    the dossiers reached
     * @param list<array{holder: string, reason: string}> $skipped
     */
    public function __construct(public int $done, public array $skipped)
    {
    }

    /** « 3 dossiers remis à l'état de départ · 1 laissé : Morel Inès (pas d'état de départ). » */
    public function sentence(string $one, string $many): string
    {
        $parts = [\sprintf('%d %s', $this->done, $this->done > 1 ? $many : $one)];
        if ([] !== $this->skipped) {
            $parts[] = \sprintf('%d %s : %s', \count($this->skipped), \count($this->skipped) > 1 ? 'laissés' : 'laissé', implode(' ; ', array_map(static fn (array $skip): string => $skip['holder'].' ('.$skip['reason'].')', $this->skipped)));
        }

        return implode(' · ', $parts).'.';
    }
}
