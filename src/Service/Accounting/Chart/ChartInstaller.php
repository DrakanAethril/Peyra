<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountNature;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\DossierContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives a dossier its plan of accounts (lot 1: « un dossier en reçoit une copie qu'il peut
 * étendre »): every account of three digits and more of a reference chart, padded to six digits,
 * lettrable and collective by their defaults; the chart's code is kept on the dossier. Only into an
 * empty plan - a plan already there is the dossier's own. Enters the dossier's context for its
 * reading; the caller flushes.
 */
final readonly class ChartInstaller
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountRepository $accounts,
        private AuditTrail $auditTrail,
        private DossierContext $context,
    ) {
    }

    /** @return int the number of accounts copied */
    public function install(Dossier $dossier, ReferenceChart $chart, User|string $actor): int
    {
        if (0 !== $this->context->within($dossier, fn (): int => $this->accounts->countPlan($dossier))) {
            throw new \LogicException('The dossier already has a plan of accounts.');
        }
        $copied = 0;
        foreach ($chart->copiedAccounts() as $reference) {
            $number = $reference->dossierNumber();
            $this->entityManager->persist(new Account(
                $dossier,
                $number,
                $reference->label,
                AccountNature::of($number)->isLettrableByDefault(),
                AccountNumber::isCollectiveByDefault($number),
                AccountOrigin::Reference,
            ));
            ++$copied;
        }
        $dossier->usesChart($chart->code);
        $this->auditTrail->record(
            AuditAction::ChartInstalled,
            $actor,
            'dossier',
            (string) $dossier->getId(),
            \sprintf('Plan de comptes %s installé dans %s (%d comptes)', $chart->label, $dossier->getName(), $copied),
            ['dossier' => $dossier->getId(), 'chart' => $chart->code, 'accounts' => $copied],
        );

        return $copied;
    }
}
