<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Accounting\Chart\ChartInstaller;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Accounting\Journal\JournalDraft;
use App\Service\Accounting\Journal\JournalSheet;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\CompanySheet;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\Exercises;
use App\Service\Dossier\PeriodPlan;
use App\Service\Shared\DateValue;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Nouveau dossier modèle › Dossier vide » (design-ux.md § 3.3): a template of the teacher's
 * own, ready to be worked in - the company's fiche, its first exercise and its months, the plan
 * of the reference chart chosen (DECISIONS 1.2a: the choice of the plan comes with the new
 * template), and the five journals every dossier starts with (Achats, Ventes, Banque on 512000,
 * Opérations diverses, À-nouveaux), which the teacher renames or completes in Paramètres
 * comptables. One transaction; every gesture in the template's trail.
 */
final readonly class TemplateFactory
{
    /** The journals of a new dossier: code, label, type, counterpart. */
    public const array JOURNALS = [
        ['ACH', 'Achats', JournalType::Purchases, ''],
        ['VTE', 'Ventes', JournalType::Sales, ''],
        ['BQ', 'Banque', JournalType::Bank, '512000'],
        ['OD', 'Opérations diverses', JournalType::General, ''],
        ['AN', 'À-nouveaux', JournalType::Opening, ''],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanySheet $companies,
        private TemplateCards $cards,
        private ReferenceCharts $charts,
        private ChartInstaller $chartInstaller,
        private Exercises $exercises,
        private JournalSheet $journals,
        private DossierContext $context,
        private SchoolYearRepository $years,
        private AuditTrail $auditTrail,
    ) {
    }

    /** The template, or null with the draft's faults written. */
    public function create(EmptyTemplateDraft $draft, User $by, \DateTimeImmutable $at): ?Dossier
    {
        $start = $this->prepare($draft);
        if (null === $start) {
            return null;
        }

        return $this->entityManager->wrapInTransaction(function () use ($draft, $start, $by, $at): Dossier {
            $template = $this->open($start, $draft->title, 'dossier vide', $by, $at);
            $this->context->within($template, function () use ($template, $by): void {
                foreach (self::JOURNALS as [$code, $label, $type, $counterpart]) {
                    $journal = new JournalDraft();
                    $journal->code = $code;
                    $journal->label = $label;
                    $journal->type = $type->value;
                    $journal->counterpart = $counterpart;
                    $fields = $this->journals->check($journal, $template) ?? throw new \LogicException(\sprintf('The journal %s of a new template: %s', $code, implode(' ', $journal->errors)));
                    $this->journals->create($template, $journal, $fields, $by);
                }
            });
            $this->entityManager->flush();

            return $template;
        });
    }

    /**
     * The draft's company, exercise, date and reference chart checked (the FEC's import starts
     * from them too), or null with the draft's faults written.
     */
    public function prepare(EmptyTemplateDraft $draft): ?TemplateStart
    {
        $company = $this->companies->check($draft->company);
        $draft->errors = [];
        $card = new TemplateCardDraft();
        $card->title = $draft->title;
        if (!$this->cards->check($card)) {
            $draft->errors['title'] = $card->error('title') ?? '';
        }
        $start = DateValue::parse($draft->exerciseStart);
        $end = DateValue::parse($draft->exerciseEnd);
        $date = DateValue::parse($draft->dossierDate);
        if (null === $start) {
            $draft->errors['exerciseStart'] = 'Indiquez le premier jour de l’exercice, au format JJ/MM/AAAA.';
        }
        if (null === $end) {
            $draft->errors['exerciseEnd'] = 'Indiquez le dernier jour de l’exercice, au format JJ/MM/AAAA.';
        }
        if (null !== $start && null !== $end) {
            try {
                PeriodPlan::monthly($start, $end);
            } catch (\InvalidArgumentException) {
                $draft->errors['exerciseEnd'] = $end <= $start ? 'L’exercice finit après son premier jour.' : 'Un exercice dure 24 mois au plus.';
            }
        }
        if (null === $date) {
            $draft->errors['dossierDate'] = 'Indiquez la date du dossier, au format JJ/MM/AAAA.';
        } elseif (null !== $start && null !== $end && ($date < $start || $date > $end)) {
            $draft->errors['dossierDate'] = 'La date du dossier tombe dans son exercice.';
        }
        $chart = $this->charts->get($draft->chart);
        if (null === $chart) {
            $draft->errors['chart'] = 'Choisissez le plan de comptes dans la liste.';
        }
        if (null === $company || [] !== $draft->errors || null === $start || null === $end || null === $date || null === $chart) {
            return null;
        }

        return new TemplateStart($company, $start, $end, $date, $chart);
    }

    /**
     * A new template of the teacher's own: its card's title, its first exercise and months, the
     * plan of its reference chart - no journal yet. Flushed; the caller holds the transaction.
     * `$how` ends the trail's line (« dossier vide », « depuis le FEC … »).
     */
    public function open(TemplateStart $start, string $title, string $how, User $by, \DateTimeImmutable $at): Dossier
    {
        $template = new Dossier(DossierKind::Template, $start->company, $start->dossierDate, $by, $this->years->findCurrent(), $at, $by);
        $template->describeInLibrary('' === $title ? null : $title, null, null, []);
        $this->entityManager->persist($template);
        $this->entityManager->flush();
        $this->auditTrail->inDossier($template, AuditAction::DossierCreated, $by, \sprintf('Dossier modèle « %s » créé (%s)', $template->getLibraryTitle(), $how), ['dossier' => $template->getId(), 'kind' => DossierKind::Template->value]);
        $this->exercises->open($template, self::exerciseCode($start->exerciseStart, $start->exerciseEnd), $start->exerciseStart, $start->exerciseEnd);
        $this->chartInstaller->install($template, $start->chart, $by);
        $this->entityManager->flush();

        return $template;
    }

    /** « 2026 » for a calendar year, « 2026-2027 » for an exercise across two. */
    public static function exerciseCode(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        return $start->format('Y') === $end->format('Y') ? $start->format('Y') : $start->format('Y').'-'.$end->format('Y');
    }
}
