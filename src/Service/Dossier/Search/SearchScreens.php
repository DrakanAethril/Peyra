<?php

declare(strict_types=1);

namespace App\Service\Dossier\Search;

use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\JournalType;
use App\Repository\Accounting\JournalRepository;
use App\Security\Voter\DossierRightVoter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The screens and actions of a dossier the global search offers (Ctrl K, design-ux.md § 2.1): the
 * catalogue of what exists today - the dashboard, Pièces › Boîte de réception, Comptabilité's screens (Saisie, one line per
 * journal, the guided entry when it is open to the person, À-nouveaux, Brouillard, Consultation,
 * Lettrage, Recherche d'écritures, the five editions, the five views of the settings), Immobilisations et clôture › FEC, Paie et social's
 * three views of its settings (to whoever reads the payroll), Système d'information ›
 * Société, Utilisateurs et droits, Piste d'audit - and, for whoever may change the dossier, the
 * actions that create (« Déposer une pièce », « Nouvelle écriture », « Nouveau compte »…). The search never offers a
 * module a mission closes, nor what a profile closes (a journal it does not open, the settings'
 * actions it does not change: DossierRightVoter); the missions (step 2.9) narrow this catalogue
 * too. Read inside the dossier's context.
 *
 * A screen's key is its route, plus the journal for the grid of one journal
 * (« app_accounting_entry:ACH »): what RecentScreens keeps.
 */
final readonly class SearchScreens
{
    /** The routes of the catalogue's screens: those a visit records among the recent ones. */
    public const array SCREEN_ROUTES = [
        'app_dossier_dashboard', 'app_documents', 'app_sales_customers', 'app_sales_documents', 'app_purchase_suppliers', 'app_purchase_invoices', 'app_inventory_stock_movements', 'app_inventory_stock_state', 'app_inventory_stock_counts', 'app_inventory_articles', 'app_accounting_entry', 'app_accounting_guided', 'app_accounting_opening', 'app_accounting_brouillard',
        'app_accounting_consultation', 'app_accounting_lettering', 'app_accounting_entries', 'app_accounting_edition_journals',
        'app_accounting_edition_general_ledger', 'app_accounting_edition_trial_balance',
        'app_accounting_edition_third_party_balance', 'app_accounting_edition_aged_balance',
        'app_accounting_accounts', 'app_accounting_journals', 'app_third_parties', 'app_accounting_templates',
        'app_accounting_subscriptions', 'app_bank_statements', 'app_bank_reconciliation', 'app_bank_rules', 'app_closing_fec', 'app_dossier_company', 'app_dossier_users', 'app_dossier_audit',
        'app_dossier_restore_points', 'app_payment_proposal', 'app_payroll_employees', 'app_payroll_register', 'app_payroll_items', 'app_payroll_bodies', 'app_payroll_establishment',
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
    private const string PAYROLL_SETTINGS = 'Paie et social › Paramètres de paie';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private JournalRepository $journals,
        private AuthorizationCheckerInterface $authorization,
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
     * @param bool $guidedOpen whether the guided entry is open to the person (Assistance::opensGuidedEntry())
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
            $screen('app_documents', 'Boîte de réception', 'Pièces', 'inbox', 'pieces factures tickets a traiter'),
        ];
        if ($this->authorization->isGranted(DossierRightVoter::SALES_READ, $dossier)) {
            $items[] = $screen('app_sales_customers', 'Clients', 'Ventes', 'tag', 'clients encours');
            $items[] = $screen('app_sales_documents', 'Devis, commandes, livraisons', 'Ventes', 'tag', 'devis commande bon de livraison', ['side' => 'sales', 'type' => 'quote']);
            $items[] = new SearchItem(SearchGroup::Screens, 'Factures, avoirs, acomptes', $this->urls->generate('app_sales_documents', $id + ['side' => 'sales', 'type' => 'invoice']), 'Ventes', icon: 'tag', key: 'app_sales_documents:invoice', terms: 'factures de vente avoirs acomptes facturation');
            $items[] = new SearchItem(SearchGroup::Screens, 'Échéancier clients', $this->urls->generate('app_due_schedule', $id + ['side' => 'sales']), 'Ventes', icon: 'tag', key: 'app_due_schedule:sales', terms: 'echeancier suivi creances echues encours clients retard');
        }
        if ($this->authorization->isGranted(DossierRightVoter::PURCHASES_READ, $dossier)) {
            $items[] = $screen('app_purchase_suppliers', 'Fournisseurs', 'Achats et stocks', 'package', 'fournisseurs dettes');
            $items[] = new SearchItem(SearchGroup::Screens, 'Commandes, réceptions', $this->urls->generate('app_sales_documents', $id + ['side' => 'purchases', 'type' => 'purchase_order']), 'Achats et stocks', icon: 'package', key: 'app_sales_documents:purchases', terms: 'commandes fournisseurs bons de reception demandes de prix achats');
            $items[] = $screen('app_purchase_invoices', 'Factures et avoirs reçus', 'Achats et stocks', 'package', 'factures fournisseurs rapprochement receptions non facturees factures non parvenues');
            $items[] = new SearchItem(SearchGroup::Screens, 'Échéancier fournisseurs', $this->urls->generate('app_due_schedule', $id + ['side' => 'purchases']), 'Achats et stocks', icon: 'package', key: 'app_due_schedule:purchases', terms: 'echeancier dettes fournisseurs echues encours');
            $items[] = $screen('app_payment_proposal', 'Proposition de paiement', 'Achats et stocks', 'package', 'bon a payer virements fournisseurs reglements campagne de paiement');
            $items[] = $screen('app_inventory_stock_movements', 'Mouvements de stock', 'Achats et stocks', 'package', 'stocks entrees sorties transferts ajustements fiche de stock');
            $items[] = $screen('app_inventory_stock_state', 'État du stock', 'Achats et stocks', 'package', 'stocks valorisation cmup peps rupture minimum inventaire');
            $items[] = $screen('app_inventory_stock_counts', 'Inventaire physique', 'Achats et stocks', 'package', 'stocks inventaire comptage ecarts ajustements');
        }
        $items = [
            ...$items,
            $screen('app_inventory_articles', 'Articles', 'Achats et stocks', 'package', 'articles produits references prix'),
            $screen('app_accounting_entry', 'Saisie par journal', self::ENTRY, 'book-text', 'ecriture grille comptabilite'),
        ];
        foreach ($this->journals->findByDossier($dossier) as $journal) {
            if (!$this->authorization->isGranted(DossierRightVoter::JOURNAL_READ, $journal)) {
                continue;
            }
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
        $items[] = $screen('app_accounting_opening', 'À-nouveaux', self::ENTRY, 'book-text', 'a nouveaux balance ouverture exercice an');

        $payroll = !$this->authorization->isGranted(DossierRightVoter::PAYROLL_READ, $dossier) ? [] : [
            $screen('app_payroll_employees', 'Salariés', 'Paie et social', 'users', 'personnel contrat embauche fiche salarie'),
            $screen('app_payroll_register', 'Registre unique du personnel', 'Paie et social › Salariés', 'users', 'registre personnel embauche entree sortie'),
            $screen('app_payroll_items', 'Plan de paie', self::PAYROLL_SETTINGS, 'users', 'rubriques cotisations taux salaire'),
            $screen('app_payroll_bodies', 'Organismes sociaux', self::PAYROLL_SETTINGS, 'users', 'urssaf retraite prevoyance mutuelle'),
            $screen('app_payroll_establishment', 'Établissement', self::PAYROLL_SETTINGS, 'users', 'effectif accident du travail versement mobilite paie'),
        ];

        return [
            ...$items,
            $screen('app_accounting_brouillard', 'Brouillard', 'Comptabilité', 'book-text', 'valider ecritures'),
            $screen('app_accounting_consultation', 'Consultation d’un compte ou d’un tiers', self::CONSULTATION, 'search', 'interroger extrait'),
            $screen('app_accounting_lettering', 'Lettrage', self::CONSULTATION, 'link-2', 'lettrer delettrer factures reglements rapprocher'),
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
            $screen('app_bank_statements', 'Relevés bancaires', 'Banque', 'landmark', 'releve banque importer csv'),
            $screen('app_bank_reconciliation', 'Rapprochement bancaire', 'Banque', 'landmark', 'rapprocher pointer etat de rapprochement releve banque'),
            $screen('app_bank_rules', 'Règles d’affectation', 'Banque', 'landmark', 'regle libelle compte rapprochement banque'),
            $screen('app_closing_fec', 'FEC', 'Immobilisations et clôture › Clôture', 'lock', 'fichier des ecritures comptables export controle fiscal conformite archivage telecharger'),
            ...$payroll,
            $screen('app_dossier_company', 'Société', 'Système d’information', 'database', 'entreprise siret dossier'),
            $screen('app_dossier_users', 'Utilisateurs et droits', 'Système d’information', 'users', 'profils membres groupe matrice'),
            $screen('app_dossier_audit', 'Piste d’audit', 'Système d’information', 'history', 'historique journal qui quoi quand'),
            $screen('app_dossier_restore_points', 'Points de restauration', 'Système d’information', 'history', 'sauvegarde revenir instantane'),
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

        $actions = [
            $action('app_document_new', 'Déposer une pièce', 'Pièces', 'upload', 'ajouter facture ticket pdf image'),
            $action('app_accounting_entry', 'Nouvelle écriture', self::ENTRY, 'plus', 'creer saisir ajouter'),
            $action('app_dossier_restore_point_new', 'Poser un point de restauration', 'Système d’information', 'history', 'sauvegarde instantane'),
        ];
        foreach ($this->journals->findByType($dossier, JournalType::Bank) as $journal) {
            if ($this->authorization->isGranted(DossierRightVoter::JOURNAL_ENTER, $journal)) {
                $actions[] = $action('app_bank_statement_import', 'Importer un relevé', 'Banque', 'upload', 'releve banque csv rapprochement');
                break;
            }
        }
        if ($this->authorization->isGranted(DossierRightVoter::PAYROLL_ENTER, $dossier)) {
            $actions[] = $action('app_payroll_employee_new', 'Nouveau salarié', 'Paie et social', 'plus', 'creer ajouter embauche contrat');
        }
        if ($this->authorization->isGranted(DossierRightVoter::PAYROLL_SETTINGS, $dossier)) {
            $actions[] = $action('app_payroll_item_new', 'Nouvelle rubrique de paie', self::PAYROLL_SETTINGS, 'plus', 'creer ajouter cotisation');
            $actions[] = $action('app_payroll_body_new', 'Nouvel organisme social', self::PAYROLL_SETTINGS, 'plus', 'creer ajouter urssaf retraite');
        }
        if (!$this->authorization->isGranted(DossierRightVoter::SETTINGS, $dossier)) {
            return $actions;
        }

        return [
            ...$actions,
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
