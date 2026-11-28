<?php

declare(strict_types=1);

namespace App\Service\Accounting\Journal;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountNature;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Repository\Accounting\JournalRepository;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to a dossier's journals (Comptabilité › Paramètres comptables › Journaux): checks a
 * journal as typed, names every fault under its field, creates, changes and deletes - each gesture
 * in the audit trail. A bank journal's counterpart is a bank account (51…), a cash journal's a cash
 * account (53…), and no other journal has one; a dossier has one à-nouveaux journal at most.
 * A journal that carries entries or entry templates stays, and one with entries keeps its type; once one of them is validated, its code
 * no longer changes either (the entries' numbers name it: « ACH-2026-00012 »).
 * Runs inside the dossier's context; the caller checks DossierVoter::EDIT and flushes.
 */
final readonly class JournalSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private JournalRepository $journals,
        private AccountRepository $accounts,
        private AuditTrail $auditTrail,
        private EntryRepository $entries,
        private EntryTemplateRepository $templates,
    ) {
    }

    /** The journal's type and counterpart when the draft may be saved, else null with its errors filled. */
    public function check(JournalDraft $draft, Dossier $dossier, ?Journal $journal = null): ?JournalFields
    {
        $draft->errors = [];
        $draft->code = strtoupper((string) preg_replace('/\s+/u', '', $draft->code));
        $draft->label = trim((string) preg_replace('/\s+/u', ' ', $draft->label));
        $draft->counterpart = AccountNumber::normalize($draft->counterpart);

        if ('' === $draft->code) {
            $draft->errors['code'] = 'Indiquez le code du journal.';
        } elseif (1 !== preg_match('/^[A-Z0-9]{1,6}$/', $draft->code)) {
            $draft->errors['code'] = 'Un code de journal compte de 1 à 6 lettres ou chiffres, sans accent (ACH, BQ1).';
        } else {
            $same = $this->journals->findOneByCode($dossier, $draft->code);
            if (null !== $same && $same !== $journal) {
                $draft->errors['code'] = \sprintf('Le journal %s existe déjà : %s.', $draft->code, $same->getLabel());
            } elseif (null !== $journal && $draft->code !== $journal->getCode() && $this->entries->countInJournal($journal, true) > 0) {
                $draft->errors['code'] = 'Ce journal porte des écritures validées, numérotées à son code : son code ne change plus.';
            }
        }

        if ('' === $draft->label) {
            $draft->errors['label'] = 'Indiquez l’intitulé du journal.';
        } elseif (mb_strlen($draft->label) > 80) {
            $draft->errors['label'] = 'Un intitulé de journal tient en 80 caractères.';
        }

        $type = JournalType::tryFrom($draft->type);
        if (null === $type) {
            $draft->errors['type'] = 'Choisissez le type du journal.';
        } elseif (null !== $journal && $type !== $journal->getType() && $this->entries->countInJournal($journal) > 0) {
            $draft->errors['type'] = \sprintf('Ce journal porte des écritures : il reste de type « %s ».', $journal->getType()->label());
        } elseif (JournalType::Opening === $type) {
            foreach ($this->journals->findByType($dossier, JournalType::Opening) as $opening) {
                if ($opening !== $journal) {
                    $draft->errors['type'] = \sprintf('Le dossier a déjà son journal d’à-nouveaux : %s.', $opening->getCode());
                }
            }
        }

        $counterpart = null;
        $nature = $type?->counterpartNature();
        if (null !== $type && null === $nature && '' !== $draft->counterpart) {
            $draft->errors['counterpart'] = 'Seuls les journaux de banque et de caisse ont un compte de contrepartie.';
        } elseif (null !== $nature) {
            $counterpart = '' === $draft->counterpart ? null : $this->accounts->findOneByNumber($dossier, $draft->counterpart);
            if ('' === $draft->counterpart) {
                $draft->errors['counterpart'] = \sprintf('Un journal de %s a son compte de contrepartie (%s).', mb_strtolower($type->label()), self::example($nature));
            } elseif (null === $counterpart) {
                $draft->errors['counterpart'] = \sprintf('Le compte %s n’est pas dans le plan du dossier.', $draft->counterpart);
            } elseif ($counterpart->getNature() !== $nature) {
                $draft->errors['counterpart'] = \sprintf('La contrepartie d’un journal de %s est un compte de %s (%s).', mb_strtolower($type->label()), mb_strtolower($nature->label()), self::example($nature));
            }
        }

        if ([] !== $draft->errors || null === $type) {
            return null;
        }

        return new JournalFields($type, $counterpart);
    }

    public function create(Dossier $dossier, JournalDraft $draft, JournalFields $fields, User $by): Journal
    {
        $journal = new Journal($dossier, $draft->code, $draft->label, $fields->type, $fields->counterpart);
        $this->entityManager->persist($journal);
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::JournalCreated,
            $by,
            \sprintf('Journal %s %s créé dans %s', $draft->code, $draft->label, $dossier->getName()),
            ['dossier' => $dossier->getId(), 'code' => $draft->code, 'type' => $fields->type->value],
        );

        return $journal;
    }

    /** False when nothing changed (nothing is written). */
    public function update(Journal $journal, JournalDraft $draft, JournalFields $fields, User $by): bool
    {
        $before = ['code' => $journal->getCode(), 'label' => $journal->getLabel(), 'type' => $journal->getType()->value, 'counterpart' => $journal->getCounterpartAccount()?->getNumber()];
        $after = ['code' => $draft->code, 'label' => $draft->label, 'type' => $fields->type->value, 'counterpart' => $fields->counterpart?->getNumber()];
        $changed = array_keys(array_diff_assoc(array_map(strval(...), $after), array_map(strval(...), $before)));
        if ([] === $changed) {
            return false;
        }
        $journal->describe($draft->code, $draft->label, $fields->type, $fields->counterpart);
        $this->auditTrail->inDossier(
            $journal->getDossier(),
            AuditAction::JournalChanged,
            $by,
            \sprintf('Journal %s modifié', $draft->code),
            ['dossier' => $journal->getDossier()->getId(), 'fields' => $changed, 'before' => $before],
        );

        return true;
    }

    /** Why the journal may not be deleted, or null when it may. */
    public function deletionRefusal(Journal $journal): ?string
    {
        $count = $this->entries->countInJournal($journal);
        if (0 === $count) {
            $templates = $this->templates->countInJournal($journal);

            return 0 === $templates ? null : \sprintf('Ce journal porte %s : supprimez-%s ou placez-%s dans un autre journal d’abord.', 1 === $templates ? 'un modèle d’écriture' : $templates.' modèles d’écriture', 1 === $templates ? 'le' : 'les', 1 === $templates ? 'le' : 'les');
        }

        return $this->entries->countInJournal($journal, true) > 0
            ? 'Ce journal porte des écritures validées : il reste au dossier.'
            : \sprintf('Ce journal porte %s au brouillard : supprimez-%s d’abord.', 1 === $count ? 'une écriture' : $count.' écritures', 1 === $count ? 'la' : 'les');
    }

    public function delete(Journal $journal, User $by): void
    {
        if (null !== $this->deletionRefusal($journal)) {
            throw new \LogicException('This journal carries entries.');
        }
        $this->auditTrail->inDossier(
            $journal->getDossier(),
            AuditAction::JournalDeleted,
            $by,
            \sprintf('Journal %s %s supprimé', $journal->getCode(), $journal->getLabel()),
            ['dossier' => $journal->getDossier()->getId(), 'code' => $journal->getCode()],
        );
        $this->entityManager->remove($journal);
    }

    private static function example(AccountNature $nature): string
    {
        return AccountNature::Cash === $nature ? '530000' : '512000';
    }
}
