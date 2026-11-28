<?php

declare(strict_types=1);

namespace App\Service\Banking\Rule;

use App\Entity\Banking\BankRule;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Template\VatRate;
use App\Service\Audit\AuditTrail;
use App\Service\Banking\BankingRefused;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the assignment rules (step 2.6a; design-ux.md § 3.2, « Règles d'affectation »):
 * a rule is a text the wording must contain, an account of the plan or a third party's auxiliary
 * code, an optional VAT rate as its author writes it and an optional label. Each gesture in the
 * dossier's trail. Inside the dossier's context.
 */
final readonly class BankRules
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountRepository $accounts,
        private ThirdPartyRepository $thirdParties,
        private AuditTrail $auditTrail,
    ) {
    }

    /** A new rule (`rule` null) or a rule changed; a refusal names its faults by field. */
    public function save(Dossier $dossier, ?BankRule $rule, string $pattern, string $account, string $vatRate, string $label, User|string $actor): BankRule
    {
        $pattern = trim(preg_replace('/\s+/u', ' ', $pattern) ?? '');
        $account = mb_strtoupper(trim($account));
        $label = trim($label);
        $errors = [];
        if ('' === $pattern) {
            $errors['pattern'] = 'Indiquez le texte que le libellé de la ligne doit contenir.';
        } elseif (mb_strlen($pattern) > 60) {
            $errors['pattern'] = '60 caractères au plus.';
        }
        if ('' === $account) {
            $errors['account'] = 'Indiquez un compte, ou le code auxiliaire d’un tiers.';
        } elseif (1 === preg_match('/[A-Z]/', $account)) {
            if (null === $this->thirdParties->findOneByAuxiliaryCode($dossier, $account)) {
                $errors['account'] = \sprintf('Aucun tiers n’a le code auxiliaire %s.', $account);
            }
        } elseif (null === $this->accounts->findOneByNumber($dossier, $account)) {
            $errors['account'] = \sprintf('Le compte %s n’existe pas dans le plan.', $account);
        }
        $rate = null;
        if ('' !== trim($vatRate)) {
            $parsed = VatRate::parse($vatRate);
            if (null === $parsed) {
                $errors['vatRate'] = 'Un taux en pour cent : 20, 10, 5,5…';
            } else {
                $rate = VatRate::canonical($parsed);
            }
        }
        if (mb_strlen($label) > 60) {
            $errors['label'] = '60 caractères au plus.';
        }
        if ([] !== $errors) {
            throw new BankingRefused((string) reset($errors), $errors);
        }
        if (null === $rule) {
            $rule = new BankRule($dossier, $pattern, $account, $rate, $label);
            $this->entityManager->persist($rule);
        } else {
            $rule->describe($pattern, $account, $rate, $label);
        }
        $this->auditTrail->inDossier($dossier, AuditAction::BankRuleSaved, $actor, \sprintf('Règle d’affectation « %s » → %s%s', $pattern, $account, null === $rate ? '' : ', TVA '.str_replace('.', ',', $rate).' %'));
        $this->entityManager->flush();

        return $rule;
    }

    public function delete(BankRule $rule, User|string $actor): void
    {
        $this->auditTrail->inDossier($rule->getDossier(), AuditAction::BankRuleDeleted, $actor, \sprintf('Règle d’affectation « %s » supprimée', $rule->getPattern()));
        $this->entityManager->remove($rule);
        $this->entityManager->flush();
    }
}
