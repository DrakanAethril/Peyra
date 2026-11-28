<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Accounting\LetteringMode;
use App\Repository\Accounting\AccountRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Lettering\Lettering;
use App\Service\Accounting\Lettering\LetteringBoard;
use App\Service\Accounting\Lettering\LetteringKey;
use App\Service\Accounting\Lettering\LetteringRefused;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Comptabilité › Consultation › Lettrage (lot 1, step 1.6; design-ux.md § 3.2, gabarit X): the
 * accounts and third parties with lines left to letter, then the face-à-face of one - its debits
 * on the left, its credits on the right -, the selection lettered by Ctrl Entrée, a group délettré,
 * the last one undone by Ctrl Z, and the automatic lettering by amount or by document, previewed
 * before it is done (proposed by default at level G). Every gesture goes through Lettering.
 */
#[Route('/d/{dossier}/accounting/lettering', requirements: ['dossier' => '\d+'])]
final class LetteringController extends AbstractController
{
    #[Route('', name: 'app_accounting_lettering', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, LetteringBoard $board, Lettering $lettering, AssistanceLevels $assistance, AccountRepository $accounts): Response
    {
        $typed = mb_strtoupper(QueryValue::trimmed($request, 'account'));
        $on = QueryValue::trimmed($request, 'on');
        $level = $assistance->for($dossier);
        $auto = 'none' === QueryValue::trimmed($request, 'auto') ? null : (LetteringMode::tryFrom(QueryValue::trimmed($request, 'auto')) ?? (AssistanceLevel::Guided === $level ? LetteringMode::Amount : null));
        $collective = 1 === preg_match('/^\d+$/', $typed) ? $accounts->findOneByNumber($dossier, $typed) : null;
        if (null !== $collective && (!$collective->isCollective() || !$collective->isLettrable())) {
            $collective = null;
        }

        [$key, $error] = '' === $typed || null !== $collective ? [null, null] : $board->key($dossier, $typed, $on);
        $view = null === $key ? null : $board->view($dossier, $key, QueryValue::bool($request, 'all'));
        $proposals = null === $view || null === $auto ? [] : LetteringBoard::lettered($lettering->proposals($view->key, $auto), $view->usedLetters);
        $proposed = [];
        foreach ($proposals as $group) {
            foreach ($group['lines'] as $line) {
                $proposed[(int) $line->getId()] = $group['letter'];
            }
        }

        return $this->render('accounting/lettering.html.twig', [
            'dossier' => $dossier,
            'typed' => $typed,
            'error' => $error,
            'collective' => $collective,
            'lettering_rows' => null === $key ? $board->rows($collective) : [],
            'key' => $key,
            'face' => $view,
            'all' => QueryValue::bool($request, 'all'),
            'level' => $level,
            'auto' => $auto,
            'proposals' => $proposals,
            'proposed' => $proposed,
            'last' => QueryValue::trimmed($request, 'last'),
            'here' => ReturnPath::here($request->getPathInfo(), (string) $request->getQueryString()),
            'return_path' => ReturnPath::of(QueryValue::trimmed($request, 'return'), (int) $dossier->getId()),
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/letter', name: 'app_accounting_lettering_letter', methods: ['POST'])]
    public function letter(Dossier $dossier, Request $request, Lettering $lettering, #[CurrentUser] User $me): Response
    {
        $this->refuseUnlessEditable($dossier, $request);
        $last = null;
        try {
            $result = $lettering->letter($dossier, PostValue::intList($request, 'lines'), $me);
            $this->addFlash('status', $result->message());
            $last = $result->letter;
        } catch (LetteringRefused $refused) {
            $this->addFlash('lettering_fault', $refused->getMessage());
        }

        return $this->back($dossier, $request, ['last' => $last]);
    }

    #[Route('/unletter', name: 'app_accounting_lettering_unletter', methods: ['POST'])]
    public function unletter(Dossier $dossier, Request $request, LetteringBoard $board, Lettering $lettering, #[CurrentUser] User $me): Response
    {
        $this->refuseUnlessEditable($dossier, $request);
        $key = $this->key($dossier, $request, $board);
        $letter = PostValue::trimmed($request, 'letter');
        try {
            $count = $lettering->unletter($dossier, $key, $letter, $me);
            $this->addFlash('status', \sprintf('Lettrage %s défait : %d lignes à lettrer de nouveau.', mb_strtoupper($letter), $count));
        } catch (LetteringRefused $refused) {
            $this->addFlash('lettering_fault', $refused->getMessage());
        }

        return $this->back($dossier, $request);
    }

    #[Route('/auto', name: 'app_accounting_lettering_auto', methods: ['POST'])]
    public function auto(Dossier $dossier, Request $request, LetteringBoard $board, Lettering $lettering, #[CurrentUser] User $me): Response
    {
        $this->refuseUnlessEditable($dossier, $request);
        $key = $this->key($dossier, $request, $board);
        $mode = LetteringMode::tryFrom(PostValue::trimmed($request, 'mode')) ?? throw new NotFoundHttpException('No such lettering.');
        try {
            $letters = $lettering->letterProposals($dossier, $key, $mode, $me);
            $this->addFlash('status', \sprintf('Lettrage automatique %s : %d groupe%s lettré%s (%s).', $mode->phrase(), \count($letters), \count($letters) > 1 ? 's' : '', \count($letters) > 1 ? 's' : '', implode(', ', $letters)));
        } catch (LetteringRefused $refused) {
            $this->addFlash('lettering_fault', $refused->getMessage());
        }

        return $this->back($dossier, $request, ['auto' => 'none']);
    }

    private function refuseUnlessEditable(Dossier $dossier, Request $request): void
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('lettering-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to letter.');
        }
    }

    private function key(Dossier $dossier, Request $request, LetteringBoard $board): LetteringKey
    {
        [$key] = $board->key($dossier, PostValue::trimmed($request, 'account'), PostValue::trimmed($request, 'on'));

        return $key ?? throw new NotFoundHttpException('No such account to letter.');
    }

    /** @param array<string, ?string> $extra */
    private function back(Dossier $dossier, Request $request, array $extra = []): RedirectResponse
    {
        return $this->redirectToRoute('app_accounting_lettering', array_filter([
            'dossier' => $dossier->getId(),
            'account' => PostValue::trimmed($request, 'account'),
            'on' => PostValue::trimmed($request, 'on'),
            'all' => '1' === PostValue::trimmed($request, 'all') ? '1' : null,
            ...$extra,
        ], static fn (int|string|null $value): bool => null !== $value && '' !== $value), Response::HTTP_SEE_OTHER);
    }
}
