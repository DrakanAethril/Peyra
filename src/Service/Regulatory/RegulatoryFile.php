<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Enum\Regulatory\RegulatoryDomain;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a millésime file (config/regulatory/<année>.yaml) and checks it whole: a file with one
 * fault is refused with every fault named, and nothing of it is installed. Pure.
 *
 * The schema is the one of the prepared data (plan, donnees/reglementaire/LISEZ-MOI.md):
 *
 *     millesime: 2026
 *     consulted_on: 2026-11-27          # optional, the date of the research
 *     entries:
 *       - key: social.smic_hourly       # <domain>.<name>, snake_case; a domain of RegulatoryDomain
 *         label: SMIC horaire brut
 *         value: 12.02                  # a number, a text, or a structure of them
 *         unit: EUR/heure
 *         effective_from: 2026-01-01    # a change already known for a later year is allowed
 *         source_url: https://…         # optional, http(s)
 *         consulted_on: 2026-11-27      # optional
 *         verified: true                # true only when read on the official source
 *         note: …                       # optional
 *
 * A key may appear several times in a file, at different effective dates (the SMIC of January and
 * the SMIC of June); twice at the same date is a fault.
 */
final class RegulatoryFile
{
    private const array ENTRY_FIELDS = ['key', 'label', 'value', 'unit', 'effective_from', 'source_url', 'consulted_on', 'verified', 'note'];

    private const array HEADER_FIELDS = ['millesime', 'consulted_on', 'entries'];

    private const string KEY = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/';

    /**
     * @return list<RegulatoryFileEntry>
     *
     * @throws InvalidRegulatoryFile
     */
    public static function parse(string $yaml, string $fileName, int $millesime): array
    {
        try {
            $document = Yaml::parse($yaml, Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $error) {
            throw new InvalidRegulatoryFile($fileName, ['YAML illisible : '.$error->getMessage()]);
        }
        if (!\is_array($document)) {
            throw new InvalidRegulatoryFile($fileName, ['le fichier ne contient pas de tableau « millesime », « entries ».']);
        }

        $problems = [];
        foreach (array_diff(array_map(strval(...), array_keys($document)), self::HEADER_FIELDS) as $unknown) {
            $problems[] = \sprintf('champ inconnu en tête de fichier : « %s ».', $unknown);
        }
        if (($document['millesime'] ?? null) !== $millesime) {
            $problems[] = \sprintf('« millesime » doit valoir %d, comme le nom du fichier.', $millesime);
        }
        $entries = $document['entries'] ?? null;
        if (!\is_array($entries) || !array_is_list($entries)) {
            $problems[] = '« entries » doit être une liste (vide si le millésime n’a encore aucune valeur).';
            $entries = [];
        }

        $read = [];
        $seen = [];
        foreach ($entries as $index => $raw) {
            $where = \sprintf('entrée %d', $index + 1);
            if (!\is_array($raw)) {
                $problems[] = $where.' : ce n’est pas un tableau de champs.';
                continue;
            }
            $entryProblems = [];
            $entry = self::entry($raw, $entryProblems);
            $key = \is_string($raw['key'] ?? null) ? $raw['key'] : null;
            if (null !== $key) {
                $where .= ' ('.$key.')';
            }
            foreach ($entryProblems as $problem) {
                $problems[] = $where.' : '.$problem;
            }
            if (null === $entry) {
                continue;
            }
            $identity = $entry->key.'@'.$entry->effectiveFrom->format('Y-m-d');
            if (isset($seen[$identity])) {
                $problems[] = \sprintf('%s : même clé et même date d’effet que l’entrée %d.', $where, $seen[$identity]);
                continue;
            }
            $seen[$identity] = $index + 1;
            $read[] = $entry;
        }

        if ([] !== $problems) {
            throw new InvalidRegulatoryFile($fileName, $problems);
        }

        return $read;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param list<string>            $problems
     */
    private static function entry(array $raw, array &$problems): ?RegulatoryFileEntry
    {
        foreach (array_diff(array_map(strval(...), array_keys($raw)), self::ENTRY_FIELDS) as $unknown) {
            $problems[] = \sprintf('champ inconnu « %s ».', $unknown);
        }

        $key = $raw['key'] ?? null;
        if (!\is_string($key) || 1 !== preg_match(self::KEY, $key)) {
            $problems[] = '« key » doit être de la forme domaine.nom_en_snake_case.';
        } elseif (null === RegulatoryDomain::ofKey($key)) {
            $problems[] = \sprintf('domaine inconnu « %s » (domaines : %s).', explode('.', $key)[0], implode(', ', array_map(static fn (RegulatoryDomain $d): string => $d->value, RegulatoryDomain::cases())));
        }
        $label = self::text($raw['label'] ?? null);
        if (null === $label) {
            $problems[] = '« label » est obligatoire.';
        }
        $unit = self::text($raw['unit'] ?? null);
        if (null === $unit) {
            $problems[] = '« unit » est obligatoire.';
        }

        $tree = null;
        if (!\array_key_exists('value', $raw) || null === $raw['value']) {
            $problems[] = '« value » est obligatoire.';
        } else {
            try {
                $tree = RegulatoryTree::fromYaml($raw['value']);
                if ([] === RegulatoryTree::leaves($tree)) {
                    $problems[] = '« value » est une structure vide.';
                }
            } catch (\InvalidArgumentException) {
                $problems[] = '« value » ne contient que des nombres, des textes, des dates, des tableaux et des listes.';
            }
        }

        $effectiveFrom = self::date($raw['effective_from'] ?? null);
        if (null === $effectiveFrom) {
            $problems[] = '« effective_from » doit être une date AAAA-MM-JJ.';
        }

        $sourceUrl = $raw['source_url'] ?? null;
        if (null !== $sourceUrl && (!\is_string($sourceUrl) || 1 !== preg_match('#^https?://\S+$#', $sourceUrl) || \strlen($sourceUrl) > 500)) {
            $problems[] = '« source_url » doit être une adresse http(s).';
            $sourceUrl = null;
        }
        $consultedOn = null;
        if (null !== ($raw['consulted_on'] ?? null)) {
            $consultedOn = self::date($raw['consulted_on']);
            if (null === $consultedOn) {
                $problems[] = '« consulted_on » doit être une date AAAA-MM-JJ.';
            }
        }
        $verified = $raw['verified'] ?? null;
        if (!\is_bool($verified)) {
            $problems[] = '« verified » doit valoir true ou false.';
        } elseif ($verified && (null === $sourceUrl || null === $consultedOn)) {
            $problems[] = 'une valeur vérifiée dit où (« source_url ») et quand (« consulted_on ») elle a été lue.';
        }
        $note = $raw['note'] ?? null;
        if (null !== $note && !\is_string($note)) {
            $problems[] = '« note » est un texte.';
        }

        if ([] !== $problems || !\is_string($key) || null === $label || null === $unit || null === $effectiveFrom || !\is_bool($verified) || null === $tree) {
            return null;
        }

        return new RegulatoryFileEntry($key, $label, $tree, $unit, $effectiveFrom, \is_string($sourceUrl) ? $sourceUrl : null, $consultedOn, $verified, \is_string($note) && '' !== trim($note) ? trim($note) : null);
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /** A YAML date (parsed as a DateTime), or the same day quoted as text. */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return new \DateTimeImmutable($value->format('Y-m-d'));
        }
        if (\is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
        }

        return null;
    }
}
