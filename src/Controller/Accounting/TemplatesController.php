<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\EntryTemplate;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\EntrySide;
use App\Enum\Accounting\JournalType;
use App\Enum\Accounting\TemplateAmount;
use App\Enum\Accounting\TemplateBase;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Template\TemplateDraft;
use App\Service\Accounting\Template\TemplateLineDraft;
use App\Service\Accounting\Template\TemplateSheet;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Comptabilité › Paramètres comptables › Modèles d'écriture (design-ux.md § 3.2, gabarits L then F):
 * the dossier's entry templates, and the fiche of one - code, label, journal, the amount a call asks
 * and its VAT rate, then its lines (account or auxiliary code, third party, label, side, amount:
 * HT, TVA, TTC, fixed, balance, to type). F10 saves for whoever works in the dossier
 * (DossierVoter::EDIT), anybody else reads. The address of a template is its code.
 */
#[Route('/d/{dossier}/accounting/templates', requirements: ['dossier' => '\d+'])]
final class TemplatesController extends AbstractController
{
    /** Spare empty lines a fiche offers below the template's own. */
    private const int SPARE_LINES = 1;

    #[Route('', name: 'app_accounting_templates', methods: ['GET'])]
    public function index(Dossier $dossier, EntryTemplateRepository $templates, TemplateSheet $sheet): Response
    {
        $rows = [];
        foreach ($templates->findByDossier($dossier) as $template) {
            $rows[] = ['template' => $template, 'fault' => $sheet->faultOf($template)];
        }

        return $this->render('accounting/templates.html.twig', ['dossier' => $dossier, 'template_rows' => $rows]);
    }

    #[Route('/new', name: 'app_accounting_template_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, TemplateSheet $sheet, JournalRepository $journals, AccountRepository $accounts, ThirdPartyRepository $thirdParties, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft = new TemplateDraft();
        $draft->journal = strtoupper(QueryValue::trimmed($request, 'journal'));
        $draft->lines = [new TemplateLineDraft(side: EntrySide::Debit->value, amount: TemplateAmount::Net->value), new TemplateLineDraft(side: EntrySide::Credit->value, amount: TemplateAmount::Gross->value)];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'dossier-template-new-'.$dossier->getId());
            $fields = $sheet->check($draft, $dossier);
            if (null !== $fields) {
                $sheet->create($dossier, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Modèle %s créé.', $fields->code));

                return $this->redirectToRoute('app_accounting_templates', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('accounting/template.html.twig', $this->view($dossier, $draft, null, $journals, $accounts, $thirdParties, null), new Response(status: $status));
    }

    #[Route('/{code}', name: 'app_accounting_template', requirements: ['code' => '[A-Z0-9]{1,12}'], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, string $code, Request $request, EntryTemplateRepository $templates, TemplateSheet $sheet, JournalRepository $journals, AccountRepository $accounts, ThirdPartyRepository $thirdParties, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $template = $templates->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such template.');
        $draft = TemplateDraft::of($template);
        $status = Response::HTTP_OK;
        $fault = null;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierVoter::EDIT, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $draft, 'dossier-template-'.$template->getId());
            $fields = $sheet->check($draft, $dossier, $template);
            if (null !== $fields) {
                $changed = $sheet->update($template, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? \sprintf('Modèle %s enregistré.', $fields->code) : 'Rien à enregistrer : le modèle est inchangé.');

                return $this->redirectToRoute('app_accounting_template', ['dossier' => $dossier->getId(), 'code' => $fields->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        } else {
            $fault = $sheet->faultOf($template);
        }

        return $this->render('accounting/template.html.twig', $this->view($dossier, $draft, $template, $journals, $accounts, $thirdParties, $fault), new Response(status: $status));
    }

    #[Route('/{code}/delete', name: 'app_accounting_template_delete', requirements: ['code' => '[A-Z0-9]{1,12}'], methods: ['POST'])]
    public function delete(Dossier $dossier, string $code, Request $request, EntryTemplateRepository $templates, TemplateSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $template = $templates->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such template.');
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('dossier-template-delete-'.$template->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $sheet->delete($template, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Modèle %s supprimé.', $code));

        return $this->redirectToRoute('app_accounting_templates', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    /** @return array<string, mixed> */
    private function view(Dossier $dossier, TemplateDraft $draft, ?EntryTemplate $template, JournalRepository $journals, AccountRepository $accounts, ThirdPartyRepository $thirdParties, ?string $fault): array
    {
        $journalOptions = [];
        $counterparts = [];
        foreach ($journals->findByDossier($dossier) as $journal) {
            if (JournalType::Opening !== $journal->getType()) {
                $journalOptions[] = ['value' => $journal->getCode(), 'label' => $journal->getCode().' · '.$journal->getLabel()];
            }
            if (null !== $journal->getCounterpartAccount()) {
                $counterparts[$journal->getCode()] = $journal->getCounterpartAccount()->getNumber();
            }
        }
        $labels = [];
        foreach ($draft->lines as $index => $line) {
            $labels[$index] = 1 === preg_match('/^\d+$/', $line->account)
                ? $accounts->findOneByNumber($dossier, $line->account)?->getLabel()
                : ('' === $line->account ? null : $thirdParties->findOneByAuxiliaryCode($dossier, strtoupper($line->account))?->getName());
        }
        $lines = $draft->lines;
        for ($i = 0; $i < self::SPARE_LINES && \count($lines) < 30; ++$i) {
            $lines[] = new TemplateLineDraft(side: EntrySide::Debit->value, amount: TemplateAmount::Typed->value);
        }

        return [
            'dossier' => $dossier,
            'draft' => $draft,
            'template' => $template,
            'lines' => $lines,
            'account_labels' => $labels,
            'fault' => $fault,
            'journal_options' => $journalOptions,
            'counterparts' => $counterparts,
            'base_options' => array_map(static fn (TemplateBase $base): array => ['value' => $base->value, 'label' => $base->label()], TemplateBase::cases()),
            'side_options' => array_map(static fn (EntrySide $side): array => ['value' => $side->value, 'label' => $side->label()], EntrySide::cases()),
            'amount_options' => array_map(static fn (TemplateAmount $amount): array => ['value' => $amount->value, 'label' => $amount->label()], TemplateAmount::cases()),
        ];
    }

    private function read(Request $request, TemplateDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft->code = PostValue::trimmed($request, 'code');
        $draft->label = PostValue::trimmed($request, 'label');
        $draft->journal = PostValue::trimmed($request, 'journal');
        $draft->base = PostValue::trimmed($request, 'base');
        $draft->rate = PostValue::trimmed($request, 'rate');
        $draft->lines = [];
        foreach (\array_slice(PostValue::rows($request, 'lines'), 0, 60) as $row) {
            $fixed = $row['fixed'] ?? '';
            $draft->lines[] = new TemplateLineDraft(
                $row['account'] ?? '',
                $row['thirdParty'] ?? '',
                $row['label'] ?? '',
                $row['side'] ?? '',
                $row['amount'] ?? '',
                1 === preg_match('/^\d{1,15}$/', $fixed) && (int) $fixed > 0 ? (int) $fixed : null,
            );
        }
    }
}
