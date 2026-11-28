<?php

declare(strict_types=1);

namespace App\Service\Mission\AnswerKey;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Mission\Mission;
use App\Entity\Mission\MissionAnswerKey;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\CopyKind;
use App\Enum\Dossier\DossierKind;
use App\Repository\Document\DocumentRepository;
use App\Repository\Mission\MissionAnswerKeyRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Copy\DossierCopier;
use App\Service\Dossier\DossierContext;
use App\Service\Mission\MissionRefused;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of a mission's answer key (step 2.9b; design-ux.md § 3.3 « Corrigé (saisi dans le PGI
 * en mode corrigé, tolérances) »): « Jouer la mission en mode corrigé » makes the teacher's copy of
 * the template, « Enregistrer comme corrigé » reads it into MissionAnswerKey, the tolerances are set
 * beside. Who may: the mission's author (MissionVoter, in the controller).
 *
 * @phpstan-import-type ExpectedEntry from MissionAnswerKey
 * @phpstan-import-type ExpectedPiece from MissionAnswerKey
 * @phpstan-import-type EntryRow from AnswerKeyReading
 */
final readonly class AnswerKeys
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MissionAnswerKeyRepository $answerKeys,
        private DossierCopier $copier,
        private DossierContext $context,
        private DocumentRepository $documents,
        private AuditTrail $auditTrail,
    ) {
    }

    /**
     * The teacher's copy of the mission's template - its content as it is handed out, its date -, of
     * kind AnswerKey: theirs alone, listed nowhere but on the mission. Once per mission.
     *
     * @throws MissionRefused
     */
    public function play(Mission $mission, User $teacher, \DateTimeImmutable $now): Dossier
    {
        $template = $mission->getTemplate() ?? throw MissionRefused::of(['answerKey' => 'Le dossier modèle de cette mission a été supprimé : il n’y a plus où jouer la mission.']);
        if (null !== $this->answerKeys->findOneByMission($mission)?->getDossier()) {
            throw MissionRefused::of(['answerKey' => 'Le dossier du corrigé existe déjà : ouvrez-le.']);
        }

        return $this->entityManager->wrapInTransaction(function () use ($mission, $template, $teacher, $now): Dossier {
            $copy = new Dossier(DossierKind::AnswerKey, $template->getCompany(), $template->getDossierDate(), $teacher, null, $now, $teacher);
            $this->entityManager->persist($copy);
            $this->entityManager->flush();
            $report = $this->copier->copy($template, $copy, CopyKind::Template);
            $baseline = array_map(AnswerKeyReading::fingerprint(...), $this->entryRows($copy));
            $answerKey = $this->answerKeys->findOneByMission($mission);
            if (null === $answerKey) {
                $this->entityManager->persist(new MissionAnswerKey($mission, $copy, $baseline, $now));
            } else {
                // Its copy was deleted: the record stays, a new copy starts from the template again.
                $answerKey->playIn($copy, $baseline);
            }
            $this->auditTrail->inDossier($copy, AuditAction::DossierCreated, $teacher, \sprintf('Dossier du corrigé de la mission « %s » créé, copie de « %s »', $mission->getTitle(), $template->getLibraryTitle()), [
                'dossier' => $copy->getId(),
                'kind' => DossierKind::AnswerKey->value,
                'source' => $template->getId(),
                'mission' => $mission->getId(),
                'rows' => $report->rowCount(),
            ]);
            $this->auditTrail->record(AuditAction::MissionChanged, $teacher, 'mission', (string) $mission->getId(), \sprintf('Mission « %s » : dossier du corrigé créé', $mission->getTitle()));
            $this->entityManager->flush();

            return $copy;
        });
    }

    /**
     * « Enregistrer comme corrigé »: the copy as it stands now becomes the answer key - its entries
     * the starting state did not hold, its arrived pièces and what became of them. Recording again
     * replaces the record; the optional lines of the entries that did not move stay optional.
     *
     * @throws MissionRefused
     */
    public function record(Mission $mission, User $teacher, \DateTimeImmutable $now): MissionAnswerKey
    {
        $answerKey = $this->answerKeys->findOneByMission($mission);
        $copy = $answerKey?->getDossier();
        if (null === $answerKey || null === $copy) {
            throw MissionRefused::of(['answerKey' => 'Jouez d’abord la mission dans le dossier du corrigé.']);
        }
        [$entries, $pieces] = $this->context->within($copy, function () use ($copy, $answerKey): array {
            $rows = $this->entryRows($copy);
            $expected = AnswerKeyReading::expected($rows, $answerKey->getBaseline(), $answerKey->getEntries());

            return [$expected, $this->pieces($copy, $expected)];
        });
        $answerKey->record($entries, $pieces, $teacher, $now);
        $this->auditTrail->record(AuditAction::MissionAnswerKeyRecorded, $teacher, 'mission', (string) $mission->getId(), \sprintf('Corrigé de la mission « %s » enregistré : %d écriture%s, %d pièce%s', $mission->getTitle(), \count($entries), \count($entries) > 1 ? 's' : '', \count($pieces), \count($pieces) > 1 ? 's' : ''));
        $this->entityManager->flush();

        return $answerKey;
    }

    /**
     * The tolerances: groups of equivalent accounts, the date window, the optional lines (keys
     * `<entry>.<line>` of the recorded entries).
     *
     * @param list<string> $optional
     *
     * @throws MissionRefused
     */
    public function tolerate(Mission $mission, string $equivalentAccounts, string $dateWindow, array $optional, User $teacher): void
    {
        $answerKey = $this->answerKeys->findOneByMission($mission);
        if (null === $answerKey || !$answerKey->isRecorded()) {
            throw MissionRefused::of(['answerKey' => 'Enregistrez d’abord le corrigé : les tolérances portent sur ses écritures.']);
        }
        [$groups, $groupsFault] = AnswerKeyReading::equivalentAccounts($equivalentAccounts);
        [$days, $daysFault] = AnswerKeyReading::dateWindow($dateWindow);
        $errors = array_filter(['equivalentAccounts' => $groupsFault, 'dateWindow' => $daysFault], static fn (?string $fault): bool => null !== $fault);
        if ([] !== $errors) {
            throw MissionRefused::of($errors);
        }
        $answerKey->tolerate($groups, $days, AnswerKeyReading::withOptional($answerKey->getEntries(), $optional));
        $this->auditTrail->record(AuditAction::MissionChanged, $teacher, 'mission', (string) $mission->getId(), \sprintf('Mission « %s » modifiée (tolérances du corrigé)', $mission->getTitle()));
        $this->entityManager->flush();
    }

    /**
     * The dossier's entries as AnswerKeyReading reads them, in journal, date and id order (inside
     * its context).
     *
     * @return list<EntryRow>
     */
    private function entryRows(Dossier $dossier): array
    {
        return $this->context->within($dossier, function (): array {
            /** @var list<Entry> $entries */
            $entries = $this->entityManager->createQueryBuilder()
                ->select('e', 'l', 'j', 'a', 't')
                ->from(Entry::class, 'e')
                ->join('e.journal', 'j')
                ->leftJoin('e.lines', 'l')
                ->leftJoin('l.account', 'a')
                ->leftJoin('l.thirdParty', 't')
                ->orderBy('j.code', 'ASC')->addOrderBy('e.entryDate', 'ASC')->addOrderBy('e.id', 'ASC')->addOrderBy('l.position', 'ASC')
                ->getQuery()->getResult();
            $rows = [];
            foreach ($entries as $entry) {
                $rows[(int) $entry->getId()] = self::row($entry);
            }

            return array_values($rows);
        });
    }

    /** @return EntryRow */
    private static function row(Entry $entry): array
    {
        $lines = [];
        foreach ($entry->getLines() as $line) {
            $lines[] = [
                'account' => $line->getAccount()->getNumber(),
                'thirdParty' => $line->getThirdParty()?->getCode(),
                'label' => $line->getLabel(),
                'debit' => $line->getDebit()->cents,
                'credit' => $line->getCredit()->cents,
            ];
        }

        return ['journal' => $entry->getJournal()->getCode(), 'date' => $entry->getEntryDate()->format('Y-m-d'), 'document' => $entry->getDocument(), 'label' => $entry->getLabel(), 'state' => $entry->getState()->value, 'lines' => $lines];
    }

    /**
     * The arrived pièces of the copy, in the order they arrived, each with the index of the
     * expected entry it was posted to.
     *
     * @param list<ExpectedEntry> $expected
     *
     * @return list<ExpectedPiece>
     */
    private function pieces(Dossier $copy, array $expected): array
    {
        $byFingerprint = [];
        foreach ($expected as $i => $entry) {
            $byFingerprint[$entry['fingerprint']] ??= $i;
        }
        $pieces = [];
        foreach (array_reverse($this->documents->findByDossier($copy)) as $document) {
            $entry = $document->getEntry();
            $pieces[] = [
                'number' => (string) $document->getNumber(),
                'type' => $document->getType()->value,
                'duplicate' => null !== $document->getDuplicateOf(),
                'thirdParty' => $document->getThirdParty()?->getCode(),
                'state' => $document->getState()->value,
                'entry' => null === $entry ? null : ($byFingerprint[AnswerKeyReading::fingerprint(self::row($entry))] ?? null),
            ];
        }

        return $pieces;
    }
}
