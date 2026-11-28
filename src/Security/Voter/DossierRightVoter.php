<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Dossier\AccessLevel;
use App\Enum\Dossier\DossierArea;
use App\Service\Dossier\Rights\DossierRights;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * A dossier's rights area by area (lot 1, step 1.7), for the screens: DossierVoter says who works
 * in the dossier, the profile (App\Service\Dossier\Rights\DossierRights) how far.
 *
 * - SETTINGS (a dossier): change its settings - the fiche société, the plan, the journals, the third
 *   parties, the entry templates, the subscriptions;
 * - JOURNAL_READ (a journal): see its entry screen - a journal the profile closes is not offered;
 * - JOURNAL_ENTER: enter in it (pending work, drafts, F6, the opening balance);
 * - JOURNAL_VALIDATE: validate and reverse its entries;
 * - PAYROLL_READ (a dossier): open Paie et social - closed to a profile that does not read the
 *   payroll, whose rail then has no such entry;
 * - PAYROLL_SETTINGS: change the payroll's settings - the establishment, the bodies, the plan de paie;
 * - PAYROLL_ENTER: enter the payroll - the employees (step 4.2a);
 * - SALES_READ (a dossier): open Ventes - closed to a profile that does not read the sales;
 * - SALES_ENTER: write the commercial documents' drafts (step 2.3);
 * - SALES_VALIDATE: validate, transform and send them;
 * - PURCHASES_READ, PURCHASES_ENTER, PURCHASES_VALIDATE: the same for the purchasing documents - price
 *   requests, supplier orders, receipts (step 2.4), on the area Achats.
 *
 * The doors hold the same rules (Ledger, Lettering refuse a gesture the profile does not allow);
 * a refusal of a screen reads as a 404.
 *
 * @extends Voter<string, Dossier|Journal>
 */
final class DossierRightVoter extends Voter
{
    public const string SETTINGS = 'DOSSIER_SETTINGS';
    public const string JOURNAL_READ = 'JOURNAL_READ';
    public const string JOURNAL_ENTER = 'JOURNAL_ENTER';
    public const string JOURNAL_VALIDATE = 'JOURNAL_VALIDATE';
    public const string PAYROLL_READ = 'PAYROLL_READ';
    public const string PAYROLL_SETTINGS = 'PAYROLL_SETTINGS';
    public const string PAYROLL_ENTER = 'PAYROLL_ENTER';
    public const string SALES_READ = 'SALES_READ';
    public const string SALES_ENTER = 'SALES_ENTER';
    public const string SALES_VALIDATE = 'SALES_VALIDATE';
    public const string PURCHASES_READ = 'PURCHASES_READ';
    public const string PURCHASES_ENTER = 'PURCHASES_ENTER';
    public const string PURCHASES_VALIDATE = 'PURCHASES_VALIDATE';

    public function __construct(private readonly DossierVoter $dossiers, private readonly DossierRights $rights)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (\in_array($attribute, [self::SETTINGS, self::PAYROLL_READ, self::PAYROLL_SETTINGS, self::PAYROLL_ENTER, self::SALES_READ, self::SALES_ENTER, self::SALES_VALIDATE, self::PURCHASES_READ, self::PURCHASES_ENTER, self::PURCHASES_VALIDATE], true) && $subject instanceof Dossier)
            || (\in_array($attribute, [self::JOURNAL_READ, self::JOURNAL_ENTER, self::JOURNAL_VALIDATE], true) && $subject instanceof Journal);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $dossier = $subject instanceof Journal ? $subject->getDossier() : $subject;
        [$area, $wanted] = match ($attribute) {
            self::SETTINGS => [DossierArea::Settings, AccessLevel::Enter],
            self::PAYROLL_READ => [DossierArea::Payroll, AccessLevel::Read],
            self::PAYROLL_SETTINGS, self::PAYROLL_ENTER => [DossierArea::Payroll, AccessLevel::Enter],
            self::SALES_READ => [DossierArea::Sales, AccessLevel::Read],
            self::SALES_ENTER => [DossierArea::Sales, AccessLevel::Enter],
            self::SALES_VALIDATE => [DossierArea::Sales, AccessLevel::Validate],
            self::PURCHASES_READ => [DossierArea::Purchases, AccessLevel::Read],
            self::PURCHASES_ENTER => [DossierArea::Purchases, AccessLevel::Enter],
            self::PURCHASES_VALIDATE => [DossierArea::Purchases, AccessLevel::Validate],
            self::JOURNAL_READ => [null, AccessLevel::Read],
            self::JOURNAL_ENTER => [null, AccessLevel::Enter],
            default => [null, AccessLevel::Validate],
        };
        $area ??= $subject instanceof Journal ? DossierArea::ofJournal($subject->getType()) : DossierArea::Settings;
        $works = VoterInterface::ACCESS_GRANTED === $this->dossiers->vote($token, $dossier, [AccessLevel::Read === $wanted ? DossierVoter::VIEW : DossierVoter::EDIT]);

        return $works && $this->rights->allows($dossier, $user, $area, $wanted);
    }
}
