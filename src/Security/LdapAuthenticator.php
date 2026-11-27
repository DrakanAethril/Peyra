<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Identity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * The sign-in form (POST /login) against the establishment's directory, as in Formatool. The whole
 * check happens in SignIn before a passport exists - throttling, directory, password, entry rule -
 * so that a refused person never gets an account and every outcome reaches the journal; the
 * passport then only carries the account.
 */
final class LdapAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly SignIn $signIn,
        private readonly CsrfTokenManagerInterface $csrfTokens,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
    ) {
    }

    /**
     * A POST from somebody already signed in is the form coming back from the browser's history,
     * not an attempt: the /login route sends them home. Checked after the parent so that reading
     * the token does not start a session on every request (the firewall is lazy).
     */
    public function supports(Request $request): bool
    {
        return parent::supports($request) && null === $this->security->getUser();
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $login = trim($request->request->getString('_username'));
        $password = $request->request->getString('_password');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $login);

        if (!$this->csrfTokens->isTokenValid(new CsrfToken('authenticate', $request->request->getString('_csrf_token')))) {
            throw new CustomUserMessageAuthenticationException('La page a expiré. Saisissez de nouveau votre mot de passe.');
        }

        $user = $this->signIn->withPassword($login, $password, $request->getClientIp());

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): User => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($target ?? $this->urlGenerator->generate('app_home'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }
}
