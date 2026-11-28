<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\Audit\AuditEvent;
use App\Service\Shared\Export\Column;
use App\Service\Shared\Export\ColumnKind;
use App\Service\Shared\Export\ExportTable;

/**
 * A dossier's trail as a spreadsheet (Piste d'audit › Exporter): one row per line, the real time
 * and the dossier's date side by side, who, as what, the gesture and its sentence.
 */
final class DossierTrailExport
{
    /** @param list<AuditEvent> $events */
    public static function table(array $events): ExportTable
    {
        $table = new ExportTable('Piste d’audit', [
            new Column('Date réelle', ColumnKind::Text, 17),
            new Column('Date du dossier', ColumnKind::Day, 12),
            new Column('Auteur', ColumnKind::Text, 24),
            new Column('Rôle', ColumnKind::Text, 12),
            new Column('Profil', ColumnKind::Text, 22),
            new Column('Action', ColumnKind::Text, 32),
            new Column('Détail', ColumnKind::Text, 70),
        ]);
        foreach ($events as $event) {
            $table->add([
                $event->getOccurredAt()->format('d/m/Y H:i:s'),
                $event->getDossierDate(),
                $event->getActorName(),
                $event->getActorRole()?->label() ?? 'Commande',
                $event->getActorProfile()?->label(),
                $event->getAction()->label(),
                $event->getSummary(),
            ]);
        }

        return $table;
    }
}
