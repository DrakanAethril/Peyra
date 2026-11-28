<?php

declare(strict_types=1);

namespace App\Service\Dossier\Search;

use App\Entity\Dossier\Dossier;
use App\Repository\Accounting\JournalRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The screens and actions of a dossier the global search offers (Ctrl K, design-ux.md § 2.1): the
 * catalogue of what exists today - the dashboard, Comptabilité's screens (Saisie, one line per
 * journal, the guided entry when it is open to the person, Brouillard, Consultation, Recherche
 * d'écritures, the five editions, the five views of the settings), Système d'information ›
 * Société - and, for whoever may change the dossier, the actions that create (« Nouvelle
 * écriture », « Nouveau compte »…). The search never offers a module a mission closes: the
 * missions (step 2.9) narrow this catalogue. Read inside the dossier's context.
 *
 * A screen's key is its route, plus the journal for the grid of one journal
 * (« app_accounting_entry:ACH »): what RecentScreens keeps.
 */
final readonly class SearchScreens
{
    /** The routes of the catalogue's screens: those a visit records among the recent ones. */
    public const array SCREEN_ROUTES = [
        'app_dossier_dashboard', 'app_accounting_entry', 'app_accounting_guided', 'app_accounting_brouillard',
        'app_accounting_consultation', 'app_accounting_entries', 'app_accounting_edition_journals',
        'app_accounting_edition_general_ledger', 'app_accounting_edition_trial_balance',
        'app_accounting_edition_third_party_balance', 'app_accounting_edition_aged_balance',
        'app_accounting_accounts', 'app_accounting_journals', 'app_third_parties', 'app_accounting_templates',
        'app_accounting_subscriptions', 'app_dossier_company',
    ];

    /** What an empty search shows when nothing was opened yet. */
    public const array FIRST_SCREENS = [
        'app_accounting_entry', 'app_accounting_brouillard', 'app_accounting_consultation',
        'app_accounting_entries', 'app_accounting_edition_trial_balance',
    ];

    private const string ENTRY = 'Comptabilité › Saisie';
    private const string CONSULTATION = 'Comptabilité › Consultation';
    private const string EDITIONS = 'Comptabilité › Éditions';
    private const string SETTINGS = 'Comptabilité › Paramètres comptables';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private JournalRepository $journals,
    ) {
    }

    /** The recent-screens key of a visit, or null for a screen the catalogue does not list. */
    public static function keyOf(string $route, string $journal): ?string
    {
        if (!\in_array($route, self::SCREEN_ROUTES, true)) {
            return null;
        }
        $journal = mb_strtoupper(trim($journal));

        return 'app_accounting_entry' === $route && 1 === preg_match('/^[A-Z0-9]{1,6}$/', $journal) ? $route.':'.$journal : $route;
    }

    /**
     * @param bool $guidedOpen whether the guided entry is open to the person (GuidedEntry::isOpen)
     *
     * @return list<SearchItem> in the order of the rail and the module tabs
     */
    public function screens(Dossier $dossier, bool $canEdit, bool $guidedOpen): array
    {
        $id = ['dossier' => $dossier->getId()];
        $screen = fn (string $route, string $label, ?string $detail, string $icon, string $terms = '', array $query = []): SearchItem => new SearchItem(
            SearchGroup::Screens,
            $label,
            $this->urls->generate($route, $id + $query),
            $detail,
            icon: $icon,
            key: $route,
            terms: $terms,
        );

        $items = [
            $screen('app_dossier_dashboard', 'Tableau de bord', null, 'house', 'accueil'),
            $screen('app_accounting_entry', 'Saisie par journal', self::ENTRY, 'book-text', 'ecriture grille comptabilite'),
        ];
        foreach ($this->journals->findByDossier($dossier) as $journal) {
            $items[] = new SearchItem(
                SearchGroup::Screens,
                $journal->getLabel(),
                $this->urls->generate('app_accounting_entry', $id + ['journal' => $journal->getCode()]),
                self::ENTRY,
                $journal->getCode(),
                icon: 'book-text',
                key: 'app_accounting_entry:'.$journal->getCode(),
                terms: 'saisie journal',
            );
        }
        if ($canEdit && $guidedOpen) {
            $items[] = $screen('app_accounting_guided', 'Saisie guidée', self::ENTRY, 'book-text', 'modele');
        }

        return [
            ...$items,
            $screen('app_accounting_brouillard', 'Brouillard', 'Comptabilité', 'book-text', 'valider ecritures'),
            $screen('app_accounting_consultation', 'Consultation d’un compte ou d’un tiers', self::CONSULTATION, 'search', 'interroger extrait'),
            $screen('app_accounting_entries', 'Recherche d’écritures', self::CONSULTATION, 'search', 'interroger journal piece'),
            $screen('app_accounting_edition_journals', 'Journaux', self::EDITIONS, 'printer', 'edition imprimer'),
            $screen('app_accounting_edition_general_ledger', 'Grand livre', self::EDITIONS, 'printer', 'edition imprimer'),
            $screen('app_accounting_edition_trial_balance', 'Balance', self::EDITIONS, 'printer', 'generale edition imprimer'),
            $screen('app_accounting_edition_third_party_balance', 'Balance auxiliaire', self::EDITIONS, 'printer', 'tiers clients fournisseurs edition'),
            $screen('app_accounting_edition_aged_balance', 'Balance âgée', self::EDITIONS, 'printer', 'echeances retard edition'),
            $screen('app_accounting_accounts', 'Plan comptable', self::SETTINGS, 'settings', 'comptes pcg'),
            $screen('app_accounting_journals', 'Journaux', self::SETTINGS, 'settings', 'parametres'),
            $screen('app_third_parties', 'Tiers', self::SETTINGS, 'settings', 'clients fournisseurs'),
            $screen('app_accounting_templates', 'Modèles d’écriture', self::SETTINGS, 'settings', 'saisie f6'),
            $screen('app_accounting_subscriptions', 'Abonnements', self::SETTINGS, 'settings', 'loyer echeances generer'),
            $screen('app_dossier_company', 'Société', 'Système d’information', 'database', 'entreprise siret dossier'),
        ];
    }

    /** @return list<SearchItem> what creates, for whoever may change the dossier */
    public function actions(Dossier $dossier, bool $canEdit): array
    {
        if (!$canEdit) {
            return [];
        }
        $id = ['dossier' => $dossier->getId()];
        $action = fn (string $route, string $label, ?string $detail, string $icon, string $terms): SearchItem => new SearchItem(
            SearchGroup::Actions,
            $label,
            $this->urls->generate($route, $id),
            $detail,
            icon: $icon,
            key: $route,
            terms: $terms,
        );

        return [
            $action('app_accounting_entry', 'Nouvelle écriture', self::ENTRY, 'plus', 'creer saisir ajouter'),
            $action('app_accounting_account_new', 'Nouveau compte', self::SETTINGS, 'plus', 'creer ajouter plan'),
            $action('app_third_party_new', 'Nouveau tiers', self::SETTINGS, 'plus', 'creer ajouter client fournisseur'),
            $action('app_accounting_journal_new', 'Nouveau journal', self::SETTINGS, 'plus', 'creer ajouter'),
            $action('app_accounting_template_new', 'Nouveau modèle d’écriture', self::SETTINGS, 'plus', 'creer ajouter'),
            $action('app_accounting_subscription_new', 'Nouvel abonnement', self::SETTINGS, 'plus', 'creer ajouter'),
            $action('app_accounting_accounts_import', 'Importer un plan comptable', self::SETTINGS, 'upload', 'csv comptes'),
            $action('app_third_parties_import', 'Importer des tiers', self::SETTINGS, 'upload', 'csv clients fournisseurs'),
        ];
    }
}
