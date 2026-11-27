<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * A signed-in person refused by access_control or a Voter gets a 404, never a 403 (README of the
 * plan, § 8): the firewall turns the exception thrown here into the « Page introuvable » page.
 * Somebody not signed in is still sent to the sign-in page by the entry point.
 */
final class NotFoundOnAccessDenied implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        throw new NotFoundHttpException('Not found.', $accessDeniedException);
    }
}
