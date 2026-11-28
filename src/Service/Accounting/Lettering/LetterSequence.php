<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

/**
 * The lettering letters (lot 1, step 1.6): A to Z, then AA to ZZ, AAA… up to four letters - a
 * bijective count in base 26, continuous per account (per third party on a collective). A group
 * that balances is written in capitals, a partial one in small letters, as Sage writes them: « A »
 * is lettered, « a » partly. Comparisons ignore the case: one letter names one group of an account.
 */
final class LetterSequence
{
    public const int MAX_LENGTH = 4;

    /** 1 for A, 26 for Z, 27 for AA…, whatever the case. */
    public static function ordinal(string $letter): int
    {
        $letter = mb_strtoupper($letter);
        if (1 !== preg_match('/^[A-Z]{1,'.self::MAX_LENGTH.'}$/', $letter)) {
            throw new \InvalidArgumentException(\sprintf('« %s » is not a lettering letter.', $letter));
        }
        $ordinal = 0;
        foreach (str_split($letter) as $character) {
            $ordinal = $ordinal * 26 + (\ord($character) - 64);
        }

        return $ordinal;
    }

    /** The letter of a rank, in capitals: 1 → A, 27 → AA. */
    public static function letter(int $ordinal): string
    {
        if ($ordinal < 1 || $ordinal > self::last()) {
            throw new \InvalidArgumentException(\sprintf('No lettering letter has the rank %d.', $ordinal));
        }
        $letter = '';
        while ($ordinal > 0) {
            $remainder = ($ordinal - 1) % 26;
            $letter = \chr(65 + $remainder).$letter;
            $ordinal = intdiv($ordinal - 1, 26);
        }

        return $letter;
    }

    /**
     * The letter after the highest one used on the account, in capitals; A when none is.
     *
     * @param iterable<string> $used
     */
    public static function next(iterable $used): string
    {
        $highest = 0;
        foreach ($used as $letter) {
            $highest = max($highest, self::ordinal($letter));
        }
        if ($highest >= self::last()) {
            throw new LetteringRefused('Ce compte a épuisé ses lettres de lettrage (de A à ZZZZ).');
        }

        return self::letter($highest + 1);
    }

    /** A group that does not balance yet: its letter is in small letters. */
    public static function isPartial(string $letter): bool
    {
        return mb_strtolower($letter) === $letter;
    }

    public static function same(string $one, string $other): bool
    {
        return mb_strtoupper($one) === mb_strtoupper($other);
    }

    /** The highest rank four letters reach (ZZZZ). */
    private static function last(): int
    {
        return 26 + 26 ** 2 + 26 ** 3 + 26 ** 4;
    }
}
