<?php

declare(strict_types=1);

namespace App\Service\Payroll\Plan;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollItem;
use App\Entity\Payroll\PayrollRate;
use App\Enum\Audit\AuditAction;
use App\Repository\Payroll\PayrollBodyRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Regulatory\RegulatorySnapshot;
use App\Service\Shared\FrenchFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to the plan de paie by hand (Paie et social › Paramètres de paie › Plan de paie):
 * PayrollItemRules, then what reads the dossier - a code already taken, the body among the
 * dossier's, and **a regulatory value that reads at the dossier's date** (the key in force, a number
 * at the path): a rubrique the payslip could not compute is refused when it is written, not on the
 * payslip. Creates, changes and deletes, each gesture in the dossier's trail. Runs inside the
 * dossier's context; the caller checks DossierRightVoter::PAYROLL_SETTINGS and flushes.
 */
final readonly class PayrollItemSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PayrollItemRepository $items,
        private PayrollBodyRepository $bodies,
        private RegulatoryReader $regulatory,
        private AuditTrail $auditTrail,
    ) {
    }

    /** @phpstan-impure it writes the draft's errors */
    public function check(PayrollItemDraft $draft, Dossier $dossier, ?PayrollItem $item = null): ?PayrollItemSpec
    {
        $body = null;
        $bodyMissing = false;
        if ('' !== trim($draft->body)) {
            $body = 1 === preg_match('/^\d+$/', trim($draft->body)) ? $this->bodies->findOneInDossier($dossier, (int) trim($draft->body)) : null;
            $bodyMissing = null === $body;
        }
        $spec = PayrollItemRules::check($draft, $body);
        if ($bodyMissing) {
            $draft->errors['body'] = 'Cet organisme n’est pas au dossier.';
        }
        if (!isset($draft->errors['code'])) {
            $same = $this->items->findOneByCode($dossier, $draft->code);
            if (null !== $same && $same !== $item) {
                $draft->errors['code'] = \sprintf('La rubrique %s existe déjà : %s.', $draft->code, $same->getLabel());
            }
        }
        if (null !== $spec) {
            $snapshot = $this->regulatory->at($dossier->getDossierDate());
            foreach (['employee' => $spec->employeeRate, 'employer' => $spec->employerRate] as $side => $rate) {
                $refusal = self::unreadable($rate, $snapshot);
                if (null !== $refusal) {
                    $draft->errors[$side.'Key'] = $refusal;
                }
            }
        }

        return [] === $draft->errors ? $spec : null;
    }

    public function create(Dossier $dossier, PayrollItemSpec $spec, User|string $by): PayrollItem
    {
        $item = new PayrollItem($dossier, $spec);
        $this->entityManager->persist($item);
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::PayrollItemCreated,
            $by,
            \sprintf('Rubrique %s %s créée', $spec->code, $spec->label),
            ['dossier' => $dossier->getId(), 'code' => $spec->code, 'kind' => $spec->kind->value],
        );

        return $item;
    }

    /** False when nothing changed (nothing is written). */
    public function update(PayrollItem $item, PayrollItemSpec $spec, User|string $by): bool
    {
        $before = get_object_vars(PayrollItemDraft::of($item));
        $item->describe($spec);
        $after = get_object_vars(PayrollItemDraft::of($item));
        $changed = [];
        foreach ($after as $field => $value) {
            if ('errors' !== $field && $value !== $before[$field]) {
                $changed[] = $field;
            }
        }
        if ([] === $changed) {
            return false;
        }
        $this->auditTrail->inDossier(
            $item->getDossier(),
            AuditAction::PayrollItemChanged,
            $by,
            \sprintf('Rubrique %s %s modifiée', $spec->code, $spec->label),
            ['dossier' => $item->getDossier()->getId(), 'code' => $spec->code, 'fields' => $changed, 'before_code' => $before['code']],
        );

        return true;
    }

    public function delete(PayrollItem $item, User|string $by): void
    {
        $this->auditTrail->inDossier(
            $item->getDossier(),
            AuditAction::PayrollItemDeleted,
            $by,
            \sprintf('Rubrique %s %s supprimée', $item->getCode(), $item->getLabel()),
            ['dossier' => $item->getDossier()->getId(), 'code' => $item->getCode()],
        );
        $this->entityManager->remove($item);
    }

    /** Why a rate's regulatory value does not read on that day, or null when it reads (or names none). */
    public static function unreadable(PayrollRate $rate, RegulatorySnapshot $snapshot): ?string
    {
        $key = $rate->getReferenceKey();
        if (null === $key) {
            return null;
        }
        try {
            $snapshot->decimal($key, ...$rate->pathSegments());

            return null;
        } catch (MissingRegulatoryValue) {
            return \sprintf(
                'La valeur réglementaire %s%s ne se lit pas au %s : aucune valeur en vigueur, ou pas de nombre à ce chemin (Paramètres › Paramètres réglementaires).',
                $key,
                null === $rate->getReferencePath() ? '' : ' › '.str_replace('.', ' › ', $rate->getReferencePath()),
                FrenchFormat::date($snapshot->day),
            );
        }
    }
}
