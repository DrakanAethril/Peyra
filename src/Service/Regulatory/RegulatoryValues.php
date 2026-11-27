<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Entity\Identity\User;
use App\Entity\Regulatory\RegulatoryValue;
use App\Enum\Audit\AuditAction;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\DecimalParseError;
use App\Service\Shared\DecimalParser;
use App\Service\Shared\FrenchFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door by which a person writes a regulatory value: « Nouvelle valeur datée », for
 * administrators (design-ux.md § 3.3). It adds a row and never changes one: a value from a new
 * date, or the correction of a date's value, the corrected row staying in the history. Each
 * addition is written to the audit trail.
 *
 * @phpstan-import-type Node from RegulatoryTree
 */
final readonly class RegulatoryValues
{
    private const int NOTE_MAX = 2000;

    public function __construct(private EntityManagerInterface $entityManager, private AuditTrail $auditTrail)
    {
    }

    /**
     * Adds the draft's value, or leaves its refusals in $draft->errors and returns null.
     */
    public function add(RegulatoryHistory $history, RegulatoryDraft $draft, User $administrator, \DateTimeImmutable $now): ?RegulatoryValue
    {
        $draft->errors = [];
        $tree = $this->tree($draft);
        $sourceUrl = trim($draft->sourceUrl);
        $note = trim($draft->note);

        if (null === $draft->effectiveFrom) {
            $draft->errors['effective_from'] = 'Saisissez la date d’effet.';
        }
        if ('' !== $sourceUrl && (1 !== preg_match('#^https?://\S+$#', $sourceUrl) || \strlen($sourceUrl) > 500)) {
            $draft->errors['source_url'] = 'Saisissez une adresse qui commence par https://.';
        }
        if ($draft->consultedOnUnreadable) {
            $draft->errors['consulted_on'] = 'Cette date n’existe pas. Écrivez-la JJ/MM/AAAA.';
        }
        if ($draft->verified && ('' === $sourceUrl || null === $draft->consultedOn)) {
            $draft->errors['verified'] = 'Une valeur vérifiée dit où et quand elle a été lue : renseignez la source et la date de consultation.';
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            $draft->errors['note'] = \sprintf('La note tient en %d caractères.', self::NOTE_MAX);
        }
        if ([] !== $draft->errors) {
            return null;
        }

        $existing = $history->versionOf($draft->effectiveFrom);
        if (null !== $existing
            && $existing->value->getValueText() === RegulatoryTree::encode($tree)
            && $existing->value->getSourceUrl() === ('' === $sourceUrl ? null : $sourceUrl)
            && $existing->value->getConsultedOn()?->format('Y-m-d') === $draft->consultedOn?->format('Y-m-d')
            && $existing->value->isVerified() === $draft->verified
            && $existing->value->getNote() === ('' === $note ? null : $note)) {
            $draft->errors['form'] = \sprintf('Rien ne change : c’est déjà la valeur du %s.', FrenchFormat::date($draft->effectiveFrom));

            return null;
        }

        $value = RegulatoryValue::byAdministrator($draft->base, $tree, $draft->effectiveFrom, '' === $sourceUrl ? null : $sourceUrl, $draft->consultedOn, $draft->verified, '' === $note ? null : $note, $administrator, $now);
        $this->entityManager->persist($value);
        $this->auditTrail->record(
            AuditAction::RegulatoryValueAdded,
            $administrator,
            'regulatory_key',
            $value->getKey(),
            \sprintf('%s : %s au %s%s', $value->getLabel(), self::summary($tree, $value->getUnit()), FrenchFormat::date($draft->effectiveFrom), null !== $existing ? ' (correction)' : ''),
            ['effective_from' => $draft->effectiveFrom->format('Y-m-d'), 'value' => $tree, 'correction' => null !== $existing, 'verified' => $draft->verified],
        );
        $this->entityManager->flush();

        return $value;
    }

    /**
     * The typed leaves read into the base's structure; a leaf that cannot be read leaves its
     * refusal in $draft->errors (`leaf_<index>`) and an empty place in the tree.
     *
     * @return Node
     */
    private function tree(RegulatoryDraft $draft): string|bool|array|null
    {
        $values = [];
        foreach ($draft->baseLeaves() as $index => $leaf) {
            $typed = trim($draft->leaves[$index] ?? '');
            if (\is_bool($leaf->value)) {
                $values[] = '1' === $typed;
                continue;
            }
            if ($leaf->isNumber()) {
                try {
                    $values[] = DecimalParser::parse($typed)->strippedOfTrailingZeros()->toString();
                } catch (DecimalParseError $error) {
                    $draft->errors['leaf_'.$index] = $error->getMessage();
                    $values[] = null;
                }
                continue;
            }
            if ('' === $typed && null !== $leaf->value) {
                $draft->errors['leaf_'.$index] = 'Saisissez une valeur.';
            }
            $values[] = '' === $typed ? null : $typed;
        }

        return RegulatoryTree::withLeaves($draft->base->getTree(), $values);
    }

    /** @param Node $tree « 12,31 €/heure », or « 4 valeurs » for a structure */
    private static function summary(string|bool|array|null $tree, string $unit): string
    {
        $leaves = RegulatoryTree::leaves($tree);
        if (!\is_array($tree) && 1 === \count($leaves)) {
            return RegulatoryDisplay::leaf($leaves[0]->value, $unit);
        }

        return \count($leaves).' valeurs';
    }
}
