<?php

declare(strict_types=1);

namespace App\Controller\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\PayrollItem;
use App\Enum\Payroll\HeadcountCondition;
use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollCeiling;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollRateSource;
use App\Enum\Payroll\PayrollStatusCondition;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\SalaryCondition;
use App\Repository\Payroll\PayrollBodyRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Payroll\Plan\PayrollItemDraft;
use App\Service\Payroll\Plan\PayrollItemSheet;
use App\Service\Payroll\Plan\PayrollPlanInstaller;
use App\Service\Payroll\Plan\PayrollPlanView;
use App\Service\Payroll\Statement\PayrollAccounts;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Paie et social › Paramètres de paie › Plan de paie (lot 4, step 4.1; gabarits L then F): the
 * dossier's rubriques by family and place, and the fiche of one - its family, base, ceiling, the
 * rate of each side and where it comes from, its conditions, its body and its line of the
 * clarified payslip. An empty plan offers the standard one. Read by whoever reads the payroll
 * (DossierRightVoter::PAYROLL_READ), changed with F10 by whoever may change its settings
 * (PAYROLL_SETTINGS). The address of a rubrique is its code.
 */
#[Route('/d/{dossier}/payroll/items', requirements: ['dossier' => '\d+'])]
final class PayrollItemsController extends AbstractController
{
    #[Route('', name: 'app_payroll_items', methods: ['GET'])]
    public function index(Dossier $dossier, PayrollPlanView $plan): Response
    {
        $this->denyUnlessReads($dossier);

        return $this->render('payroll/items.html.twig', [
            'dossier' => $dossier,
            'families' => $plan->families($dossier),
            'kinds' => PayrollItemKind::cases(),
        ]);
    }

    #[Route('/install', name: 'app_payroll_plan_install', methods: ['POST'])]
    public function install(Dossier $dossier, Request $request, PayrollItemRepository $items, PayrollPlanInstaller $installer, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_SETTINGS, $dossier) || !$this->isCsrfTokenValid('payroll-plan-install-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        if ($items->countInDossier($dossier) > 0) {
            $this->addFlash('status', 'Le plan de paie n’est pas vide : il reste tel quel.');
        } else {
            $count = $installer->installStandard($dossier, $me);
            $entityManager->flush();
            $this->addFlash('status', \sprintf('Plan de paie standard installé : %d rubriques.', $count));
        }

        return $this->redirectToRoute('app_payroll_items', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/new', name: 'app_payroll_item_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, PayrollItemSheet $sheet, PayrollBodyRepository $bodies, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_SETTINGS, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $draft = new PayrollItemDraft();
        $draft->kind = PayrollItemKind::Contribution->value;
        $draft->base = PayrollBase::Gross->value;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'payroll-item-new-'.$dossier->getId());
            $spec = $sheet->check($draft, $dossier);
            if (null !== $spec) {
                $sheet->create($dossier, $spec, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Rubrique %s créée.', $spec->code));

                return $this->redirectToRoute('app_payroll_item', ['dossier' => $dossier->getId(), 'code' => $spec->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('payroll/item.html.twig', $this->view($dossier, $draft, null, $bodies) + ['readings' => null], new Response(status: $status));
    }

    #[Route('/{code}', name: 'app_payroll_item', requirements: ['code' => '\d{4}'], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, string $code, Request $request, PayrollItemRepository $items, PayrollItemSheet $sheet, PayrollBodyRepository $bodies, PayrollPlanView $plan, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $this->denyUnlessReads($dossier);
        $item = $items->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such rubrique.');
        $draft = PayrollItemDraft::of($item);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::PAYROLL_SETTINGS, $dossier)) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $draft, 'payroll-item-'.$item->getId());
            $spec = $sheet->check($draft, $dossier, $item);
            if (null !== $spec) {
                $changed = $sheet->update($item, $spec, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? \sprintf('Rubrique %s enregistrée.', $spec->code) : 'Rien à enregistrer : la rubrique est inchangée.');

                return $this->redirectToRoute('app_payroll_item', ['dossier' => $dossier->getId(), 'code' => $spec->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('payroll/item.html.twig', $this->view($dossier, $draft, $item, $bodies) + ['readings' => $plan->readings($dossier, $item)], new Response(status: $status));
    }

    #[Route('/{code}/delete', name: 'app_payroll_item_delete', requirements: ['code' => '\d{4}'], methods: ['POST'])]
    public function delete(Dossier $dossier, string $code, Request $request, PayrollItemRepository $items, PayrollItemSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $item = $items->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such rubrique.');
        if (!$this->isGranted(DossierRightVoter::PAYROLL_SETTINGS, $dossier) || !$this->isCsrfTokenValid('payroll-item-delete-'.$item->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $sheet->delete($item, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Rubrique %s supprimée.', $code));

        return $this->redirectToRoute('app_payroll_items', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    private function denyUnlessReads(Dossier $dossier): void
    {
        if (!$this->isGranted(DossierRightVoter::PAYROLL_READ, $dossier)) {
            throw new NotFoundHttpException('The payroll is closed to this profile.');
        }
    }

    /** @return array<string, mixed> */
    private function view(Dossier $dossier, PayrollItemDraft $draft, ?PayrollItem $item, PayrollBodyRepository $bodies): array
    {
        $options = static fn (array $cases): array => array_map(static fn (PayrollItemKind|PayrollBase|PayrollCeiling|PayrollRateSource|PayrollStatusCondition|HeadcountCondition|SalaryCondition $case): array => ['value' => $case->value, 'label' => $case->label()], $cases);

        return [
            'dossier' => $dossier,
            'draft' => $draft,
            'item' => $item,
            'default_account' => null === $item ? null : PayrollAccounts::defaultOf($item->spec()),
            'kind_options' => $options(PayrollItemKind::cases()),
            'base_options' => $options(PayrollBase::cases()),
            'ceiling_options' => $options(PayrollCeiling::cases()),
            'source_options' => $options(PayrollRateSource::cases()),
            'status_options' => $options(PayrollStatusCondition::cases()),
            'headcount_options' => $options(HeadcountCondition::cases()),
            'salary_options' => $options(SalaryCondition::cases()),
            'line_options' => array_map(static fn (PayslipLine $line): array => ['value' => $line->value, 'label' => $line->section()->label().' › '.$line->label()], PayslipLine::cases()),
            'body_options' => array_map(static fn (\App\Entity\Payroll\PayrollBody $body): array => ['value' => (string) $body->getId(), 'label' => $body->getName().' ('.$body->getKind()->label().')'], $bodies->findByDossier($dossier)),
        ];
    }

    private function read(Request $request, PayrollItemDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        foreach (['code', 'label', 'kind', 'sortOrder', 'base', 'ceiling', 'employeeSource', 'employeeKey', 'employeePath', 'employeeValue',
            'employerSource', 'employerKey', 'employerPath', 'employerValue', 'status', 'headcount', 'salary', 'body', 'line', 'account'] as $field) {
            $draft->{$field} = PostValue::trimmed($request, $field);
        }
        $draft->subtracts = '1' === PostValue::trimmed($request, 'subtracts');
        $draft->benefitInKind = '1' === PostValue::trimmed($request, 'benefitInKind');
    }
}
