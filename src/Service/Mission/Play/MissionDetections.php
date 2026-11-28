<?php

declare(strict_types=1);

namespace App\Service\Mission\Play;

use App\Entity\Accounting\Entry;
use App\Entity\Banking\BankStatement;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\EntryState;
use App\Enum\Mission\MissionTaskCheck;
use App\Repository\Document\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the dossier itself says of the tasks it can tick (step 2.9b, MissionTaskCheck), read in its
 * context: every pièce that has arrived is posted or discarded (and one has arrived), no entry left
 * in the brouillard, every bank statement reconciled (and one imported). One count each, asked only
 * for the checks the mission uses.
 */
final readonly class MissionDetections
{
    public function __construct(private EntityManagerInterface $entityManager, private DocumentRepository $documents)
    {
    }

    /**
     * @param list<MissionTaskCheck> $checks
     *
     * @return array<string, bool> by MissionTaskCheck value
     */
    public function of(Dossier $dossier, array $checks): array
    {
        $detected = [];
        foreach ($checks as $check) {
            $detected[$check->value] ??= match ($check) {
                MissionTaskCheck::Declared => false,
                MissionTaskCheck::Pieces => $this->documents->countArrived($dossier) > 0 && 0 === $this->documents->countToProcess($dossier),
                MissionTaskCheck::Drafts => 0 === $this->count(Entry::class, $dossier, 'x.state = :draft', ['draft' => EntryState::Draft]),
                MissionTaskCheck::Statements => $this->count(BankStatement::class, $dossier) > 0 && 0 === $this->count(BankStatement::class, $dossier, 'x.reconciledOn IS NULL'),
            };
        }

        return $detected;
    }

    /**
     * @param class-string         $entity
     * @param array<string, mixed> $parameters
     */
    private function count(string $entity, Dossier $dossier, ?string $where = null, array $parameters = []): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(x.id)')
            ->from($entity, 'x')
            ->andWhere('x.dossier = :dossier')->setParameter('dossier', $dossier);
        if (null !== $where) {
            $query->andWhere($where);
        }
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        $count = $query->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }
}
