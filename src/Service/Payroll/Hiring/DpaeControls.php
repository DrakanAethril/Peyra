<?php

declare(strict_types=1);

namespace App\Service\Payroll\Hiring;

use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Portal\PortalFault;

/**
 * What the simulated social portal checks of a DPAE before accepting it (step 4.2b), pure - the
 * mentions of articles R1221-1 and R1221-2 the dossier may lack, and the day of deposit:
 *
 * - the establishment's APE code (the SIRET and the address are required by the company's fiche);
 * - the employee's birth place and the hour of hire (name, sex, birth date, contract and day of
 *   hire are required by the employee's fiche; the NIR only « si déjà immatriculé »);
 * - deposited at the earliest `earliestDays` before the hire. Deposited after the hire, it is
 *   accepted and marked late (`isLate()`): the portal does not refuse it, the employer is at fault.
 */
final class DpaeControls
{
    /** @return list<PortalFault> empty: the portal accepts */
    public static function check(?string $nafCode, ?string $birthPlace, ?string $hireTime, \DateTimeImmutable $hiredOn, \DateTimeImmutable $declaredOn, int $earliestDays): array
    {
        $faults = [];
        if (null === $nafCode || '' === trim($nafCode)) {
            $faults[] = new PortalFault('DPAE-E01', 'Code APE de l’établissement absent.', 'La DPAE identifie l’employeur par son SIRET et son code APE : renseignez le code APE dans Système d’information › Société.');
        }
        if (null === $birthPlace || '' === trim($birthPlace)) {
            $faults[] = new PortalFault('DPAE-E02', 'Lieu de naissance du salarié absent.', 'La DPAE indique la date et le lieu de naissance du salarié, même quand son numéro de sécurité sociale est connu : complétez sa fiche.');
        }
        if (null === $hireTime || '' === trim($hireTime)) {
            $faults[] = new PortalFault('DPAE-E03', 'Heure d’embauche absente.', 'La DPAE déclare la date et l’heure prévues de l’embauche : c’est l’heure à laquelle le salarié commence à travailler, à indiquer sur sa fiche (Contrat).');
        }
        $opensOn = $hiredOn->modify(\sprintf('-%d days', $earliestDays));
        if ($declaredOn < $opensOn) {
            $faults[] = new PortalFault('DPAE-E04', \sprintf('Dépôt trop tôt : embauche le %s, dépôt possible à partir du %s.', FrenchFormat::date($hiredOn), FrenchFormat::date($opensOn)), \sprintf('Une DPAE se dépose au plus tôt %d jours avant l’embauche, et au plus tard juste avant la prise de poste : avancez la date du dossier ou attendez.', $earliestDays));
        }

        return $faults;
    }

    public static function isLate(\DateTimeImmutable $hiredOn, \DateTimeImmutable $declaredOn): bool
    {
        return $declaredOn > $hiredOn;
    }
}
