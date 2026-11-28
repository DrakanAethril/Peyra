<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

use App\Entity\Accounting\EntryLine;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\LetteringMode;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\AccessLevel;
use App\Enum\Dossier\DossierArea;
use App\Repository\Accounting\EntryLineRepository;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Rights\DossierRights;
use App\Service\Dossier\Rights\Gesture;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the lettering (lot 1, step 1.6; LetteringDoorTest): it sets and clears the
 * letters of the lines, nothing else does.
 *
 * - `letter()` letters a selection by hand: lines of one account (one third party on a
 *   collective), lettrable, of validated entries, outside a closed exercise; LetteringRules decides
 *   the letter, the whole group, partial or not at the dossier's level of assistance.
 * - `unletter()` undoes a group, unless one of its lines sits in a closed period (design-ux.md
 *   § 6.3: « tant que la période n'est pas close »).
 * - `proposals()` reads the automatic lettering of an account; `letterProposals()` letters them,
 *   each group its letter in date order.
 *
 * Each gesture runs under a lock on the account's row - two tabs never take one letter -, reads
 * the account's lines again under it, dates the letters with the dossier's date (the FEC's DateLet),
 * is recorded in the audit trail and flushed in one transaction. A refusal is a LetteringRefused,
 * which writes nothing. A member of a group's dossier letters where their profile enters
 * (DossierArea::ofLettering(): a supplier with the purchases, a customer with the sales). Runs
 * inside the dossier's context.
 */
final readonly class Lettering
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryLineRepository $lines,
        private AssistanceLevels $assistance,
        private AuditTrail $auditTrail,
        private DossierRights $rights,
    ) {
    }

    /**
     * The lines chosen lettered together - completing the partial groups they touch.
     *
     * @param list<int> $lineIds
     */
    public function letter(Dossier $dossier, array $lineIds, User|string $actor): LetteringResult
    {
        $chosen = $this->lines->findByIds(array_values(array_unique($lineIds)));
        if ([] === $chosen) {
            throw new LetteringRefused('Choisissez les lignes à lettrer : des débits et les crédits qui les soldent.');
        }
        if (\count($chosen) !== \count(array_unique($lineIds))) {
            throw new LetteringRefused('Une ligne choisie n’existe plus : affichez de nouveau le compte.');
        }
        $key = LetteringKey::ofLine($chosen[0]);
        foreach ($chosen as $line) {
            if (!$key->holds($line)) {
                throw new LetteringRefused('Les lignes choisies sont sur des comptes ou des tiers différents : un lettrage tient sur un seul compte.');
            }
        }
        self::refuseUnlettrable($key);
        $this->refuseUnlessAllowed($dossier, $key, $actor);
        $partialAllowed = Assistance::at($this->assistance->for($dossier))->allowsPartialLettering();

        return $this->transactional($key, function (array $accountLines) use ($dossier, $key, $chosen, $partialAllowed, $actor): LetteringResult {
            $byId = self::byId($accountLines);
            $selected = [];
            foreach ($chosen as $line) {
                $fresh = $byId[(int) $line->getId()] ?? null;
                if (null === $fresh) {
                    throw new LetteringRefused(\sprintf('La ligne de la pièce %s est au brouillard : validez l’écriture avant de la lettrer.', $line->getEntry()->getDocument()));
                }
                $selected[] = self::primitive($fresh);
            }
            $decision = LetteringRules::decide($selected, array_map(self::primitive(...), $accountLines), $partialAllowed);
            if (null !== $decision->refusal) {
                throw new LetteringRefused($decision->refusal);
            }
            $lettered = array_map(static fn (int $id): EntryLine => $byId[$id], $decision->lineIds);
            self::refuseClosedExercise($lettered);
            foreach ($lettered as $line) {
                $line->letteringSet($decision->letter, $dossier->getDossierDate());
            }
            $debit = Money::sum(array_map(static fn (EntryLine $line): Money => $line->getDebit(), $lettered));
            $result = new LetteringResult($decision->letter, \count($lettered), $debit, $decision->gap);
            $this->record($dossier, AuditAction::LinesLettered, $actor, $key, \sprintf(
                '%s %s sur %s : %d lignes, %s au débit%s',
                $result->isPartial() ? 'Lettrage partiel' : 'Lettrage',
                $decision->letter,
                $key->describe(),
                \count($lettered),
                FrenchFormat::amount($debit),
                [] === $decision->merged ? '' : ' (complète '.implode(', ', $decision->merged).')',
            ), ['letter' => $decision->letter, 'lines' => $decision->lineIds, 'gap' => $decision->gap]);

            return $result;
        });
    }

    /** A group of the account undone; answers how many lines lost their letter. */
    public function unletter(Dossier $dossier, LetteringKey $key, string $letter, User|string $actor): int
    {
        $this->refuseUnlessAllowed($dossier, $key, $actor);

        return $this->transactional($key, function (array $accountLines) use ($dossier, $key, $letter, $actor): int {
            $group = array_values(array_filter($accountLines, static fn (EntryLine $line): bool => null !== $line->getLetter() && LetterSequence::same($line->getLetter(), $letter)));
            if ([] === $group) {
                throw new LetteringRefused(\sprintf('Aucune ligne de %s n’est lettrée %s.', $key->describe(), mb_strtoupper($letter)));
            }
            foreach ($group as $line) {
                $period = $line->getEntry()->getPeriod();
                if ($period->isClosed() || $period->getExercise()->isClosed()) {
                    throw new LetteringRefused(\sprintf('La période %s est close : le lettrage %s ne se défait plus.', mb_strtolower($period->getLabel()), $group[0]->getLetter()));
                }
            }
            $shown = (string) $group[0]->getLetter();
            foreach ($group as $line) {
                $line->letteringClear();
            }
            $this->record($dossier, AuditAction::LinesUnlettered, $actor, $key, \sprintf('Lettrage %s défait sur %s : %d lignes', $shown, $key->describe(), \count($group)), ['letter' => $shown, 'lines' => array_map(static fn (EntryLine $line): int => (int) $line->getId(), $group)]);

            return \count($group);
        });
    }

    /**
     * The automatic lettering of the account: groups of its unlettered validated lines, outside a
     * closed exercise, that balance.
     *
     * @return list<list<EntryLine>>
     */
    public function proposals(LetteringKey $key, LetteringMode $mode): array
    {
        return self::proposalsAmong($this->lines->findOnAccount($key->account, $key->thirdParty, true), $mode);
    }

    /**
     * The automatic lettering done: each group its letter, in date order. Answers the letters.
     *
     * @return list<string>
     */
    public function letterProposals(Dossier $dossier, LetteringKey $key, LetteringMode $mode, User|string $actor): array
    {
        self::refuseUnlettrable($key);
        $this->refuseUnlessAllowed($dossier, $key, $actor);

        return $this->transactional($key, function (array $accountLines) use ($dossier, $key, $mode, $actor): array {
            $groups = self::proposalsAmong($accountLines, $mode);
            if ([] === $groups) {
                throw new LetteringRefused(\sprintf('Le lettrage automatique %s ne trouve rien à lettrer sur %s.', $mode->phrase(), $key->describe()));
            }
            $used = array_values(array_filter(array_map(static fn (EntryLine $line): ?string => $line->getLetter(), $accountLines)));
            $letters = [];
            foreach ($groups as $group) {
                $letter = LetterSequence::next($used);
                $used[] = $letter;
                $letters[] = $letter;
                foreach ($group as $line) {
                    $line->letteringSet($letter, $dossier->getDossierDate());
                }
            }
            $this->record($dossier, AuditAction::LinesLettered, $actor, $key, \sprintf(
                'Lettrage automatique %s sur %s : %d groupe%s (%s)',
                $mode->phrase(),
                $key->describe(),
                \count($groups),
                \count($groups) > 1 ? 's' : '',
                implode(', ', $letters),
            ), ['mode' => $mode->value, 'letters' => $letters]);

            return $letters;
        });
    }

    /** The area of the key's lettering, for a refusal and for the screen. */
    public static function areaOf(LetteringKey $key): DossierArea
    {
        return DossierArea::ofLettering($key->thirdParty?->getKind());
    }

    private function refuseUnlessAllowed(Dossier $dossier, LetteringKey $key, User|string $actor): void
    {
        $area = self::areaOf($key);
        $refusal = $this->rights->refusal($dossier, $actor, $area, AccessLevel::Enter, Gesture::lettering($area));
        if (null !== $refusal) {
            throw new LetteringRefused($refusal);
        }
    }

    /**
     * @param list<EntryLine> $accountLines
     *
     * @return list<list<EntryLine>>
     */
    private static function proposalsAmong(array $accountLines, LetteringMode $mode): array
    {
        $open = array_values(array_filter($accountLines, static fn (EntryLine $line): bool => !$line->getEntry()->getExercise()->isClosed()));
        $primitives = array_map(self::primitive(...), $open);
        $groups = LetteringMode::Amount === $mode ? LetteringProposals::byAmount($primitives) : LetteringProposals::byDocument($primitives);
        $byId = self::byId($open);

        return array_map(static fn (array $ids): array => array_map(static fn (int $id): EntryLine => $byId[$id], $ids), $groups);
    }

    /**
     * The gesture inside a transaction, the account's row locked and its validated lines read
     * again; flushed and committed when it returns.
     *
     * @template T
     *
     * @param callable(list<EntryLine>): T $gesture
     *
     * @return T
     */
    private function transactional(LetteringKey $key, callable $gesture): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->lock($key->account, LockMode::PESSIMISTIC_WRITE);
            $result = $gesture($this->lines->findOnAccount($key->account, $key->thirdParty, true, true));
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();
            throw $failure;
        }

        return $result;
    }

    private static function refuseUnlettrable(LetteringKey $key): void
    {
        if (!$key->account->isLettrable()) {
            throw new LetteringRefused(\sprintf('Le compte %s n’est pas lettrable : cochez « Lettrable » sur sa fiche pour lettrer ses lignes.', $key->account->getNumber()));
        }
        if ($key->account->isCollective() && null === $key->thirdParty) {
            throw new LetteringRefused(\sprintf('Le compte %s est collectif : on lettre les lignes d’un tiers.', $key->account->getNumber()));
        }
    }

    /** @param list<EntryLine> $lines */
    private static function refuseClosedExercise(array $lines): void
    {
        foreach ($lines as $line) {
            $exercise = $line->getEntry()->getExercise();
            if ($exercise->isClosed()) {
                throw new LetteringRefused(\sprintf('L’exercice %s est clos : ses lignes ne se lettrent plus.', $exercise->getCode()));
            }
        }
    }

    private static function primitive(EntryLine $line): LetteringLine
    {
        return new LetteringLine(
            (int) $line->getId(),
            $line->getDebit()->cents,
            $line->getCredit()->cents,
            $line->getLetter(),
            $line->getEntry()->getEntryDate()->format('Y-m-d'),
            $line->getEntry()->getDocument(),
        );
    }

    /**
     * @param list<EntryLine> $lines
     *
     * @return array<int, EntryLine>
     */
    private static function byId(array $lines): array
    {
        $byId = [];
        foreach ($lines as $line) {
            $byId[(int) $line->getId()] = $line;
        }

        return $byId;
    }

    /** @param array<string, mixed> $details */
    private function record(Dossier $dossier, AuditAction $action, User|string $actor, LetteringKey $key, string $summary, array $details): void
    {
        $this->auditTrail->inDossier($dossier, $action, $actor, $summary, [
            'dossier' => $dossier->getId(),
            'account' => $key->account->getNumber(),
            'thirdParty' => $key->thirdParty?->getCode(),
            ...$details,
        ]);
    }
}
