<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Service\Shared\DateValue;

/**
 * The days a consultation reads (lot 1, step 1.5): from `from` to `to` inclusive. Left empty, a
 * bound is the screen's default - the first day of the exercise and the dossier's date, never the
 * computer's -; a bound that is not a day, or a window that ends before it starts, is said in a
 * sentence and the defaults are read instead.
 */
final readonly class ConsultationWindow
{
    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?string $error,
    ) {
    }

    public static function of(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('A window starts before it ends.');
        }

        return new self($from, $to, null);
    }

    /** @param string $fromIso « 2026-01-01 » as the date field's hidden input carries it, or empty */
    public static function read(string $fromIso, string $toIso, \DateTimeImmutable $defaultFrom, \DateTimeImmutable $defaultTo): self
    {
        $defaults = $defaultFrom <= $defaultTo ? [$defaultFrom, $defaultTo] : [$defaultTo, $defaultFrom];
        $from = '' === trim($fromIso) ? $defaults[0] : DateValue::parse(trim($fromIso));
        $to = '' === trim($toIso) ? $defaults[1] : DateValue::parse(trim($toIso));
        if (null === $from || null === $to) {
            return new self($defaults[0], $defaults[1], 'Une date de la période doit s’écrire jj/mm/aaaa, par exemple 31/01/2026.');
        }
        if ($from > $to) {
            return new self($defaults[0], $defaults[1], \sprintf('La période commence le %s, après sa fin, le %s.', $from->format('d/m/Y'), $to->format('d/m/Y')));
        }

        return new self($from, $to, null);
    }

    /** Whether the window is the screen's default one (no « Réinitialiser » to offer). */
    public function isDefault(\DateTimeImmutable $defaultFrom, \DateTimeImmutable $defaultTo): bool
    {
        return $this->from == $defaultFrom && $this->to == $defaultTo;
    }
}
