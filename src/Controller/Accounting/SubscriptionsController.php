<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\EntrySubscription;
use App\Entity\Accounting\EntryTemplate;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\SubscriptionTarget;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntrySubscriptionRepository;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Subscription\SubscriptionDraft;
use App\Service\Accounting\Subscription\SubscriptionRun;
use App\Service\Accounting\Subscription\SubscriptionSchedule;
use App\Service\Accounting\Subscription\SubscriptionSheet;
use App\Service\Accounting\Template\GuidedEntry;
use App\Service\Accounting\Template\TemplateCall;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Comptabilité › Paramètres comptables › Abonnements (gabarits L then F): the dossier's
 * subscriptions - an entry template called every month - with their next occurrence, and the fiche
 * of one with the dates and documents of all its occurrences. « Générer jusqu'au » the dossier's
 * date (Ctrl Entrée, after the confirmation bar that counts the entries) makes every occurrence
 * due, into the grid's pending work or the brouillard. For whoever works in the dossier
 * (DossierRightVoter::SETTINGS: the profile's « Paramètres du dossier »); anybody else reads.
 */
#[Route('/d/{dossier}/accounting/subscriptions', requirements: ['dossier' => '\d+'])]
final class SubscriptionsController extends AbstractController
{
    #[Route('', name: 'app_accounting_subscriptions', methods: ['GET'])]
    public function index(Dossier $dossier, EntrySubscriptionRepository $subscriptions, SubscriptionSheet $sheet, SubscriptionRun $run): Response
    {
        $today = $dossier->getDossierDate();
        $rows = [];
        foreach ($subscriptions->findByDossier($dossier) as $subscription) {
            $rows[] = [
                'subscription' => $subscription,
                'fault' => $sheet->faultOf($subscription),
                'next' => SubscriptionSchedule::next($subscription->getFirstMonth(), $subscription->getDayOfMonth(), $subscription->getOccurrences(), $subscription->getGenerated()),
                'last' => SubscriptionSchedule::dateOf($subscription->getFirstMonth(), $subscription->getDayOfMonth(), $subscription->getOccurrences()),
                'due' => \count(SubscriptionRun::ranksDue($subscription, $today)),
            ];
        }
        $due = $run->due($dossier, $today);

        return $this->render('accounting/subscriptions.html.twig', [
            'dossier' => $dossier,
            'subscription_rows' => $rows,
            'due_pending' => $due[SubscriptionTarget::Pending->value],
            'due_drafts' => $due[SubscriptionTarget::Draft->value],
        ]);
    }

    #[Route('/generate', name: 'app_accounting_subscriptions_generate', methods: ['POST'])]
    public function generate(Dossier $dossier, Request $request, SubscriptionRun $run, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier) || !$this->isCsrfTokenValid('dossier-subscriptions-generate-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $today = $dossier->getDossierDate();
        $outcome = $run->generate($dossier, $today, $me, $clock->now());

        $made = [];
        if ($outcome->drafts > 0) {
            $made[] = \sprintf('%s au brouillard', 1 === $outcome->drafts ? 'une écriture' : $outcome->drafts.' écritures');
        }
        if ($outcome->pending > 0) {
            $made[] = \sprintf('%s en saisie en attente (F10 dans la grille de saisie)', 1 === $outcome->pending ? 'une écriture' : $outcome->pending.' écritures');
        }
        $this->addFlash('status', [] === $made
            ? \sprintf('Aucune écriture générée jusqu’au %s.', FrenchFormat::date($today))
            : \sprintf('Générées jusqu’au %s : %s.', FrenchFormat::date($today), implode(' et ', $made)));
        foreach ($outcome->faults as $fault) {
            $this->addFlash('subscription_fault', $fault);
        }

        return $this->redirectToRoute('app_accounting_subscriptions', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/new', name: 'app_accounting_subscription_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, SubscriptionSheet $sheet, EntryTemplateRepository $templates, AccountRepository $accounts, PeriodRepository $periods, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft = new SubscriptionDraft();
        $draft->template = strtoupper(QueryValue::trimmed($request, 'template'));
        $draft->day = '1';
        $draft->firstMonth = $dossier->getDossierDate()->modify('first day of next month')->format('Y-m-d');
        $draft->occurrences = '12';
        $draft->target = SubscriptionTarget::Draft->value;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'dossier-subscription-new-'.$dossier->getId());
            $fields = $sheet->check($draft, $dossier);
            if (null !== $fields) {
                $subscription = $sheet->create($dossier, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Abonnement « %s » créé.', $fields->label));

                return $this->redirectToRoute('app_accounting_subscription', ['dossier' => $dossier->getId(), 'id' => $subscription->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('accounting/subscription.html.twig', $this->view($dossier, $draft, null, null, $templates, $accounts, $periods), new Response(status: $status));
    }

    #[Route('/{id}', name: 'app_accounting_subscription', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, int $id, Request $request, EntrySubscriptionRepository $subscriptions, SubscriptionSheet $sheet, EntryTemplateRepository $templates, AccountRepository $accounts, PeriodRepository $periods, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $subscription = $subscriptions->findOneInDossier($dossier, $id) ?? throw new NotFoundHttpException('No such subscription.');
        $draft = SubscriptionDraft::of($subscription);
        $status = Response::HTTP_OK;
        $fault = null;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $draft, 'dossier-subscription-'.$subscription->getId());
            $fields = $sheet->check($draft, $dossier, $subscription);
            if (null !== $fields) {
                $changed = $sheet->update($subscription, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? \sprintf('Abonnement « %s » enregistré.', $fields->label) : 'Rien à enregistrer : l’abonnement est inchangé.');

                return $this->redirectToRoute('app_accounting_subscription', ['dossier' => $dossier->getId(), 'id' => $subscription->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        } else {
            $fault = $sheet->faultOf($subscription);
        }

        return $this->render('accounting/subscription.html.twig', $this->view($dossier, $draft, $subscription, $fault, $templates, $accounts, $periods), new Response(status: $status));
    }

    #[Route('/{id}/delete', name: 'app_accounting_subscription_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Dossier $dossier, int $id, Request $request, EntrySubscriptionRepository $subscriptions, SubscriptionSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $subscription = $subscriptions->findOneInDossier($dossier, $id) ?? throw new NotFoundHttpException('No such subscription.');
        if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier) || !$this->isCsrfTokenValid('dossier-subscription-delete-'.$subscription->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $label = $subscription->getLabel();
        $sheet->delete($subscription, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Abonnement « %s » supprimé ; les écritures qu’il a générées restent.', $label));

        return $this->redirectToRoute('app_accounting_subscriptions', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    /** @return array<string, mixed> */
    private function view(Dossier $dossier, SubscriptionDraft $draft, ?EntrySubscription $subscription, ?string $fault, EntryTemplateRepository $templates, AccountRepository $accounts, PeriodRepository $periods): array
    {
        $templateOptions = [];
        $calls = [];
        foreach ($templates->findByDossier($dossier) as $template) {
            $templateOptions[] = ['value' => $template->getCode(), 'label' => $template->getCode().' · '.$template->getLabel().' ('.$template->getJournal()->getCode().')'];
            $calls[$template->getCode()] = self::call($template, $accounts, $dossier);
        }
        $months = [];
        foreach ($periods->findByDossier($dossier) as $period) {
            $months[] = ['value' => $period->getStartsOn()->format('Y-m-d'), 'label' => ucfirst(FrenchFormat::period($period->getStartsOn()))];
        }
        $chosen = $calls[$draft->template] ?? null;

        return [
            'dossier' => $dossier,
            'draft' => $draft,
            'subscription' => $subscription,
            'fault' => $fault,
            'template_options' => $templateOptions,
            'calls' => $calls,
            'chosen' => $chosen,
            'month_options' => $months,
            'target_options' => array_map(static fn (SubscriptionTarget $target): array => ['value' => $target->value, 'label' => $target->label(), 'checked' => $draft->target === $target->value], SubscriptionTarget::cases()),
            'occurrences' => null === $subscription ? [] : SubscriptionSchedule::occurrences($subscription->getFirstMonth(), $subscription->getDayOfMonth(), $subscription->getOccurrences(), $subscription->getDocumentPattern()),
        ];
    }

    /**
     * What a call of the template asks, for the form to show the fields it needs
     * (subscription_form_controller.js): the amount and its label, the rate it proposes, the third
     * party, and whether every line is computed (the brouillard needs it).
     *
     * @return array{amount: bool, base: string, rate: string, party: bool, computed: bool}
     */
    private static function call(EntryTemplate $template, AccountRepository $accounts, Dossier $dossier): array
    {
        return [
            'amount' => $template->asksAmount(),
            'base' => $template->getBase()?->label() ?? 'Montant',
            'rate' => str_replace('.', ',', (string) $template->getVatRate()),
            'party' => TemplateCall::asksThirdParty($template, static fn (string $number) => $accounts->findOneByNumber($dossier, $number)),
            'computed' => GuidedEntry::offers($template),
        ];
    }

    private function read(Request $request, SubscriptionDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $amount = PostValue::trimmed($request, 'amount');
        $draft->label = mb_substr(PostValue::trimmed($request, 'label'), 0, 200);
        $draft->template = mb_substr(PostValue::trimmed($request, 'template'), 0, 12);
        $draft->amount = 1 === preg_match('/^\d{1,13}$/', $amount) ? (int) $amount : null;
        $draft->rate = mb_substr(PostValue::trimmed($request, 'rate'), 0, 8);
        $draft->thirdParty = mb_substr(PostValue::trimmed($request, 'thirdParty'), 0, 17);
        $draft->day = mb_substr(PostValue::trimmed($request, 'day'), 0, 4);
        $draft->firstMonth = mb_substr(PostValue::trimmed($request, 'firstMonth'), 0, 10);
        $draft->occurrences = mb_substr(PostValue::trimmed($request, 'occurrences'), 0, 4);
        $draft->pattern = mb_substr(PostValue::trimmed($request, 'pattern'), 0, 60);
        $draft->target = PostValue::trimmed($request, 'target');
    }
}
