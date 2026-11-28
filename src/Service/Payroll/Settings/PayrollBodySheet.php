<?php

declare(strict_types=1);

namespace App\Service\Payroll\Settings;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollBody;
use App\Enum\Audit\AuditAction;
use App\Enum\Payroll\PayrollBodyKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Service\Payroll\Plan\PayrollItemRules;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to the collecting bodies (Paie et social › Paramètres de paie › Organismes): a kind,
 * a name, a reference (the Urssaf account, a contract number), each gesture in the dossier's trail.
 * Its two accounts in the payroll's entry (step 4.5), each of the dossier's chart, else its kind's.
 * A body a contribution of the plan names stays, and keeps its kind. The caller checks
 * DossierRightVoter::PAYROLL_SETTINGS and flushes.
 */
final readonly class PayrollBodySheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PayrollItemRepository $items,
        private AuditTrail $auditTrail,
        private AccountRepository $accounts,
    ) {
    }

    public function check(PayrollBodyDraft $draft, ?PayrollBody $body = null, ?Dossier $dossier = null): ?PayrollBodyKind
    {
        $draft->errors = [];
        $draft->name = trim((string) preg_replace('/\s+/u', ' ', $draft->name));
        $draft->reference = trim((string) preg_replace('/\s+/u', ' ', $draft->reference));
        $kind = PayrollBodyKind::tryFrom($draft->kind);
        if (null === $kind) {
            $draft->errors['kind'] = 'Choisissez le type d’organisme.';
        } elseif (null !== $body && $kind !== $body->getKind() && $this->items->countForBody($body) > 0) {
            $draft->errors['kind'] = \sprintf('Des cotisations du plan de paie sont versées à cet organisme : il reste « %s ».', $body->getKind()->label());
        }
        if ('' === $draft->name) {
            $draft->errors['name'] = 'Indiquez le nom de l’organisme.';
        } elseif (mb_strlen($draft->name) > 80) {
            $draft->errors['name'] = 'Un nom d’organisme tient en 80 caractères.';
        }
        if (mb_strlen($draft->reference) > 40) {
            $draft->errors['reference'] = 'Une référence tient en 40 caractères.';
        }
        $dossier ??= $body?->getDossier();
        foreach (['account', 'chargeAccount'] as $field) {
            $draft->{$field} = trim($draft->{$field});
            if ('' === $draft->{$field}) {
                continue;
            }
            if (1 !== preg_match(PayrollItemRules::ACCOUNT, $draft->{$field})) {
                $draft->errors[$field] = 'Un numéro de compte s’écrit en chiffres (431000).';
            } elseif (null !== $dossier && null === $this->accounts->findOneByNumber($dossier, $draft->{$field})) {
                $draft->errors[$field] = \sprintf('Le compte %s n’est pas au plan comptable du dossier.', $draft->{$field});
            }
        }

        return [] === $draft->errors ? $kind : null;
    }

    public function create(Dossier $dossier, PayrollBodyDraft $draft, PayrollBodyKind $kind, User|string $by): PayrollBody
    {
        $body = new PayrollBody($dossier, $kind, $draft->name, $draft->reference, self::account($draft->account), self::account($draft->chargeAccount));
        $this->entityManager->persist($body);
        $this->auditTrail->inDossier($dossier, AuditAction::PayrollBodyCreated, $by, \sprintf('Organisme %s créé', $draft->name), ['dossier' => $dossier->getId(), 'kind' => $kind->value]);

        return $body;
    }

    /** False when nothing changed. */
    public function update(PayrollBody $body, PayrollBodyDraft $draft, PayrollBodyKind $kind, User|string $by): bool
    {
        if ($kind === $body->getKind() && $draft->name === $body->getName() && $draft->reference === $body->getReference()
            && self::account($draft->account) === $body->getAccount() && self::account($draft->chargeAccount) === $body->getChargeAccount()) {
            return false;
        }
        $before = $body->getName();
        $body->describe($kind, $draft->name, $draft->reference, self::account($draft->account), self::account($draft->chargeAccount));
        $this->auditTrail->inDossier($body->getDossier(), AuditAction::PayrollBodyChanged, $by, \sprintf('Organisme %s modifié', $draft->name), ['dossier' => $body->getDossier()->getId(), 'before' => $before]);

        return true;
    }

    public function deletionRefusal(PayrollBody $body): ?string
    {
        $count = $this->items->countForBody($body);

        return 0 === $count ? null : \sprintf('%s du plan de paie %s versée%s à cet organisme : il reste au dossier.', 1 === $count ? 'Une cotisation' : $count.' cotisations', 1 === $count ? 'est' : 'sont', 1 === $count ? '' : 's');
    }

    public function delete(PayrollBody $body, User|string $by): void
    {
        if (null !== $this->deletionRefusal($body)) {
            throw new \LogicException('Contributions are paid to this body.');
        }
        $this->auditTrail->inDossier($body->getDossier(), AuditAction::PayrollBodyDeleted, $by, \sprintf('Organisme %s supprimé', $body->getName()), ['dossier' => $body->getDossier()->getId()]);
        $this->entityManager->remove($body);
    }

    private static function account(string $typed): ?string
    {
        return '' === $typed ? null : $typed;
    }
}
