<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Identity\FormatoolLinks;
use Twig\Attribute\AsTwigFunction;

/** The avatar menu's « Formatool » entry, drawn only when its address is configured. */
final readonly class FormatoolExtension
{
    public function __construct(private FormatoolLinks $links)
    {
    }

    #[AsTwigFunction('formatool_url')]
    public function home(): ?string
    {
        return $this->links->home();
    }
}
