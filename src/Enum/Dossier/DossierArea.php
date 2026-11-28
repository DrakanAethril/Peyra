<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

use App\Enum\Accounting\JournalType;
use App\Enum\ThirdParty\ThirdPartyKind;

/**
 * A row of the rights matrix (design-ux.md § 3.2, « Utilisateurs et droits »): a part of the
 * dossier a profile opens or closes. The journals go by their type; the dossier's settings - the
 * fiche société, the plan, the journals, the third parties, the entry templates and the
 * subscriptions - are one area, kept apart from the entries (segregation of duties, P1.7). The
 * modules of the later lots add their areas here, and ProfileMatrix gives each its levels: « Paie
 * et social » (step 4.1) is the payroll manager's, read by nobody else of the group but the chief
 * accountant - salaries are not the accounts payable clerk's business.
 */
enum DossierArea: string
{
    case Purchases = 'purchases';
    case Sales = 'sales';
    case Treasury = 'treasury';
    case General = 'general';
    case Settings = 'settings';
    case Payroll = 'payroll';

    public function label(): string
    {
        return match ($this) {
            self::Purchases => 'Achats',
            self::Sales => 'Ventes',
            self::Treasury => 'Banque et caisse',
            self::General => 'Opérations diverses et à-nouveaux',
            self::Settings => 'Paramètres du dossier',
            self::Payroll => 'Paie et social',
        };
    }

    /** What it covers, under its label in the matrix. */
    public function covers(): string
    {
        return match ($this) {
            self::Purchases => 'documents d’achat, mouvements de stock, journaux d’achats, lettrage des fournisseurs',
            self::Sales => 'documents commerciaux, journaux de ventes, lettrage des clients',
            self::Treasury => 'journaux de banque et de caisse',
            self::General => 'opérations diverses, à-nouveaux, lettrage des autres comptes',
            self::Settings => 'société, plan de comptes, journaux, tiers, articles, modèles, abonnements',
            self::Payroll => 'paramètres de paie : établissement, organismes, plan de paie',
        };
    }

    /** The highest level it knows: the settings are changed, never validated (a payslip is: step 4.4). */
    public function topLevel(): AccessLevel
    {
        return self::Settings === $this ? AccessLevel::Enter : AccessLevel::Validate;
    }

    public static function ofJournal(JournalType $type): self
    {
        return match ($type) {
            JournalType::Purchases => self::Purchases,
            JournalType::Sales => self::Sales,
            JournalType::Bank, JournalType::Cash => self::Treasury,
            JournalType::General, JournalType::Opening => self::General,
        };
    }

    /** The area a lettering belongs to: a supplier's with the purchases, a customer's with the sales. */
    public static function ofLettering(?ThirdPartyKind $kind): self
    {
        return match ($kind) {
            ThirdPartyKind::Supplier => self::Purchases,
            ThirdPartyKind::Customer => self::Sales,
            default => self::General,
        };
    }
}
