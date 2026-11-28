<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Enum\Payroll\PayrollRateSource;
use App\Service\Shared\Money;
use Doctrine\ORM\Mapping as ORM;

/**
 * One side of an item of the plan de paie - the employee's or the employer's: where its rate comes
 * from, and what that source needs - a regulatory value's key and path (« social.contrib.health »,
 * « employer_rate »; « social.agirc_arrco.ceg », « 0.employee_rate » in a list), a percent of the
 * dossier (« 1.5 » for 1,50 %, exact decimal text), or an amount of the dossier in cents. Immutable;
 * PayrollItemRules checks it before it is made.
 */
#[ORM\Embeddable]
final class PayrollRate
{
    #[ORM\Column(length: 24, enumType: PayrollRateSource::class)]
    private PayrollRateSource $source;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $referenceKey;

    /** The path inside the value, its segments separated by dots; empty for a value that is a number. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $referencePath;

    /** A percent as an exact decimal text: « 1.5 » is 1,50 %. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $percent;

    #[ORM\Column(type: 'money', nullable: true)]
    private ?Money $amount;

    public function __construct(PayrollRateSource $source, ?string $referenceKey = null, ?string $referencePath = null, ?string $percent = null, ?Money $amount = null)
    {
        if ($source->needsReference() !== (null !== $referenceKey) || (null === $referenceKey && null !== $referencePath)
            || $source->needsPercent() !== (null !== $percent) || $source->needsAmount() !== (null !== $amount)) {
            throw new \InvalidArgumentException('A payroll rate carries what its source needs, and nothing else.');
        }
        $this->source = $source;
        $this->referenceKey = $referenceKey;
        $this->referencePath = $referencePath;
        $this->percent = $percent;
        $this->amount = $amount;
    }

    public static function none(): self
    {
        return new self(PayrollRateSource::None);
    }

    public function getSource(): PayrollRateSource
    {
        return $this->source;
    }

    public function getReferenceKey(): ?string
    {
        return $this->referenceKey;
    }

    public function getReferencePath(): ?string
    {
        return $this->referencePath;
    }

    /**
     * The path as RegulatorySnapshot reads it: a segment of digits is a position in a list.
     *
     * @return list<int|string>
     */
    public function pathSegments(): array
    {
        if (null === $this->referencePath || '' === $this->referencePath) {
            return [];
        }

        return array_map(static fn (string $segment): int|string => 1 === preg_match('/^\d+$/', $segment) ? (int) $segment : $segment, explode('.', $this->referencePath));
    }

    public function getPercent(): ?string
    {
        return $this->percent;
    }

    public function getAmount(): ?Money
    {
        return $this->amount;
    }

    public function isNone(): bool
    {
        return PayrollRateSource::None === $this->source;
    }

    public function equals(self $other): bool
    {
        return $this->source === $other->source && $this->referenceKey === $other->referenceKey
            && $this->referencePath === $other->referencePath && $this->percent === $other->percent
            && $this->amount?->cents === $other->amount?->cents;
    }
}
