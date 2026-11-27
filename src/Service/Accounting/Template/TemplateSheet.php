<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use App\Entity\Accounting\EntryTemplate;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntrySubscriptionRepository;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to a dossier's entry templates (Comptabilité › Paramètres comptables › Modèles
 * d'écriture): checks a template as typed (TemplateRules, every fault under its field), creates,
 * changes and deletes it - each gesture in the audit trail. A template writes nothing in the books:
 * deleting one touches no entry. Runs inside the dossier's context; the caller checks
 * DossierVoter::EDIT and flushes.
 */
final readonly class TemplateSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryTemplateRepository $templates,
        private JournalRepository $journals,
        private AccountRepository $accounts,
        private ThirdPartyRepository $thirdParties,
        private AuditTrail $auditTrail,
        private EntrySubscriptionRepository $subscriptions,
    ) {
    }

    public function check(TemplateDraft $draft, Dossier $dossier, ?EntryTemplate $template = null): ?TemplateFields
    {
        return TemplateRules::check(
            $draft,
            fn (string $code) => $this->journals->findOneByCode($dossier, $code),
            fn (string $number) => $this->accounts->findOneByNumber($dossier, $number),
            fn (string $code) => $this->thirdParties->findOneByAuxiliaryCode($dossier, $code) ?? $this->thirdParties->findOneByCode($dossier, $code),
            function (string $code) use ($dossier, $template): ?string {
                $holder = $this->templates->findOneByCode($dossier, $code);

                return null === $holder || $holder === $template ? null : $holder->getLabel();
            },
        );
    }

    /**
     * Why a template saved earlier no longer reads (an account deleted or renumbered, a third party
     * gone): the first fault TemplateRules finds in it today, or null. Its list says « À revoir ».
     */
    public function faultOf(EntryTemplate $template): ?string
    {
        $draft = TemplateDraft::of($template);

        return null === $this->check($draft, $template->getDossier(), $template) ? (string) reset($draft->errors) : null;
    }

    public function create(Dossier $dossier, TemplateFields $fields, User|string $by): EntryTemplate
    {
        $template = new EntryTemplate($dossier, $fields->code, $fields->label, $fields->journal);
        $template->describe($fields->code, $fields->label, $fields->journal, $fields->base, $fields->vatRate, $fields->lines);
        $this->entityManager->persist($template);
        $this->auditTrail->record(
            AuditAction::EntryTemplateCreated,
            $by,
            'dossier',
            (string) $dossier->getId(),
            \sprintf('Modèle d’écriture %s %s créé', $fields->code, $fields->label),
            ['dossier' => $dossier->getId(), 'code' => $fields->code, 'journal' => $fields->journal->getCode(), 'lines' => \count($fields->lines)],
        );

        return $template;
    }

    /** False when nothing changed (nothing is written). */
    public function update(EntryTemplate $template, TemplateFields $fields, User $by): bool
    {
        $before = self::snapshot($template);
        $template->describe($fields->code, $fields->label, $fields->journal, $fields->base, $fields->vatRate, $fields->lines);
        if (self::snapshot($template) === $before) {
            return false;
        }
        $this->auditTrail->record(
            AuditAction::EntryTemplateChanged,
            $by,
            'dossier',
            (string) $template->getDossier()->getId(),
            \sprintf('Modèle d’écriture %s modifié', $fields->code),
            ['dossier' => $template->getDossier()->getId(), 'before' => $before],
        );

        return true;
    }

    /** Why the template may not be deleted - a subscription calls it -, or null when it may. */
    public function deletionRefusal(EntryTemplate $template): ?string
    {
        $labels = $this->subscriptions->labelsCalling($template);
        if ([] === $labels) {
            return null;
        }

        return 1 === \count($labels)
            ? \sprintf('L’abonnement « %s » appelle ce modèle : supprimez-le ou donnez-lui un autre modèle d’abord.', $labels[0])
            : \sprintf('%d abonnements appellent ce modèle (%s) : supprimez-les ou donnez-leur un autre modèle d’abord.', \count($labels), implode(', ', $labels));
    }

    public function delete(EntryTemplate $template, User $by): void
    {
        if (null !== $this->deletionRefusal($template)) {
            throw new \LogicException('A subscription calls this template.');
        }
        $this->auditTrail->record(
            AuditAction::EntryTemplateDeleted,
            $by,
            'dossier',
            (string) $template->getDossier()->getId(),
            \sprintf('Modèle d’écriture %s %s supprimé', $template->getCode(), $template->getLabel()),
            ['dossier' => $template->getDossier()->getId(), 'code' => $template->getCode()],
        );
        $this->entityManager->remove($template);
    }

    /** @return array{code: string, label: string, journal: string, base: ?string, rate: ?string, lines: list<string>} */
    private static function snapshot(EntryTemplate $template): array
    {
        $lines = [];
        foreach ($template->getLines() as $line) {
            $lines[] = implode('|', [$line->getAccount(), $line->getThirdParty(), $line->getLabel(), $line->getSide()->value, $line->getAmount()->value, (string) $line->getFixed()?->cents]);
        }

        return ['code' => $template->getCode(), 'label' => $template->getLabel(), 'journal' => $template->getJournal()->getCode(), 'base' => $template->getBase()?->value, 'rate' => $template->getVatRate(), 'lines' => $lines];
    }
}
