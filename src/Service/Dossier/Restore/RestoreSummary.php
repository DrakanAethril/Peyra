<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

use App\Entity\Dossier\RestorePoint;
use App\Repository\Audit\AuditEventRepository;
use App\Service\Dossier\DossierContents;

/**
 * The récapitulatif of a return to a restore point (design-ux.md § 4.9, step 2; § 6.2 « qui
 * compte ce qui change »): what the dossier holds now and what the point holds, side by side, the
 * lines that differ marked, and how many gestures of the trail were made since the point.
 *
 * @phpstan-type SummaryRow array{label: string, now: int, point: int}
 * @phpstan-type Summary array{rows: list<SummaryRow>, changed: int, gestures: int}
 */
final readonly class RestoreSummary
{
    /** What the récapitulatif compares, in its order. */
    private const array LABELS = [
        'validated' => 'Écritures validées',
        'drafts' => 'Écritures au brouillard',
        'pending' => 'Saisies en attente',
        'lettered' => 'Lignes lettrées',
        'accounts' => 'Comptes du plan',
        'journals' => 'Journaux',
        'thirdParties' => 'Tiers',
        'entryTemplates' => 'Modèles d’écriture',
        'subscriptions' => 'Abonnements',
        'exercises' => 'Exercices',
    ];

    public function __construct(private DossierContents $contents, private AuditEventRepository $trail)
    {
    }

    /** @return Summary */
    public function of(RestorePoint $point): array
    {
        $now = $this->contents->of($point->getDossier());
        $then = $this->contents->of($point->getSnapshot());
        $rows = [];
        $changed = 0;
        foreach (self::LABELS as $key => $label) {
            $rows[] = ['label' => $label, 'now' => $now[$key], 'point' => $then[$key]];
            if ($now[$key] !== $then[$key]) {
                ++$changed;
            }
        }

        return [
            'rows' => $rows,
            'changed' => $changed,
            'gestures' => $this->trail->countDossierSince((int) $point->getDossier()->getId(), $point->getCreatedAt()),
        ];
    }
}
