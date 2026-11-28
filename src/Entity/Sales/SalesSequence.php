<?php

declare(strict_types=1);

namespace App\Entity\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\Sales\SalesSequenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The numbering of one type of commercial document in one year (step 2.3): the last number given
 * and the date of the document that took it. A document takes `last + 1` at its validation, read
 * under a lock on this row, and is never deleted afterwards: the numbering is continuous and
 * without a gap. The demonstration starts its invoices after the ones its books already carry.
 */
#[ORM\Entity(repositoryClass: SalesSequenceRepository::class)]
#[ORM\Table(name: 'sales_sequence')]
#[ORM\UniqueConstraint(name: 'uniq_sales_sequence', columns: ['dossier_id', 'type', 'year'])]
class SalesSequence implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: SalesDocumentType::class)]
    private SalesDocumentType $type;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $year;

    #[ORM\Column]
    private int $lastNumber;

    /** The date of the document numbered last: the next one may not be dated before it. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastDate;

    public function __construct(Dossier $dossier, SalesDocumentType $type, int $year, int $lastNumber = 0, ?\DateTimeImmutable $lastDate = null)
    {
        $this->dossier = $dossier;
        $this->type = $type;
        $this->year = $year;
        $this->lastNumber = $lastNumber;
        $this->lastDate = $lastDate;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): SalesDocumentType
    {
        return $this->type;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getLastNumber(): int
    {
        return $this->lastNumber;
    }

    public function getLastDate(): ?\DateTimeImmutable
    {
        return $this->lastDate;
    }

    /** The next number, taken by a document of that date (SalesDocuments::validate(), under the lock). */
    public function take(\DateTimeImmutable $date): int
    {
        if (null !== $this->lastDate && $date < $this->lastDate) {
            throw new \LogicException('A document may not be numbered before the last one.');
        }
        ++$this->lastNumber;
        $this->lastDate = $date;

        return $this->lastNumber;
    }

    /** « FV-2026-0001 » */
    public static function label(SalesDocumentType $type, int $year, int $number): string
    {
        return \sprintf('%s-%d-%04d', $type->prefix(), $year, $number);
    }
}
