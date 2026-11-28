<?php

declare(strict_types=1);

namespace App\Service\Document\Generator;

use App\Enum\Document\InvoiceLayout;

/**
 * A fictitious supplier's look on paper (step 2.1b, pure): its layout, its colour and its
 * monogram, all read from its code and name - so that one supplier's invoices always look alike
 * and differ from another's. The colours are a fictitious company's, printed on its invoice: none
 * is Peyra's.
 */
final readonly class InvoiceCharter
{
    /** Dark inks that print well on white. */
    private const array COLOURS = ['#7a2e1f', '#1f5d4c', '#2b3f73', '#6b4c1e', '#5a2a5e', '#24566e'];

    private function __construct(
        public InvoiceLayout $layout,
        public string $colour,
        public string $monogram,
    ) {
    }

    public static function of(string $code, string $name): self
    {
        $hash = crc32($code);
        $layouts = InvoiceLayout::cases();

        return new self($layouts[$hash % \count($layouts)], self::COLOURS[intdiv($hash, \count($layouts)) % \count(self::COLOURS)], self::monogram($name));
    }

    /** The initials of the name's first two words of three letters or more (« Scierie du Haut-Forez » → « SH »). */
    private static function monogram(string $name): string
    {
        $words = array_values(array_filter(preg_split('/[\s\-\'’]+/u', $name) ?: [], static fn (string $word): bool => mb_strlen($word) >= 3));
        $initials = array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), \array_slice($words, 0, 2));

        return [] === $initials ? mb_strtoupper(mb_substr(trim($name), 0, 1)) : implode('', $initials);
    }
}
