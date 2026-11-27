<?php

declare(strict_types=1);

namespace App\Entity\ThirdParty;

use App\Enum\ThirdParty\PaymentTermKind;
use Doctrine\ORM\Mapping as ORM;

/**
 * A third party's payment term, structured so that a due date is computed rather than read
 * (step 2.7, the schedule and the reminders): its kind, and the number of days or the day of the
 * month it needs. Immutable; ThirdPartySheet checks it before it is made.
 */
#[ORM\Embeddable]
final class PaymentTerms
{
    #[ORM\Column(length: 20, enumType: PaymentTermKind::class)]
    private PaymentTermKind $kind;

    /** The N of « 30 jours date de facture », « 30 jours fin de mois », « fin de mois + 45 jours ». */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $days;

    /** The D of « le 10 du mois suivant ». */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $dayOfMonth;

    public const int MAX_DAYS = 120;

    public function __construct(PaymentTermKind $kind, ?int $days = null, ?int $dayOfMonth = null)
    {
        if ($kind->needsDays() !== (null !== $days) || $kind->needsDayOfMonth() !== (null !== $dayOfMonth)) {
            throw new \InvalidArgumentException('A payment term carries the number its kind needs, and no other.');
        }
        if (null !== $days && ($days < 1 || $days > self::MAX_DAYS)) {
            throw new \InvalidArgumentException('Days out of range.');
        }
        if (null !== $dayOfMonth && ($dayOfMonth < 1 || $dayOfMonth > 31)) {
            throw new \InvalidArgumentException('Day of month out of range.');
        }
        $this->kind = $kind;
        $this->days = $days;
        $this->dayOfMonth = $dayOfMonth;
    }

    /** The term a new third party starts with: thirty days from the invoice date. */
    public static function default(): self
    {
        return new self(PaymentTermKind::Net, 30);
    }

    public function getKind(): PaymentTermKind
    {
        return $this->kind;
    }

    public function getDays(): ?int
    {
        return $this->days;
    }

    public function getDayOfMonth(): ?int
    {
        return $this->dayOfMonth;
    }

    /** When an invoice of that date falls due. */
    public function dueDate(\DateTimeImmutable $invoiceDate): \DateTimeImmutable
    {
        $day = $invoiceDate->setTime(0, 0);

        return match ($this->kind) {
            PaymentTermKind::Immediate => $day,
            PaymentTermKind::Net => $day->modify(\sprintf('+%d days', $this->days)),
            PaymentTermKind::NetEndOfMonth => $day->modify(\sprintf('+%d days', $this->days))->modify('last day of this month'),
            PaymentTermKind::EndOfMonthNet => $day->modify('last day of this month')->modify(\sprintf('+%d days', $this->days)),
            PaymentTermKind::DayOfNextMonth => self::dayOfNextMonth($day, (int) $this->dayOfMonth),
        };
    }

    /** « 30 jours date de facture », « Fin de mois + 45 jours », « Le 1er du mois suivant »… */
    public function label(): string
    {
        return match ($this->kind) {
            PaymentTermKind::Immediate => 'Comptant',
            PaymentTermKind::Net => \sprintf('%d jours date de facture', $this->days),
            PaymentTermKind::NetEndOfMonth => \sprintf('%d jours fin de mois', $this->days),
            PaymentTermKind::EndOfMonthNet => \sprintf('Fin de mois + %d jours', $this->days),
            PaymentTermKind::DayOfNextMonth => \sprintf('Le %s du mois suivant', 1 === $this->dayOfMonth ? '1er' : (string) $this->dayOfMonth),
        };
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->days === $other->days && $this->dayOfMonth === $other->dayOfMonth;
    }

    /** Day D of the next month, or its last day when it is shorter (« le 31 » in February). */
    private static function dayOfNextMonth(\DateTimeImmutable $day, int $dayOfMonth): \DateTimeImmutable
    {
        $first = $day->modify('first day of next month');
        $length = (int) $first->format('t');

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($dayOfMonth, $length));
    }
}
