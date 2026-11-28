<?php

declare(strict_types=1);

namespace App\Controller\Document;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Document\InvoiceFault;
use App\Enum\Document\InvoiceMention;
use App\Security\Voter\DossierVoter;
use App\Service\Document\Generator\InvoiceGenerator;
use App\Service\Document\Generator\InvoiceOrder;
use App\Service\Dossier\Library\TemplateCard;
use App\Service\Dossier\Library\TemplateLibrary;
use App\Service\Shared\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Pièces › Générateur (design-ux.md § 3.3, gabarit A; lot 2, step 2.1b): the teacher describes a
 * supplier's invoice of a template they may change - supplier, number, dates, lines, rates - ticks
 * the faults it should carry, sees its PDF (Ctrl Entrée), then « Générer » deposits it into the
 * template's inbox with its read data. The form is a GET (the preview's address is the form
 * itself); only « Générer » posts, its token as the button's value.
 */
#[Route('/teacher/documents/generator')]
final class InvoiceGeneratorController extends AbstractController
{
    #[Route('', name: 'app_generator', methods: ['GET'])]
    public function choose(Request $request, TemplateLibrary $library, #[CurrentUser] User $me): Response
    {
        $templates = $this->editableTemplates($library, $me);
        $id = QueryValue::int($request, 'dossier');
        foreach ($templates as $template) {
            if ($template->getId() === $id) {
                return $this->redirectToRoute('app_generator_dossier', ['dossier' => $id]);
            }
        }

        return $this->render('document/generator.html.twig', [
            'dossier' => null,
            'template_options' => self::templateOptions($templates),
        ]);
    }

    #[Route('/{dossier}', name: 'app_generator_dossier', requirements: ['dossier' => '\d+'], methods: ['GET', 'POST'])]
    public function generate(Dossier $dossier, Request $request, InvoiceGenerator $generator, TemplateLibrary $library, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $this->assertEditableTemplate($dossier);
        $status = Response::HTTP_OK;
        $preview = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('generator-'.$dossier->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $order = InvoiceOrder::fromParameters($request->request->all());
            $invoice = $generator->prepare($dossier, $order);
            if (null !== $invoice) {
                $made = $generator->generate($dossier, $invoice, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('%s générée : elle attend dans la boîte de réception du modèle%s.', $invoice->fileName(), \count($made) > 1 ? ', deux fois' : ''));

                return $this->redirectToRoute('app_document', ['dossier' => $dossier->getId(), 'id' => $made[0]->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        } elseif ($request->query->has('supplier')) {
            $order = InvoiceOrder::fromParameters($request->query->all());
            if (null === $generator->prepare($dossier, $order)) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } else {
                $preview = $this->generateUrl('app_generator_preview', ['dossier' => $dossier->getId()] + $order->toParameters());
            }
        } else {
            $order = InvoiceOrder::on($dossier->getDossierDate());
        }

        return $this->render('document/generator.html.twig', [
            'dossier' => $dossier,
            'order' => $order,
            'preview' => $preview,
            'template_options' => self::templateOptions($this->editableTemplates($library, $me)),
            'supplier_options' => array_map(static fn (ThirdParty $supplier): array => ['value' => $supplier->getCode(), 'label' => $supplier->getCode().' · '.$supplier->getName()], $generator->suppliers($dossier)),
            'mention_options' => array_map(static fn (InvoiceMention $mention): array => ['value' => $mention->value, 'label' => $mention->label()], InvoiceMention::cases()),
            'fault_options' => array_map(static fn (InvoiceFault $fault): array => ['value' => $fault->value, 'label' => $fault->label(), 'checked' => $order->hasFault($fault->value)], InvoiceFault::cases()),
        ], new Response(status: $status));
    }

    #[Route('/{dossier}/preview.pdf', name: 'app_generator_preview', requirements: ['dossier' => '\d+'], methods: ['GET'])]
    public function preview(Dossier $dossier, Request $request, InvoiceGenerator $generator): Response
    {
        $this->assertEditableTemplate($dossier);
        $invoice = $generator->prepare($dossier, InvoiceOrder::fromParameters($request->query->all()));
        if (null === $invoice) {
            throw new NotFoundHttpException('Not an invoice the generator can print.');
        }

        return new Response($generator->pdf($dossier, $invoice), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, 'apercu.pdf'),
        ]);
    }

    /** A template the teacher may change; anything else is not there. */
    private function assertEditableTemplate(Dossier $dossier): void
    {
        if (!$dossier->isTemplate() || !$this->isGranted(DossierVoter::EDIT, $dossier)) {
            throw new NotFoundHttpException('Not a template of yours to change.');
        }
    }

    /** @return list<Dossier> */
    private function editableTemplates(TemplateLibrary $library, User $me): array
    {
        return array_values(array_filter(
            array_map(static fn (TemplateCard $card): Dossier => $card->template, $library->cards($me)),
            fn (Dossier $template): bool => $this->isGranted(DossierVoter::EDIT, $template),
        ));
    }

    /**
     * @param list<Dossier> $templates
     *
     * @return list<array{value: int, label: string}>
     */
    private static function templateOptions(array $templates): array
    {
        return array_map(static fn (Dossier $template): array => ['value' => (int) $template->getId(), 'label' => $template->getLibraryTitle()], $templates);
    }
}
