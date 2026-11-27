<?php

declare(strict_types=1);

namespace App\Service\Identity;

/** What DirectoryProbe found, in the operator's words (`app:ldap:check`). */
enum DirectoryProbeOutcome
{
    case NotConfigured;
    case Unreachable;
    case Refused;
    case BaseUnreadable;
    case Bound;

    public function isSuccess(): bool
    {
        return self::Bound === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Aucun annuaire configuré (LDAP_HOST est vide).',
            self::Unreachable => 'L’annuaire ne répond pas (adresse, port, pare-feu, chiffrement ou certificat).',
            self::Refused => 'L’annuaire répond, mais refuse le compte de service (LDAP_SEARCH_DN ou LDAP_SEARCH_PASSWORD).',
            self::BaseUnreadable => 'Le compte de service se lie, mais ne lit pas la base de recherche (LDAP_BASE_DN).',
            self::Bound => 'L’annuaire répond et le compte de service se lie.',
        };
    }
}
