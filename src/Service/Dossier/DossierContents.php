<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Entity\Accounting\EntrySubscription;
use App\Entity\Accounting\EntryTemplate;
use App\Entity\Accounting\Journal;
use App\Entity\Accounting\PendingEntry;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\EntryState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a dossier holds, counted (design-ux.md § 6.2, « qui compte ce qui change »): a template's
 * fiche and the récapitulatif before it is deleted, and what a return to a restore point changes -
 * the dossier now against the point's copy (step 1.8b).
 *
 * @phpstan-type Contents array{exercises: int, accounts: int, journals: int, thirdParties: int, validated: int, drafts: int, pending: int, lettered: int, entryTemplates: int, subscriptions: int}
 */
final readonly class DossierContents
{
    public function __construct(private EntityManagerInterface $entityManager, private DossierContext $context)
    {
    }

    /** @return Contents */
    public function of(Dossier $dossier): array
    {
        return $this->context->within($dossier, fn (): array => [
            'exercises' => $this->entityManager->getRepository(Exercise::class)->count([]),
            'accounts' => $this->entityManager->getRepository(Account::class)->count([]),
            'journals' => $this->entityManager->getRepository(Journal::class)->count([]),
            'thirdParties' => $this->entityManager->getRepository(ThirdParty::class)->count([]),
            'validated' => $this->entityManager->getRepository(Entry::class)->count(['state' => EntryState::Validated]),
            'drafts' => $this->entityManager->getRepository(Entry::class)->count(['state' => EntryState::Draft]),
            'pending' => $this->entityManager->getRepository(PendingEntry::class)->count([]),
            'lettered' => (int) $this->entityManager->getRepository(EntryLine::class)->createQueryBuilder('l')->select('COUNT(l.id)')->andWhere('l.letter IS NOT NULL')->getQuery()->getSingleScalarResult(),
            'entryTemplates' => $this->entityManager->getRepository(EntryTemplate::class)->count([]),
            'subscriptions' => $this->entityManager->getRepository(EntrySubscription::class)->count([]),
        ]);
    }
}
