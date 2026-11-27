<?php

declare(strict_types=1);

namespace App\Entity\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Accounting\TemplateBase;
use App\Repository\Accounting\EntryTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry template of the dossier (« modèle d'écriture », « écriture type », design-ux.md § 3.2):
 * a journal and lines whose amounts are simple formulas - HT, TVA, TTC from the amount typed at the
 * call, a fixed amount, the balance, or nothing to type. Called with F6 in the entry grid, or chosen
 * in the guided entry; it writes nothing itself: its lines become an entry typed by the person.
 *
 * `base` says which amount the call asks (HT or TTC), `vatRate` the rate proposed then (« 20 »,
 * « 5.5 », an exact decimal as text: no float). TemplateSheet is the one writer.
 */
#[ORM\Entity(repositoryClass: EntryTemplateRepository::class)]
#[ORM\Table(name: 'entry_template')]
#[ORM\UniqueConstraint(name: 'uniq_entry_template_code', columns: ['dossier_id', 'code'])]
class EntryTemplate implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « LOYER »: capitals and digits, 1 to 12. */
    #[ORM\Column(length: 12)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $label;

    /** The journal it is entered in; a journal that has templates is not deleted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Journal $journal;

    #[ORM\Column(length: 3, nullable: true, enumType: TemplateBase::class)]
    private ?TemplateBase $base = null;

    /** The VAT rate proposed at the call, in percent (« 20 », « 5.5 »). */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $vatRate = null;

    /** @var Collection<int, EntryTemplateLine> */
    #[ORM\OneToMany(targetEntity: EntryTemplateLine::class, mappedBy: 'template', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    public function __construct(Dossier $dossier, string $code, string $label, Journal $journal)
    {
        $this->dossier = $dossier;
        $this->code = $code;
        $this->label = $label;
        $this->journal = $journal;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getJournal(): Journal
    {
        return $this->journal;
    }

    public function getBase(): ?TemplateBase
    {
        return $this->base;
    }

    public function getVatRate(): ?string
    {
        return $this->vatRate;
    }

    /** @return list<EntryTemplateLine> in their order */
    public function getLines(): array
    {
        $lines = $this->lines->toArray();
        usort($lines, static fn (EntryTemplateLine $a, EntryTemplateLine $b): int => $a->getPosition() <=> $b->getPosition());

        return $lines;
    }

    /** Whether a call asks for an amount: one of its lines is HT, TVA or TTC. */
    public function asksAmount(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->getAmount()->needsBase()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything at once, as TemplateSheet checked it: its lines are replaced whole (a line is
     * nothing on its own).
     *
     * @param list<array{account: string, thirdParty: string, label: string, side: \App\Enum\Accounting\EntrySide, amount: \App\Enum\Accounting\TemplateAmount, fixed: ?\App\Service\Shared\Money}> $lines
     */
    public function describe(string $code, string $label, Journal $journal, ?TemplateBase $base, ?string $vatRate, array $lines): void
    {
        $this->code = $code;
        $this->label = $label;
        $this->journal = $journal;
        $this->base = $base;
        $this->vatRate = $vatRate;
        $existing = $this->getLines();
        foreach ($lines as $position => $line) {
            $row = $existing[$position] ?? null;
            if (null === $row) {
                $row = new EntryTemplateLine($this, $position);
                $this->lines->add($row);
            }
            $row->describe($position, $line['account'], $line['thirdParty'], $line['label'], $line['side'], $line['amount'], $line['fixed']);
        }
        foreach (\array_slice($existing, \count($lines)) as $extra) {
            $this->lines->removeElement($extra);
        }
    }
}
