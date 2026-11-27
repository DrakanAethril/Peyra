<?php

declare(strict_types=1);

namespace App\Entity\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Accounting\SubscriptionTarget;
use App\Repository\Accounting\EntrySubscriptionRepository;
use App\Service\Shared\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A subscription of the dossier (« abonnement », step 1.4c): an entry template called again every
 * month - a rent, a leasing instalment - with the amount, the rate and the third party of its call,
 * on a day of the month (31: its last day), from a first month, for a number of occurrences. Each
 * occurrence gets its document from a pattern (« LOY-{AAAA}-{MM} ») and goes either to the pending
 * work of the entry grid or to the brouillard (SubscriptionTarget). `generated` counts the
 * occurrences already made: the next one is the occurrence `generated + 1`.
 *
 * SubscriptionSheet is the one writer of its description, SubscriptionRun the one that counts
 * generated occurrences. The template is a RESTRICT key: a template a subscription carries is not
 * deleted.
 */
#[ORM\Entity(repositoryClass: EntrySubscriptionRepository::class)]
#[ORM\Table(name: 'entry_subscription')]
class EntrySubscription implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private EntryTemplate $template;

    /** The amount of the call (HT or TTC, as the template asks); null when it asks none. */
    #[ORM\Column(type: 'money', nullable: true)]
    private ?Money $amount = null;

    /** The VAT rate of the call, in percent (« 20 », « 5.5 »); null when it asks no amount. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $vatRate = null;

    /** The third party the template asks, as the grid types it (auxiliary code or code); empty when it asks none. */
    #[ORM\Column(length: 17)]
    private string $thirdParty = '';

    /** 1 to 31; a day the month does not have is its last one (31: always the last). */
    #[ORM\Column]
    private int $dayOfMonth;

    /** The first day of the first month. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $firstMonth;

    #[ORM\Column]
    private int $occurrences;

    /** « LOY-{AAAA}-{MM} »: {AAAA} the year, {MM} the month, {N} the occurrence's rank. */
    #[ORM\Column(length: 40)]
    private string $documentPattern;

    #[ORM\Column(length: 7, enumType: SubscriptionTarget::class)]
    private SubscriptionTarget $target;

    /** Occurrences already generated, from the first (« generated » is a MariaDB keyword). */
    #[ORM\Column(name: 'generated_count')]
    private int $generated = 0;

    public function __construct(Dossier $dossier, EntryTemplate $template, \DateTimeImmutable $firstMonth)
    {
        $this->dossier = $dossier;
        $this->template = $template;
        $this->firstMonth = $firstMonth;
        $this->label = '';
        $this->dayOfMonth = 1;
        $this->occurrences = 1;
        $this->documentPattern = '';
        $this->target = SubscriptionTarget::Draft;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTemplate(): EntryTemplate
    {
        return $this->template;
    }

    public function getAmount(): ?Money
    {
        return $this->amount;
    }

    public function getVatRate(): ?string
    {
        return $this->vatRate;
    }

    public function getThirdParty(): string
    {
        return $this->thirdParty;
    }

    public function getDayOfMonth(): int
    {
        return $this->dayOfMonth;
    }

    public function getFirstMonth(): \DateTimeImmutable
    {
        return $this->firstMonth;
    }

    public function getOccurrences(): int
    {
        return $this->occurrences;
    }

    public function getDocumentPattern(): string
    {
        return $this->documentPattern;
    }

    public function getTarget(): SubscriptionTarget
    {
        return $this->target;
    }

    public function getGenerated(): int
    {
        return $this->generated;
    }

    /** Every occurrence generated. */
    public function isOver(): bool
    {
        return $this->generated >= $this->occurrences;
    }

    /** Everything at once, as SubscriptionSheet checked it. */
    public function describe(string $label, EntryTemplate $template, ?Money $amount, ?string $vatRate, string $thirdParty, int $dayOfMonth, \DateTimeImmutable $firstMonth, int $occurrences, string $documentPattern, SubscriptionTarget $target): void
    {
        $this->label = $label;
        $this->template = $template;
        $this->amount = $amount;
        $this->vatRate = $vatRate;
        $this->thirdParty = $thirdParty;
        $this->dayOfMonth = $dayOfMonth;
        $this->firstMonth = $firstMonth;
        $this->occurrences = $occurrences;
        $this->documentPattern = $documentPattern;
        $this->target = $target;
    }

    /** One more occurrence made (SubscriptionRun, under a lock on this row). */
    public function countGenerated(): void
    {
        if ($this->isOver()) {
            throw new \LogicException('Every occurrence of the subscription is already generated.');
        }
        ++$this->generated;
    }
}
