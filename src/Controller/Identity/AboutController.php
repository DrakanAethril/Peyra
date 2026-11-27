<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « À propos » (design-ux.md § 2.1): the version, the licence, the link to the source code
 * (AGPL § 13). In the public shell, readable without signing in.
 */
final class AboutController extends AbstractController
{
    public const string SOURCE_URL = 'https://github.com/DrakanAethril/Peyra';

    #[Route('/about', name: 'app_about', methods: ['GET'])]
    public function about(#[Autowire(env: 'PEYRA_VERSION')] string $version): Response
    {
        return $this->render('identity/about.html.twig', [
            'version' => '' === trim($version) || 'dev' === $version ? null : $version,
            'source_url' => self::SOURCE_URL,
        ]);
    }
}
