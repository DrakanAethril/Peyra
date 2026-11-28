<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Audit\AuditAction;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The marker « créance douteuse » of a customer (step 2.7): set or taken off from its fiche, a line
 * of the dossier's trail each time. A marker only - nothing is transferred to 416000 nor provided
 * for here: the inventory work of lot 3 reads it.
 */
final readonly class DoubtfulReceivables
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditTrail $auditTrail,
    ) {
    }

    /** Refused (LogicException) for anything but a customer: only a customer's debt is doubtful. */
    public function mark(ThirdParty $customer, bool $doubtful, User|string $actor): bool
    {
        if (ThirdPartyKind::Customer !== $customer->getKind()) {
            throw new \LogicException('Only a customer’s debt is doubtful.');
        }
        if ($customer->isDoubtful() === $doubtful) {
            return false;
        }
        $customer->markDoubtful($doubtful);
        $this->auditTrail->inDossier($customer->getDossier(), AuditAction::ThirdPartyDoubtful, $actor, \sprintf(
            $doubtful ? 'Créance de %s (%s) signalée douteuse' : 'Créance de %s (%s) : marqueur « douteuse » retiré',
            $customer->getName(),
            $customer->getCode(),
        ), ['code' => $customer->getCode(), 'doubtful' => $doubtful]);
        $this->entityManager->flush();

        return true;
    }
}
