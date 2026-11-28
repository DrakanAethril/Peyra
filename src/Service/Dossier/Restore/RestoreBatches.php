<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;
use App\Repository\Dossier\RestorePointRepository;
use App\Service\Dossier\ClassDossierRow;

/**
 * The teacher's gestures on several dossiers at once (design-ux.md § 4.9, step 4; lot 1, step
 * 1.8d): « Poser un point de restauration » on each, and « Remettre à l'état de départ » - each
 * dossier taken back to the « État de départ » its distribution posed, through the same doors as
 * one dossier (RestorePoints::pose(), Restorations::returnTo()), so the same waiting pages, trail
 * lines and background work follow. A dossier one of them refuses is left and said; the others go
 * on. Who may do it on which dossier is the caller's to ask (RestorePointVoter).
 *
 * @phpstan-import-type Summary from RestoreSummary
 */
final readonly class RestoreBatches
{
    public const string NO_START = 'pas d’état de départ';

    public function __construct(
        private RestorePoints $points,
        private Restorations $restorations,
        private RestorePointRepository $pointRepository,
        private RestoreSummary $summary,
    ) {
    }

    /**
     * A point on each dossier, under one name; a dossier at its ten points is counted, not blocking.
     *
     * @param list<ClassDossierRow> $rows
     *
     * @throws RestoreRefused when the name is not one (nothing posed)
     */
    public function pose(array $rows, string $name, User $by, \DateTimeImmutable $now): RestoreBatchOutcome
    {
        $fault = RestorePointRules::nameFault($name);
        if (null !== $fault) {
            throw new RestoreRefused($fault, 'name');
        }
        $done = 0;
        $skipped = [];
        foreach ($rows as $row) {
            try {
                $this->points->pose($row->dossier, $name, $by, $now);
                ++$done;
            } catch (RestoreRefused $refused) {
                $skipped[] = ['holder' => $row->holderLabel(), 'reason' => self::reason($refused)];
            }
        }

        return new RestoreBatchOutcome($done, $skipped);
    }

    /**
     * The récapitulatif's lines: for each dossier its « État de départ », what changes going back
     * to it, and whether the state it leaves can be kept as a point (not at ten points).
     *
     * @param list<ClassDossierRow> $rows
     *
     * @return list<array{row: ClassDossierRow, summary: Summary|null, full: bool}>
     */
    public function summaries(array $rows): array
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'row' => $row,
                'summary' => null === $row->start || $row->restoring ? null : $this->summary->of($row->start),
                'full' => RestorePointRules::limitReached($this->pointRepository->countAgainstLimit($row->dossier)),
            ];
        }

        return $lines;
    }

    /**
     * Takes each dossier back to its « État de départ ». With $keepCurrent, the state each leaves is
     * posed as a point first wherever its ten points leave room (the récapitulatif says which do
     * not). A dossier without a ready start, or already going back to a point, is left.
     *
     * @param list<ClassDossierRow> $rows
     */
    public function resetToStart(array $rows, bool $keepCurrent, User $by, \DateTimeImmutable $now): RestoreBatchOutcome
    {
        $done = 0;
        $skipped = [];
        foreach ($rows as $row) {
            $start = $this->pointRepository->findStart($row->dossier);
            if (!$start instanceof RestorePoint) {
                $skipped[] = ['holder' => $row->holderLabel(), 'reason' => self::NO_START];
                continue;
            }
            $keep = $keepCurrent && !RestorePointRules::limitReached($this->pointRepository->countAgainstLimit($row->dossier));
            try {
                $this->restorations->returnTo($start, $keep, $by, $now);
                ++$done;
            } catch (RestoreRefused $refused) {
                $skipped[] = ['holder' => $row->holderLabel(), 'reason' => self::reason($refused)];
            }
        }

        return new RestoreBatchOutcome($done, $skipped);
    }

    /** A refusal's sentence as a reason in a list: its first clause, without its capital nor its full stop. */
    private static function reason(RestoreRefused $refused): string
    {
        $sentence = explode(' : ', explode('. ', $refused->getMessage())[0])[0];

        return mb_strtolower(mb_substr($sentence, 0, 1)).rtrim(mb_substr($sentence, 1), '.');
    }
}
