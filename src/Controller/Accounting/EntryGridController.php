<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Period;
use App\Entity\Identity\User;
use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\AccessLevel;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Accounting\PendingEntryRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Security\Voter\DossierRightVoter;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Chart\AccountDraft;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Accounting\Chart\AccountSheet;
use App\Service\Accounting\Entry\GridContext;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\GridOutcome;
use App\Service\Accounting\Entry\JournalGrid;
use App\Service\Accounting\Entry\PendingEntries;
use App\Service\Accounting\Entry\PendingStale;
use App\Service\Accounting\Template\GuidedEntry;
use App\Service\Accounting\Template\TemplateCall;
use App\Service\Dossier\Rights\DossierRights;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\JsonRequestPayload;
use App\Service\Shared\Money;
use App\Service\Shared\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Comptabilité › Saisie (design-ux.md § 3.2, « Saisie par journal », gabarit G; §§ 4.4 and 5.2): the
 * keyboard grid of a journal and a month. The page gives the browser the month's entries, the
 * person's pending work and the dossier's plan and third parties, so that typing never waits for
 * the server; the browser then keeps the pending work at every line left (`keep`), F10 saves it to
 * the brouillard (`save`), Ctrl Entrée validates the month's brouillard (`validate`), Alt Inser
 * creates the account typed (`account`), F6 calls one of the journal's entry templates (`template`:
 * its lines, amounts computed from the amount typed, laid in the current entry by the browser). The journal and the month last entered are taken again
 * (the session, per dossier). Whoever only reads the dossier sees the grid without fields; in a
 * group's dossier the member's profile decides (DossierRightVoter): a journal it closes is not
 * offered, one it reads is drawn without fields, Ctrl Entrée needs « validation », Alt Inser the
 * dossier's settings.
 */
#[Route('/d/{dossier}/accounting/entry', requirements: ['dossier' => '\d+'])]
final class EntryGridController extends AbstractController
{
    private const string SESSION_KEY = 'entry_grid';

    #[Route('', name: 'app_accounting_entry', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, JournalRepository $journals, PeriodRepository $periods, EntryRepository $entries, PendingEntryRepository $pendingRows, PendingEntries $pending, JournalGrid $grid, EntryTemplateRepository $templates, AccountRepository $accounts, DossierRights $rights, #[CurrentUser] User $me): Response
    {
        $remembered = $this->remembered($request, $dossier);
        $readable = array_values(array_filter($journals->findByDossier($dossier), fn (Journal $one): bool => $this->isGranted(DossierRightVoter::JOURNAL_READ, $one)));
        $journal = $this->journalOf($readable, QueryValue::trimmed($request, 'journal', $remembered['journal'] ?? ''));
        $period = $this->periodOf($dossier, $periods, QueryValue::trimmed($request, 'period', $remembered['period'] ?? ''));
        if (null === $journal || null === $period) {
            return $this->render('accounting/entry_grid_empty.html.twig', ['dossier' => $dossier, 'no_journal' => null === $journal]);
        }
        $this->remember($request, $dossier, $journal, $period);

        $context = $grid->context($dossier, $journal, $period);
        $editable = $this->isGranted(DossierRightVoter::JOURNAL_ENTER, $journal);
        $profileNote = $this->isGranted(DossierVoter::EDIT, $dossier) ? $rights->journalRefusal($journal, $me, $editable ? AccessLevel::Validate : AccessLevel::Enter) : null;
        $closed = self::closedReason($period);
        $others = [];
        foreach ($pendingRows->findAllPending($journal, $period) as $row) {
            $count = \count($row->getGroups());
            if ($row->getAuthor() !== $me && $count > 0) {
                $others[] = ['name' => $row->getAuthor()->getDisplayName(), 'count' => $count];
            }
        }

        return $this->render('accounting/entry_grid.html.twig', [
            'dossier' => $dossier,
            'journal' => $journal,
            'period' => $period,
            'journal_list' => $readable,
            'period_list' => $periods->findByDossier($dossier),
            'editable' => $editable && null === $closed,
            'closed_reason' => $closed,
            'profile_note' => null === $closed ? $profileNote : null,
            'can_validate' => $this->isGranted(DossierRightVoter::JOURNAL_VALIDATE, $journal),
            'others' => $others,
            'drafts_count' => \count($entries->findDrafts()),
            'level' => $context->level,
            'guided_open' => $editable && GuidedEntry::isOpen($context->level, $this->isGranted('ROLE_TEACHER')),
            'grid' => [
                'groups' => $grid->groups($context, $editable ? $me : null),
                'revision' => $editable ? $pending->revision($journal, $period, $me) : 0,
                'counterpart' => null === $journal->getCounterpartAccount() ? null : ['number' => $journal->getCounterpartAccount()->getNumber(), 'label' => $journal->getCounterpartAccount()->getLabel(), 'balance' => $grid->counterpartBalance($context)],
                'journal' => ['code' => $journal->getCode(), 'type' => $journal->getType()->value, 'invoicing' => \in_array($journal->getType(), [JournalType::Purchases, JournalType::Sales], true)],
                'period' => ['start' => $period->getStartsOn()->format('Y-m-d'), 'end' => $period->getEndsOn()->format('Y-m-d'), 'label' => $period->getLabel()],
                'exercise' => ['start' => $period->getExercise()->getStartsOn()->format('Y-m-d'), 'end' => $period->getExercise()->getEndsOn()->format('Y-m-d')],
                'dossierDate' => $dossier->getDossierDate()->format('Y-m-d'),
                'focus' => QueryValue::nullableInt($request, 'entry'),
                'templates' => $this->templatesOf($dossier, $journal, $templates, $accounts),
                'canCreateAccount' => $this->isGranted(DossierRightVoter::SETTINGS, $dossier),
            ],
            'lookup' => $grid->lookup($context),
        ]);
    }

    #[Route('/keep', name: 'app_accounting_entry_keep', methods: ['POST'])]
    public function keep(Dossier $dossier, Request $request, JournalRepository $journals, PeriodRepository $periods, JournalGrid $grid, ClockInterface $clock, #[CurrentUser] User $me): JsonResponse
    {
        [$context, $groups, $revision] = $this->posted($dossier, $request, $journals, $periods, $grid);
        try {
            return new JsonResponse($grid->keep($context, $me, $groups, $revision, $clock->now()));
        } catch (PendingStale $stale) {
            return new JsonResponse(['message' => $stale->getMessage(), 'revision' => $stale->current], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/save', name: 'app_accounting_entry_save', methods: ['POST'])]
    public function save(Dossier $dossier, Request $request, JournalRepository $journals, PeriodRepository $periods, JournalGrid $grid, PendingEntries $pending, EntryRepository $entries, ClockInterface $clock, #[CurrentUser] User $me): JsonResponse
    {
        [$context, $groups, $revision] = $this->posted($dossier, $request, $journals, $periods, $grid);
        try {
            $outcome = $grid->save($context, $me, $groups, $revision, $clock->now());
        } catch (PendingStale $stale) {
            return new JsonResponse(['message' => $stale->getMessage(), 'revision' => $stale->current], Response::HTTP_CONFLICT);
        }

        return $this->answer($context, $outcome, $grid, $pending, $entries, $me);
    }

    #[Route('/validate', name: 'app_accounting_entry_validate', methods: ['POST'])]
    public function validate(Dossier $dossier, Request $request, JournalRepository $journals, PeriodRepository $periods, JournalGrid $grid, PendingEntries $pending, EntryRepository $entries, ClockInterface $clock, #[CurrentUser] User $me): JsonResponse
    {
        [$context, $groups, $revision] = $this->posted($dossier, $request, $journals, $periods, $grid);
        try {
            $outcome = $grid->validate($context, $me, $groups, $revision, $clock->now());
        } catch (PendingStale $stale) {
            return new JsonResponse(['message' => $stale->getMessage(), 'revision' => $stale->current], Response::HTTP_CONFLICT);
        }

        return $this->answer($context, $outcome, $grid, $pending, $entries, $me);
    }

    /** Alt Inser: the account typed in the grid created with its label (design-ux.md § 4.4). */
    #[Route('/account', name: 'app_accounting_entry_account', methods: ['POST'])]
    public function account(Dossier $dossier, Request $request, AccountSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): JsonResponse
    {
        $this->guard($dossier, $request);
        if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier)) {
            throw new NotFoundHttpException('The profile keeps the plan closed.');
        }
        $payload = JsonRequestPayload::fromRequest($request);
        $draft = new AccountDraft();
        $draft->number = AccountNumber::normalize($payload->string('number'));
        $draft->label = trim($payload->string('label'));
        $draft->lettrable = AccountNumber::isLettrableByDefault($draft->number);
        $draft->collective = false;
        if (!$sheet->check($draft, $dossier)) {
            return new JsonResponse(['errors' => $draft->errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $account = $sheet->create($dossier, $draft, $me);
        $entityManager->flush();

        return new JsonResponse([
            'account' => ['n' => $account->getNumber(), 'l' => $account->getLabel(), 'c' => $account->isCollective(), 't' => $account->isLettrable(), 'b' => 0],
            'message' => \sprintf('Compte %s créé.', $account->getNumber()).(null !== $draft->warning ? ' '.$draft->warning : ''),
        ]);
    }

    /**
     * F6: an entry template of the journal called at the entry's date with the amount and the rate
     * typed - its lines as the grid types them; the browser lays them in the current entry, and
     * nothing is written before the line is left.
     */
    #[Route('/template', name: 'app_accounting_entry_template', methods: ['POST'])]
    public function template(Dossier $dossier, Request $request, EntryTemplateRepository $templates, AccountRepository $accounts): JsonResponse
    {
        $this->guard($dossier, $request);
        $payload = JsonRequestPayload::fromRequest($request);
        $template = $templates->findOneByCode($dossier, strtoupper($payload->string('template'))) ?? throw new NotFoundHttpException('No such template.');
        if (!$this->isGranted(DossierRightVoter::JOURNAL_ENTER, $template->getJournal())) {
            throw new NotFoundHttpException('The profile does not enter in this journal.');
        }
        $date = DateValue::parse($payload->string('date')) ?? $dossier->getDossierDate();
        $cents = $payload->int('amount');
        $call = TemplateCall::lines($template, $date, null === $cents ? null : Money::cents($cents), $payload->string('rate'), '', static fn (string $number) => $accounts->findOneByNumber($dossier, $number));
        if ([] !== $call['errors']) {
            return new JsonResponse(['errors' => $call['errors']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'lines' => array_map(static fn ($line): array => $line->toArray(), $call['lines']),
            'message' => \sprintf('Modèle %s posé : %s.', $template->getCode(), $template->getLabel()),
        ]);
    }

    /**
     * The journal's entry templates as F6 lists them: code, label, the amount a call asks and the
     * rate it proposes.
     *
     * @return list<array{code: string, label: string, asks: bool, base: string, rate: string, party: bool}>
     */
    private function templatesOf(Dossier $dossier, Journal $journal, EntryTemplateRepository $templates, AccountRepository $accounts): array
    {
        $list = [];
        foreach ($templates->findByDossier($dossier, $journal) as $template) {
            $list[] = [
                'code' => $template->getCode(),
                'label' => $template->getLabel(),
                'asks' => $template->asksAmount(),
                'base' => $template->getBase()?->label() ?? '',
                'rate' => str_replace('.', ',', (string) $template->getVatRate()),
                'party' => TemplateCall::asksThirdParty($template, static fn (string $number) => $accounts->findOneByNumber($dossier, $number)),
            ];
        }

        return $list;
    }

    /** The answer to F10 and Ctrl Entrée: the grid drawn again, and what was done. */
    private function answer(GridContext $context, GridOutcome $outcome, JournalGrid $grid, PendingEntries $pending, EntryRepository $entries, User $me): JsonResponse
    {
        return new JsonResponse([
            'message' => $outcome->message(),
            'groups' => $grid->groups($context, $me),
            'revision' => $pending->revision($context->journal, $context->period, $me),
            'counterpartBalance' => $grid->counterpartBalance($context),
            'draftsCount' => \count($entries->findDrafts()),
            'saved' => $outcome->saved + $outcome->discarded,
            'validated' => null === $outcome->validated ? 0 : \count($outcome->validated),
        ]);
    }

    /**
     * The journal, the month and the groups a POST of the grid names, for whoever works in the
     * dossier, in a month that takes entries.
     *
     * @return array{GridContext, list<GridGroup>, ?int}
     */
    private function posted(Dossier $dossier, Request $request, JournalRepository $journals, PeriodRepository $periods, JournalGrid $grid): array
    {
        $this->guard($dossier, $request);
        $payload = JsonRequestPayload::fromRequest($request);
        $journal = $journals->findOneByCode($dossier, strtoupper($payload->string('journal'))) ?? throw new NotFoundHttpException('No such journal.');
        if (!$this->isGranted(DossierRightVoter::JOURNAL_ENTER, $journal)) {
            throw new NotFoundHttpException('The profile does not enter in this journal.');
        }
        $period = $this->periodOf($dossier, $periods, $payload->string('period'), false) ?? throw new NotFoundHttpException('No such period.');
        if (null !== self::closedReason($period)) {
            throw new NotFoundHttpException('A closed period takes no entry.');
        }
        $groups = [];
        foreach (\array_slice($payload->objects('groups'), 0, PendingEntries::MAX_GROUPS) as $group) {
            $groups[] = GridGroup::fromPayload($group);
        }

        return [$grid->context($dossier, $journal, $period), $groups, $payload->int('revision')];
    }

    private function guard(Dossier $dossier, Request $request): void
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('entry-grid-'.$dossier->getId(), (string) $request->headers->get('X-Peyra-Token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
    }

    private static function closedReason(Period $period): ?string
    {
        if ($period->getExercise()->isClosed()) {
            return \sprintf('L’exercice %s est clos : ses écritures se lisent, elles ne se saisissent plus.', $period->getExercise()->getCode());
        }
        if ($period->isClosed()) {
            return \sprintf('%s est clos : ses écritures se lisent, elles ne se saisissent plus.', ucfirst(FrenchFormat::period($period->getStartsOn())));
        }

        return null;
    }

    /**
     * The journal asked among those the person may open, else the first purchase journal, else the
     * first that is not the à-nouveaux.
     *
     * @param list<Journal> $all
     */
    private function journalOf(array $all, string $code): ?Journal
    {
        foreach ($all as $journal) {
            if ($journal->getCode() === strtoupper($code)) {
                return $journal;
            }
        }
        foreach ([JournalType::Purchases, JournalType::Sales, JournalType::Bank, JournalType::General] as $type) {
            foreach ($all as $journal) {
                if ($journal->getType() === $type) {
                    return $journal;
                }
            }
        }

        return $all[0] ?? null;
    }

    /** The month that starts on the day asked (« 2026-01-01 »), else the one of the dossier's date, else the last. */
    private function periodOf(Dossier $dossier, PeriodRepository $periods, string $start, bool $fallback = true): ?Period
    {
        $all = $periods->findByDossier($dossier);
        foreach ($all as $period) {
            if ($period->getStartsOn()->format('Y-m-d') === $start) {
                return $period;
            }
        }
        if (!$fallback) {
            return null;
        }

        return $periods->findAt($dossier, $dossier->getDossierDate()) ?? ([] === $all ? null : $all[\count($all) - 1]);
    }

    /** @return array{journal?: string, period?: string} */
    private function remembered(Request $request, Dossier $dossier): array
    {
        $all = $request->getSession()->get(self::SESSION_KEY);
        $mine = \is_array($all) ? ($all[(int) $dossier->getId()] ?? null) : null;
        if (!\is_array($mine)) {
            return [];
        }
        $remembered = [];
        if (\is_string($mine['journal'] ?? null)) {
            $remembered['journal'] = $mine['journal'];
        }
        if (\is_string($mine['period'] ?? null)) {
            $remembered['period'] = $mine['period'];
        }

        return $remembered;
    }

    private function remember(Request $request, Dossier $dossier, Journal $journal, Period $period): void
    {
        $session = $request->getSession();
        $all = $session->get(self::SESSION_KEY);
        $all = \is_array($all) ? $all : [];
        $all[(int) $dossier->getId()] = ['journal' => $journal->getCode(), 'period' => $period->getStartsOn()->format('Y-m-d')];
        $session->set(self::SESSION_KEY, $all);
    }
}
