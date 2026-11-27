<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Audit\AuditAction;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to a dossier's third parties by hand (Comptabilité › Paramètres comptables › Tiers):
 * checks a third party as typed (ThirdPartyRules), creates, changes and deletes - each gesture in
 * the audit trail. Runs inside the dossier's context; the caller checks DossierVoter::EDIT and
 * flushes.
 */
final readonly class ThirdPartySheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ThirdPartyRepository $thirdParties,
        private AccountRepository $accounts,
        private AuditTrail $auditTrail,
    ) {
    }

    /** What may be saved, or null with the draft's errors filled. */
    public function check(ThirdPartyDraft $draft, Dossier $dossier, ?ThirdParty $thirdParty = null): ?ThirdPartyFields
    {
        return ThirdPartyRules::check(
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
        $this->auditTrail->record(
            AuditAction::ThirdPartyCreated,
            $by,
            'dossier',
            (string) $dossier->getId(),
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
        $this->auditTrail->record(
            AuditAction::ThirdPartyChanged,
            $by,
            'dossier',
            (string) $thirdParty->getDossier()->getId(),
            $before['code'] === $draft->code ? \sprintf('Tiers %s modifié', $draft->code) : \sprintf('Tiers %s renommé %s', $before['code'], $draft->code),
            ['dossier' => $thirdParty->getDossier()->getId(), 'fields' => $changed, 'before' => array_intersect_key($before, array_flip($changed))],
        );

        return true;
    }

    public function delete(ThirdParty $thirdParty, User $by): void
    {
        $this->auditTrail->record(
            AuditAction::ThirdPartyDeleted,
            $by,
            'dossier',
            (string) $thirdParty->getDossier()->getId(),
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
