<?php

declare(strict_types=1);

namespace App\Service\Dossier\Search;

/**
 * The groups of the global search (Ctrl K, design-ux.md § 2.1), in the order they are drawn.
 * « Pièces » joins them with the documents (step 2.1).
 */
enum SearchGroup: string
{
    case Recent = 'recent';
    case Screens = 'screens';
    case Accounts = 'accounts';
    case ThirdParties = 'third_parties';
    case Entries = 'entries';
    case Actions = 'actions';

    public function label(): string
    {
        return match ($this) {
            self::Recent => 'Récents',
            self::Screens => 'Écrans',
            self::Accounts => 'Comptes',
            self::ThirdParties => 'Tiers',
            self::Entries => 'Écritures',
            self::Actions => 'Actions',
        };
    }
}
