<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

/**
 * The accounts an edition covers (grand livre, balance): « du compte » `from` « au compte » `to`,
 * each a number or the start of one, both bounds included and read as the plan reads them - from
 * « 4 » to « 4 » is the whole of class 4, from « 401 » to « 411 » every account from 401000 to
 * 411999…. Empty, a bound is open. A bound that is not digits, or a range that ends before it
 * starts, is said in a sentence and the whole plan is read instead.
 */
final readonly class AccountRange
{
    private function __construct(
        public string $from,
        public string $to,
        public ?string $error,
    ) {
    }

    public static function all(): self
    {
        return new self('', '', null);
    }

    public static function read(string $from, string $to): self
    {
        $from = trim($from);
        $to = trim($to);
        if (('' !== $from && 1 !== preg_match('/^\d{1,8}$/', $from)) || ('' !== $to && 1 !== preg_match('/^\d{1,8}$/', $to))) {
            return new self('', '', 'Un compte de la sélection s’écrit en chiffres : 401, 401000 ou 6 pour toute la classe.');
        }
        if ('' !== $from && '' !== $to && str_pad($from, 8, '0') > str_pad($to, 8, '9')) {
            return new self('', '', \sprintf('La sélection commence au compte %s, après sa fin, le compte %s.', $from, $to));
        }

        return new self($from, $to, null);
    }

    public function isAll(): bool
    {
        return '' === $this->from && '' === $this->to;
    }

    public function contains(string $number): bool
    {
        if ('' !== $this->from && substr($number, 0, \strlen($this->from)) < $this->from) {
            return false;
        }

        return '' === $this->to || substr($number, 0, \strlen($this->to)) <= $this->to;
    }

    /** « comptes 401 à 411 », « comptes à partir de 6 », « comptes jusqu’à 5 », or null for the whole plan. */
    public function describe(): ?string
    {
        return match (true) {
            $this->isAll() => null,
            '' === $this->to => 'comptes à partir de '.$this->from,
            '' === $this->from => 'comptes jusqu’à '.$this->to,
            $this->from === $this->to => 'comptes '.$this->from,
            default => 'comptes '.$this->from.' à '.$this->to,
        };
    }
}
