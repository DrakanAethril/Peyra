<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

/** What app:regulatory:sync did with one millésime file. Each line reads « clé au JJ/MM/AAAA ». */
final class RegulatorySyncReport
{
    /** @var list<string> values the database did not hold */
    public array $added = [];

    /** @var list<string> values the file changed since its last installation: a row more, the older one kept */
    public array $updated = [];

    /** @var list<string> values an administrator entered since, left as they are */
    public array $kept = [];

    public int $unchanged = 0;

    public function __construct(public readonly int $millesime, public readonly string $fileName)
    {
    }

    public function changed(): bool
    {
        return [] !== $this->added || [] !== $this->updated;
    }

    /** « 12 ajoutées, 1 mise à jour, 1 conservée, 40 inchangées » */
    public function summary(): string
    {
        $parts = [];
        foreach ([[\count($this->added), 'ajoutée', 'ajoutées'], [\count($this->updated), 'mise à jour', 'mises à jour'], [\count($this->kept), 'conservée (saisie d’un administrateur)', 'conservées (saisies d’un administrateur)'], [$this->unchanged, 'inchangée', 'inchangées']] as [$count, $one, $many]) {
            if ($count > 0) {
                $parts[] = $count.' '.($count > 1 ? $many : $one);
            }
        }

        return [] === $parts ? 'aucune valeur' : implode(', ', $parts);
    }
}
