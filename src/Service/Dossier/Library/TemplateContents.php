<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntrySubscription;
use App\Entity\Accounting\EntryTemplate;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\EntryState;
use App\Service\Dossier\DossierContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a template holds, counted for its fiche and for the récapitulatif before it is deleted
 * (design-ux.md § 6.2, « qui compte ce qui change »).
 */
final readonly class TemplateContents
{
    public function __construct(private EntityManagerInterface $entityManager, private DossierContext $context)
    {
    }

    /** @return array{exercises: int, accounts: int, journals: int, thirdParties: int, validated: int, drafts: int, entryTemplates: int, subscriptions: int} */
    public function of(Dossier $dossier): array
    {
        return $this->context->within($dossier, fn (): array => [
            'exercises' => $this->entityManager->getRepository(Exercise::class)->count([]),
            'accounts' => $this->entityManager->getRepository(Account::class)->count([]),
            'journals' => $this->entityManager->getRepository(Journal::class)->count([]),
            'thirdParties' => $this->entityManager->getRepository(ThirdParty::class)->count([]),
            'validated' => $this->entityManager->getRepository(Entry::class)->count(['state' => EntryState::Validated]),
            'drafts' => $this->entityManager->getRepository(Entry::class)->count(['state' => EntryState::Draft]),
            'entryTemplates' => $this->entityManager->getRepository(EntryTemplate::class)->count([]),
            'subscriptions' => $this->entityManager->getRepository(EntrySubscription::class)->count([]),
        ]);
    }
}
