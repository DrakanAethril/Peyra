<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\EntryTemplate;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Accounting\EntryTemplateRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Template\GuidedEntry;
use App\Service\Accounting\Template\GuidedEntryInput;
use App\Service\Accounting\Template\GuidedEntryPreview;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;
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
 * Comptabilité › Saisie › guidée (design-ux.md § 3.2, gabarit F): a template, a few fields, the
 * entry shown as it will be written (`preview`, a Turbo frame the form refreshes as it is filled),
 * then F10 writes it to the brouillard. Open at levels G and B and to teachers (GuidedEntry::isOpen);
 * hidden otherwise, like any screen one may not reach: a 404. For whoever works in the dossier.
 */
#[Route('/d/{dossier}/accounting/entry/guided', requirements: ['dossier' => '\d+'])]
final class GuidedEntryController extends AbstractController
{
    #[Route('', name: 'app_accounting_guided', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, EntryTemplateRepository $templates, GuidedEntry $guided, AssistanceLevels $levels): Response
    {
        $this->open($dossier, $levels);
        $offered = $this->offered($dossier, $templates);
        $template = $this->chosen($offered, QueryValue::trimmed($request, 'template'));
        $input = $this->input($dossier, $request, $template, false);

        return $this->render('accounting/guided_entry.html.twig', $this->view($dossier, $offered, $template, $input, null === $template ? null : $guided->preview($dossier, $template, $input), $guided, false));
    }

    #[Route('/preview', name: 'app_accounting_guided_preview', methods: ['GET'])]
    public function preview(Dossier $dossier, Request $request, EntryTemplateRepository $templates, GuidedEntry $guided, AssistanceLevels $levels): Response
    {
        $this->open($dossier, $levels);
        $template = $this->chosen($this->offered($dossier, $templates), QueryValue::trimmed($request, 'template')) ?? throw new NotFoundHttpException('No such template.');
        $input = $this->input($dossier, $request, $template, false);

        return $this->render('accounting/_guided_preview.html.twig', [
            'dossier' => $dossier,
            'template' => $template,
            'preview' => $guided->preview($dossier, $template, $input),
            'shown' => null !== $input->amount || !$template->asksAmount(),
        ]);
    }

    #[Route('', name: 'app_accounting_guided_save', methods: ['POST'])]
    public function save(Dossier $dossier, Request $request, EntryTemplateRepository $templates, GuidedEntry $guided, AssistanceLevels $levels, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $this->open($dossier, $levels);
        if (!$this->isCsrfTokenValid('guided-entry-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $offered = $this->offered($dossier, $templates);
        $template = $this->chosen($offered, PostValue::trimmed($request, 'template')) ?? throw new NotFoundHttpException('No such template.');
        $input = $this->input($dossier, $request, $template, true);
        $preview = $guided->preview($dossier, $template, $input);
        $entry = $guided->save($dossier, $preview, $me);
        if (null === $entry) {
            return $this->render('accounting/guided_entry.html.twig', $this->view($dossier, $offered, $template, $input, $preview, $guided, true), new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Écriture enregistrée au brouillard du journal %s : pièce %s du %s, %s.', $template->getJournal()->getCode(), $entry->getDocument(), FrenchFormat::date($entry->getEntryDate()), FrenchFormat::amountInEuros($entry->totalDebit())));

        return $this->redirectToRoute('app_accounting_guided', [
            'dossier' => $dossier->getId(),
            'template' => $template->getCode(),
            'date' => $entry->getEntryDate()->format('Y-m-d'),
            'document' => GuidedEntry::nextDocument($entry->getDocument()),
        ], Response::HTTP_SEE_OTHER);
    }

    /** For whoever works in the dossier, at a level that opens it (or a teacher). */
    private function open(Dossier $dossier, AssistanceLevels $levels): void
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !GuidedEntry::isOpen($levels->for($dossier), $this->isGranted('ROLE_TEACHER'))) {
            throw new NotFoundHttpException('No guided entry here.');
        }
    }

    /** @return list<EntryTemplate> */
    private function offered(Dossier $dossier, EntryTemplateRepository $templates): array
    {
        return array_values(array_filter($templates->findByDossier($dossier), GuidedEntry::offers(...)));
    }

    /** @param list<EntryTemplate> $offered */
    private function chosen(array $offered, string $code): ?EntryTemplate
    {
        foreach ($offered as $template) {
            if ($template->getCode() === strtoupper($code)) {
                return $template;
            }
        }

        return '' === $code ? ($offered[0] ?? null) : null;
    }

    private function input(Dossier $dossier, Request $request, ?EntryTemplate $template, bool $posted): GuidedEntryInput
    {
        $read = static fn (string $key): string => $posted ? PostValue::trimmed($request, $key) : QueryValue::trimmed($request, $key);
        $date = $read('date');
        $amount = $read('amount');

        return new GuidedEntryInput(
            '' === $date && !$posted ? $dossier->getDossierDate() : DateValue::parse($date),
            mb_substr($read('document'), 0, 40),
            strtoupper(mb_substr($read('thirdParty'), 0, 17)),
            1 === preg_match('/^\d{1,13}$/', $amount) ? (int) $amount : null,
            '' === $read('rate') && !$posted ? str_replace('.', ',', (string) $template?->getVatRate()) : mb_substr($read('rate'), 0, 8),
        );
    }

    /**
     * @param list<EntryTemplate> $offered
     *
     * @return array<string, mixed>
     */
    private function view(Dossier $dossier, array $offered, ?EntryTemplate $template, GuidedEntryInput $input, ?GuidedEntryPreview $preview, GuidedEntry $guided, bool $tried): array
    {
        return [
            'dossier' => $dossier,
            'offered' => $offered,
            'template' => $template,
            'input' => $input,
            'preview' => $preview,
            'shown' => $tried || null !== $input->amount || !(null !== $template && $template->asksAmount()),
            'asks_party' => null !== $template && $guided->asksThirdParty($dossier, $template),
            'template_options' => array_map(static fn (EntryTemplate $t): array => ['value' => $t->getCode(), 'label' => $t->getJournal()->getCode().' · '.$t->getCode().' · '.$t->getLabel()], $offered),
            'guided_open' => true,
            'tried' => $tried,
        ];
    }
}
