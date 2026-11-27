<?php

declare(strict_types=1);

namespace App\Service\Identity;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The two links to Formatool, the establishment's campus platform (README of the plan, § 9): the
 * « Formatool » entry of the avatar menu, and the forgotten password. Peyra keeps no password, and
 * Formatool's procedure is its sign-in by a mailed link (`/login/magic-link`), after which the
 * person changes their password in their Formatool profile. `PEYRA_PASSWORD_HELP_URL` overrides
 * that link when the procedure lives elsewhere. An empty address draws no link.
 */
final readonly class FormatoolLinks
{
    /** Formatool's route `app_login_magic_request` (PublicMagicLoginController), public. */
    public const string PASSWORD_PROCEDURE_PATH = '/login/magic-link';

    private ?string $formatoolUrl;
    private ?string $passwordHelpUrl;

    public function __construct(
        #[Autowire(env: 'FORMATOOL_URL')] string $formatoolUrl,
        #[Autowire(env: 'PEYRA_PASSWORD_HELP_URL')] string $passwordHelpUrl,
    ) {
        $this->formatoolUrl = self::address($formatoolUrl);
        $this->passwordHelpUrl = self::address($passwordHelpUrl);
    }

    /** Formatool's home, or null when no address is configured. */
    public function home(): ?string
    {
        return null === $this->formatoolUrl ? null : $this->formatoolUrl.'/';
    }

    /** Where a forgotten password is handled, or null when nothing is configured. */
    public function passwordHelp(): ?string
    {
        if (null !== $this->passwordHelpUrl) {
            return $this->passwordHelpUrl;
        }

        return null === $this->formatoolUrl ? null : $this->formatoolUrl.self::PASSWORD_PROCEDURE_PATH;
    }

    /**
     * An http(s) address without its trailing slashes, or null. Anything else (a typo, a
     * `javascript:` scheme) is no address: a link drawn from configuration is still a link.
     */
    private static function address(string $value): ?string
    {
        $value = rtrim(trim($value), '/');
        if ('' === $value || 1 !== preg_match('~^https?://[^\s/?#]+~i', $value)) {
            return null;
        }

        return $value;
    }
}
