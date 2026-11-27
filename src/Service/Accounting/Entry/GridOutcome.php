<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Entity\Accounting\Entry;

/**
 * What F10 or Ctrl Entrée did in the grid, said in the status bar (design-ux.md § 1.2 A8):
 * entries written to the brouillard, drafts deleted, entries left pending (and the first reason),
 * entries validated, drafts held back from the validation.
 */
final class GridOutcome
{
    public int $saved = 0;

    public int $discarded = 0;

    public int $left = 0;

    public int $held = 0;

    public ?string $fault = null;

    /** @var list<Entry>|null null when nothing was asked to validate */
    public ?array $validated = null;

    public function keepFault(GridCheck $check): void
    {
        if (null !== $this->fault) {
            return;
        }
        $first = reset($check->errors);
        if (false !== $first) {
            $this->fault = $first;

            return;
        }
        foreach ($check->controls as $control) {
            if ($control->blocking) {
                $this->fault = $control->message;

                return;
            }
        }
    }

    /** One sentence for the status bar. */
    public function message(): string
    {
        $parts = [];
        if (null !== $this->validated) {
            $count = \count($this->validated);
            if (0 === $count) {
                $parts[] = null === $this->fault ? 'Aucune écriture à valider dans ce journal et ce mois.' : 'Aucune écriture validée.';
            } else {
                $numbers = array_map(static fn (Entry $entry): string => (string) $entry->getNumberLabel(), $this->validated);
                sort($numbers);
                $parts[] = 1 === $count
                    ? \sprintf('Écriture %s validée.', $numbers[0])
                    : \sprintf('%d écritures validées : %s à %s.', $count, $numbers[0], $numbers[$count - 1]);
            }
        } elseif (0 === $this->saved && 0 === $this->discarded && 0 === $this->left) {
            $parts[] = 'Rien à enregistrer : le brouillard est à jour.';
        }
        if (null === $this->validated || $this->saved > 0) {
            if ($this->saved > 0) {
                $parts[] = 1 === $this->saved ? '1 écriture enregistrée au brouillard.' : \sprintf('%d écritures enregistrées au brouillard.', $this->saved);
            }
        }
        if ($this->discarded > 0) {
            $parts[] = 1 === $this->discarded ? '1 écriture supprimée du brouillard.' : \sprintf('%d écritures supprimées du brouillard.', $this->discarded);
        }
        if ($this->left > 0) {
            $parts[] = (1 === $this->left ? '1 écriture reste en attente' : \sprintf('%d écritures restent en attente', $this->left)).(null === $this->fault ? '.' : ' : '.self::lower($this->fault));
        } elseif (null !== $this->fault) {
            $parts[] = $this->fault;
        }
        if ($this->held > 0) {
            $parts[] = 1 === $this->held ? '1 écriture du brouillard n’est pas validée (en cours de modification ou arrêtée par un contrôle).' : \sprintf('%d écritures du brouillard ne sont pas validées (en cours de modification ou arrêtées par un contrôle).', $this->held);
        }

        return implode(' ', $parts);
    }

    private static function lower(string $sentence): string
    {
        return mb_strtolower(mb_substr($sentence, 0, 1)).mb_substr($sentence, 1);
    }
}
