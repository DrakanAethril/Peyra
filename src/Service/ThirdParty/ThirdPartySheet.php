<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Audit\AuditAction;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryLineRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to a dossier's third parties by hand (Comptabilité › Paramètres comptables › Tiers):
 * checks a third party as typed (ThirdPartyRules), creates, changes and deletes - each gesture in
 * the audit trail. A third party named on an entry line keeps its account and its auxiliary code,
 * and stays (usageFaults(), deletionRefusal()). Runs inside the dossier's context; the caller
 * checks DossierVoter::EDIT and flushes.
 */
final readonly class ThirdPartySheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ThirdPartyRepository $thirdParties,
        private AccountRepository $accounts,
        private AuditTrail $auditTrail,
        private EntryLineRepository $lines,
    ) {
    }

    /** What may be saved, or null with the draft's errors filled. */
    public function check(ThirdPartyDraft $draft, Dossier $dossier, ?ThirdParty $thirdParty = null): ?ThirdPartyFields
    {
        $fields = ThirdPartyRules::check(
            $draft,
            fn (string $number) => $this->accounts->findOneByNumber($dossier, $number),
            function (string $code) use ($dossier, $thirdParty): ?string {
                $holder = $this->thirdParties->findOneByCode($dossier, $code);

                return null === $holder || $holder === $thirdParty ? null : $holder->getName();
            },
            function (string $code) use ($dossier, $thirdParty): ?string {
                $holder = $this->thirdParties->findOneByAuxiliaryCode($dossier, $code);

                return null === $holder || $holder === $thirdParty ? null : $holder->getName();
            },
        );
        if (null === $fields || null === $thirdParty || 0 === $this->lines->countOnThirdParty($thirdParty)) {
            return $fields;
        }
        $draft->errors = self::usageFaults($thirdParty, $fields);

        return [] === $draft->errors ? $fields : null;
    }

    /**
     * What a third party named on entry lines may not change: its account and its auxiliary code,
     * which those lines are entered on (ThirdPartyImport asks the same).
     *
     * @return array<string, string> by field
     */
    public static function usageFaults(ThirdParty $thirdParty, ThirdPartyFields $fields): array
    {
        $faults = [];
        if ($fields->account !== $thirdParty->getAccount()) {
            $faults['account'] = null === $thirdParty->getAccount()
                ? 'Ce tiers est nommé sur des écritures : il reste sans compte.'
                : \sprintf('Ce tiers est nommé sur des écritures : il reste sur le compte %s.', $thirdParty->getAccount()->getNumber());
        }
        if ($fields->auxiliaryCode !== $thirdParty->getAuxiliaryCode()) {
            $faults['auxiliaryCode'] = null === $thirdParty->getAuxiliaryCode()
                ? 'Ce tiers est nommé sur des écritures : il reste sans code auxiliaire.'
                : \sprintf('Ce tiers est nommé sur des écritures : son code auxiliaire reste %s.', $thirdParty->getAuxiliaryCode());
        }

        return $faults;
    }

    /** Why the third party may not be deleted, or null when it may. */
    public function deletionRefusal(ThirdParty $thirdParty): ?string
    {
        $count = $this->lines->countOnThirdParty($thirdParty);
        if (0 === $count) {
            return null;
        }

        return \sprintf('Ce tiers est nommé sur %s : il reste au dossier.', 1 === $count ? 'une ligne d’écriture' : $count.' lignes d’écriture');
    }

    /** A draft for a new third party of that kind: the next code, its usual account when the plan holds it. */
    public function blank(Dossier $dossier, ThirdPartyKind $kind): ThirdPartyDraft
    {
        $draft = new ThirdPartyDraft();
        $draft->kind = $kind->value;
        $draft->code = ThirdPartyCode::next($kind, array_map(static fn (ThirdParty $t): string => $t->getCode(), $this->thirdParties->findByDossier($dossier)));
        $default = $kind->defaultAccountNumber();
        if (null !== $default && null !== $this->accounts->findOneByNumber($dossier, $default)) {
            $draft->account = $default;
        }
        $draft->termKind = 'net';
        $draft->termDays = '30';
        $draft->paymentMethod = 'transfer';

        return $draft;
    }

    public function create(Dossier $dossier, ThirdPartyDraft $draft, ThirdPartyFields $fields, User $by): ThirdParty
    {
        $thirdParty = new ThirdParty($dossier, $draft->code, $fields->kind, $draft->name);
        self::write($thirdParty, $draft, $fields);
        $this->entityManager->persist($thirdParty);
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::ThirdPartyCreated,
            $by,
            \sprintf('Tiers %s %s créé dans %s', $draft->code, $draft->name, $dossier->getName()),
            ['dossier' => $dossier->getId(), 'code' => $draft->code, 'kind' => $fields->kind->value, 'auxiliary' => $fields->auxiliaryCode],
        );

        return $thirdParty;
    }

    /** False when nothing changed (nothing is written). */
    public function update(ThirdParty $thirdParty, ThirdPartyDraft $draft, ThirdPartyFields $fields, User $by): bool
    {
        $before = self::snapshot(ThirdPartyDraft::of($thirdParty));
        $changed = array_keys(array_diff_assoc(self::snapshot($draft), $before));
        if ([] === $changed) {
            return false;
        }
        self::write($thirdParty, $draft, $fields);
        $this->auditTrail->inDossier(
            $thirdParty->getDossier(),
            AuditAction::ThirdPartyChanged,
            $by,
            $before['code'] === $draft->code ? \sprintf('Tiers %s modifié', $draft->code) : \sprintf('Tiers %s renommé %s', $before['code'], $draft->code),
            ['dossier' => $thirdParty->getDossier()->getId(), 'fields' => $changed, 'before' => array_intersect_key($before, array_flip($changed))],
        );

        return true;
    }

    public function delete(ThirdParty $thirdParty, User $by): void
    {
        if (null !== $this->deletionRefusal($thirdParty)) {
            throw new \LogicException('This third party is named on entry lines.');
        }
        $this->auditTrail->inDossier(
            $thirdParty->getDossier(),
            AuditAction::ThirdPartyDeleted,
            $by,
            \sprintf('Tiers %s %s supprimé', $thirdParty->getCode(), $thirdParty->getName()),
            ['dossier' => $thirdParty->getDossier()->getId(), 'code' => $thirdParty->getCode(), 'auxiliary' => $thirdParty->getAuxiliaryCode()],
        );
        $this->entityManager->remove($thirdParty);
    }

    /** A checked draft written onto a third party: ThirdPartyImport writes through it too. */
    public static function write(ThirdParty $thirdParty, ThirdPartyDraft $draft, ThirdPartyFields $fields): void
    {
        $thirdParty->describe($draft->code, $fields->kind, $draft->name, $draft->individual);
        $thirdParty->keepOn($fields->account, $fields->auxiliaryCode);
        $thirdParty->identify(self::orNull($draft->siren), self::orNull($draft->vatNumber));
        $thirdParty->locate(
            self::orNull($draft->street),
            self::orNull($draft->street2),
            self::orNull($draft->postalCode),
            self::orNull($draft->city),
            $draft->country,
            self::orNull($draft->contactName),
            self::orNull($draft->email),
            self::orNull($draft->phone),
        );
        $thirdParty->settle($fields->terms, $fields->method, self::orNull($draft->note));
    }

    /** @return array<string, string> every field as text, for comparing two states */
    public static function snapshot(ThirdPartyDraft $draft): array
    {
        return [
            'code' => $draft->code, 'kind' => $draft->kind, 'name' => $draft->name, 'individual' => $draft->individual ? 'oui' : 'non',
            'account' => $draft->account, 'auxiliaryCode' => $draft->auxiliaryCode, 'siren' => $draft->siren, 'vatNumber' => $draft->vatNumber,
            'street' => $draft->street, 'street2' => $draft->street2, 'postalCode' => $draft->postalCode, 'city' => $draft->city,
            'country' => $draft->country, 'contactName' => $draft->contactName, 'email' => $draft->email, 'phone' => $draft->phone,
            'termKind' => $draft->termKind, 'termDays' => $draft->termDays, 'termDay' => $draft->termDay,
            'paymentMethod' => $draft->paymentMethod, 'note' => $draft->note,
        ];
    }

    private static function orNull(string $text): ?string
    {
        return '' === $text ? null : $text;
    }
}
