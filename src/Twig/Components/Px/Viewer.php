<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The viewer of a pièce (design-ui.md § 4.12): a bar - the file's name, the zoom, « Télécharger »,
 * « Agrandir » (F7) - over a grey stage holding the page. A picture is drawn by the page itself
 * (zoom 50 to 200 % by steps of 25, « + », « − », « 0 » when the viewer has the focus, Ctrl and
 * the wheel; fitted to the width at first), a PDF by the browser's own reader in a frame (its
 * zoom, its pages, its keys: design-ui.md § 7, item 1). `kind` is « pdf » or « image »; a picture
 * needs its `alt`, the component refuses one without.
 *
 *     <twig:Px:Viewer src="…/file" kind="image" name="ticket.jpg" alt="Ticket Station U" download="…/file?download=1"/>
 */
#[AsTwigComponent('Px:Viewer')]
final class Viewer
{
    private const array KINDS = ['pdf', 'image'];

    public string $src;
    public string $kind;
    public string $name;
    public ?string $alt = null;
    public ?string $download = null;

    public function mount(string $src, string $kind, string $name, ?string $alt = null, ?string $download = null): void
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(\sprintf('Px:Viewer kind is one of %s, not "%s".', implode(', ', self::KINDS), $kind));
        }
        if ('image' === $kind && (null === $alt || '' === trim($alt))) {
            throw new \InvalidArgumentException('Px:Viewer shows a picture with its alt text.');
        }
        $this->src = $src;
        $this->kind = $kind;
        $this->name = $name;
        $this->alt = $alt;
        $this->download = $download;
    }
}
