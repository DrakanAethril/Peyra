<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Security\LdapAuthenticator;
use App\Security\SignIn;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Signs in as a directory account without its password, for bin/shot and the browser checks
 * (`PEYRA_SHOT_LOGIN`). Development and test only: the route does not exist in production. The
 * entry rule still applies - a refused account gets a 404 - and the journal still records it.
 */
#[Route('/_dev', env: ['dev', 'test'])]
final class DevLoginController extends AbstractController
{
    #[Route('/login-as/{login}', name: 'app_dev_login_as', methods: ['GET'])]
    public function loginAs(string $login, Request $request, SignIn $signIn, Security $security): RedirectResponse
    {
        try {
            $user = $signIn->withoutPassword($login, $request->getClientIp());
        } catch (CustomUserMessageAuthenticationException $exception) {
            throw new NotFoundHttpException($exception->getMessageKey(), $exception);
        }
        $security->login($user, LdapAuthenticator::class, 'main');

        return $this->redirectToRoute('app_home');
    }
}
