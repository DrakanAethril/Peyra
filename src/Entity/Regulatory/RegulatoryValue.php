<?php

declare(strict_types=1);

namespace App\Entity\Regulatory;

use App\Entity\Identity\User;
use App\Entity\Shared\AppendOnly;
use App\Enum\Regulatory\RegulatoryDomain;
use App\Enum\Regulatory\RegulatoryOrigin;
use App\Enum\Regulatory\Verification;
use App\Repository\Regulatory\RegulatoryValueRepository;
use App\Service\Regulatory\RegulatoryFileEntry;
use App\Service\Regulatory\RegulatoryTree;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One dated value of a regulatory parameter (conception § 4: « clé, valeur, date d'effet »),
 * common to the establishment and read at the dossier's date (App\Service\Regulatory\RegulatoryReader).
 *
 * **Append-only** (design-ux.md § 3.3: « jamais de modification d'une valeur passée »): a row is
 * never changed nor deleted. A new value is a new effective date; a correction of the value of a
 * date is one more row at that same date, which replaces it for every reading while the older one
 * stays in the history. The value in force at a day is the latest effective date not after it,
 * and among the rows of that date the last one written.
 *
 * The value is RegulatoryTree's canonical JSON, numbers as exact decimal texts (no float column,
 * no float in it either). `millesime` is the file a row came from, or the year of its effective
 * date when an administrator entered it: the screen's filter.
 *
 * @phpstan-import-type Node from RegulatoryTree
 */
#[ORM\Entity(repositoryClass: RegulatoryValueRepository::class)]
#[ORM\Index(name: 'idx_regulatory_value_key_date', columns: ['value_key', 'effective_from'])]
#[ORM\Index(name: 'idx_regulatory_value_millesime', columns: ['millesime'])]
class RegulatoryValue implements AppendOnly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « social.smic_hourly »: `key` is a reserved word of MariaDB, hence the column's name. */
    #[ORM\Column(name: 'value_key', length: 120)]
    private string $key;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(name: 'value_text', type: Types::TEXT)]
    private string $value;

    /** As the millésime files write it: « percent », « EUR », « EUR/mois », « coefficient », « text »… */
    #[ORM\Column(length: 120)]
    private string $unit;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $effectiveFrom;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $sourceUrl;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consultedOn;

    #[ORM\Column]
    private bool $verified;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 20, enumType: RegulatoryOrigin::class)]
    private RegulatoryOrigin $origin;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $millesime;

    /** Real time of the writing: the history's, never a business date. */
    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    /** The administrator who entered it; null for a row of a millésime file. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $recordedBy;

    /** @param Node $tree */
    private function __construct(string $key, string $label, string|bool|array|null $tree, string $unit, \DateTimeImmutable $effectiveFrom, ?string $sourceUrl, ?\DateTimeImmutable $consultedOn, bool $verified, ?string $note, RegulatoryOrigin $origin, int $millesime, \DateTimeImmutable $recordedAt, ?User $recordedBy)
    {
        $this->key = $key;
        $this->label = $label;
        $this->value = RegulatoryTree::encode($tree);
        $this->unit = $unit;
        $this->effectiveFrom = $effectiveFrom;
        $this->sourceUrl = $sourceUrl;
        $this->consultedOn = $consultedOn;
        $this->verified = $verified;
        $this->note = $note;
        $this->origin = $origin;
        $this->millesime = $millesime;
        $this->recordedAt = $recordedAt;
        $this->recordedBy = $recordedBy;
    }

    public static function fromFile(RegulatoryFileEntry $entry, int $millesime, \DateTimeImmutable $recordedAt): self
    {
        return new self($entry->key, $entry->label, $entry->tree, $entry->unit, $entry->effectiveFrom, $entry->sourceUrl, $entry->consultedOn, $entry->verified, $entry->note, RegulatoryOrigin::File, $millesime, $recordedAt, null);
    }

    /** @param Node $tree */
    public static function byAdministrator(self $base, string|bool|array|null $tree, \DateTimeImmutable $effectiveFrom, ?string $sourceUrl, ?\DateTimeImmutable $consultedOn, bool $verified, ?string $note, User $administrator, \DateTimeImmutable $recordedAt): self
    {
        return new self($base->key, $base->label, $tree, $base->unit, $effectiveFrom, $sourceUrl, $consultedOn, $verified, $note, RegulatoryOrigin::Administrator, (int) $effectiveFrom->format('Y'), $recordedAt, $administrator);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getDomain(): ?RegulatoryDomain
    {
        return RegulatoryDomain::ofKey($this->key);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** @return Node */
    public function getTree(): string|bool|array|null
    {
        return RegulatoryTree::decode($this->value);
    }

    /** The canonical text of the value: two rows hold the same value when these are equal. */
    public function getValueText(): string
    {
        return $this->value;
    }

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function getEffectiveFrom(): \DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function getConsultedOn(): ?\DateTimeImmutable
    {
        return $this->consultedOn;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getVerification(): Verification
    {
        return Verification::of($this->verified);
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getOrigin(): RegulatoryOrigin
    {
        return $this->origin;
    }

    public function getMillesime(): int
    {
        return $this->millesime;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }
}
