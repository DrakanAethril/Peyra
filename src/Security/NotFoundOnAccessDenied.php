<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Twig\Environment;

/**
 * A signed-in person refused by access_control or a Voter gets a 404, never a 403 (README of the
 * plan, § 8): the « Page introuvable » page, the very one a missing page shows. The page is
 * rendered here because the firewall refuses an exception thrown by this handler. Somebody not
 * signed in is still sent to the sign-in page by the entry point.
 */
final readonly class NotFoundOnAccessDenied implements AccessDeniedHandlerInterface
{
    public function __construct(private Environment $twig)
    {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new Response($this->twig->render('bundles/TwigBundle/Exception/error.html.twig', [
            'status_code' => Response::HTTP_NOT_FOUND,
            'status_text' => 'Not Found',
        ]), Response::HTTP_NOT_FOUND);
    }
}
