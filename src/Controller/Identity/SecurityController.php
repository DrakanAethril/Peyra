<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Security\SignIn;
use App\Service\Identity\FormatoolLinks;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The sign-in page (design-ux.md § 4.1, design-ui.md § 6 « Connexion »): login, the establishment's
 * password, « Se connecter ». No account creation, no « mot de passe oublié » of Peyra's own: a link
 * to the establishment's procedure, Formatool's, when one is configured (FormatoolLinks). The POST
 * is LdapAuthenticator's.
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, FormatoolLinks $formatool): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            // Every refusal of SignIn and LdapAuthenticator is a sentence written for the person; anything
            // else (a misconfigured firewall) reads as a wrong password, never as a technical message.
            'error' => null === $error ? null : ($error instanceof CustomUserMessageAuthenticationException ? $error->getMessageKey() : SignIn::BAD_CREDENTIALS),
            'password_help_url' => $formatool->passwordHelp(),
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** Intercepted by the firewall's logout listener. */
    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall handles the sign-out.');
    }
}
