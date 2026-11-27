<?php

declare(strict_types=1);

namespace App\Entity\Accounting;

use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Accounting\EntrySide;
use App\Enum\Accounting\TemplateAmount;
use App\Service\Shared\Money;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of an entry template, as the entry grid types one (design-ux.md § 5.2): `account` holds an
 * account number or a third party's auxiliary code (« 401SCIV »), `thirdParty` the third party of a
 * collective account typed by number - empty on a collective, the call asks it. Text, not a key:
 * the account or the third party is looked up at each call, like a line of the grid, and a template
 * whose account no longer exists says so then (and in its list).
 */
#[ORM\Entity]
#[ORM\Table(name: 'entry_template_line')]
class EntryTemplateLine implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EntryTemplate $template;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 17)]
    private string $account = '';

    #[ORM\Column(length: 17)]
    private string $thirdParty = '';

    #[ORM\Column(length: 250)]
    private string $label = '';

    #[ORM\Column(length: 6, enumType: EntrySide::class)]
    private EntrySide $side = EntrySide::Debit;

    #[ORM\Column(length: 8, enumType: TemplateAmount::class)]
    private TemplateAmount $amount = TemplateAmount::Typed;

    #[ORM\Column(type: 'money', nullable: true)]
    private ?Money $fixed = null;

    public function __construct(EntryTemplate $template, int $position)
    {
        $this->dossier = $template->getDossier();
        $this->template = $template;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): EntryTemplate
    {
        return $this->template;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getAccount(): string
    {
        return $this->account;
    }

    public function getThirdParty(): string
    {
        return $this->thirdParty;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSide(): EntrySide
    {
        return $this->side;
    }

    public function getAmount(): TemplateAmount
    {
        return $this->amount;
    }

    public function getFixed(): ?Money
    {
        return $this->fixed;
    }

    public function describe(int $position, string $account, string $thirdParty, string $label, EntrySide $side, TemplateAmount $amount, ?Money $fixed): void
    {
        $this->position = $position;
        $this->account = $account;
        $this->thirdParty = $thirdParty;
        $this->label = $label;
        $this->side = $side;
        $this->amount = $amount;
        $this->fixed = TemplateAmount::Fixed === $amount ? $fixed : null;
    }
}
