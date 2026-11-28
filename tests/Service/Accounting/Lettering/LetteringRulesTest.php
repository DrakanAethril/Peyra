<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Lettering;

use App\Service\Accounting\Lettering\LetteringLine;
use App\Service\Accounting\Lettering\LetteringRules;
use PHPUnit\Framework\TestCase;

/**
 * LetteringRules on the Scierie du Haut-Forez's account (401SCIE): two December invoices of the
 * opening (7 878,00 and 4 704,00, credits), their transfers (debits), January's invoice 4 158,12
 * and a down payment of 2 000,00 on it. Gaps reckoned by hand.
 */
final class LetteringRulesTest extends TestCase
{
    public function testABalancedSelectionTakesTheNextLetterInCapitals(): void
    {
        $account = [self::credit(1, 787_800), self::debit(2, 787_800), self::credit(3, 470_400, 'A'), self::debit(4, 470_400, 'A')];
        $decision = LetteringRules::decide([$account[1], $account[0]], $account, false);

        self::assertNull($decision->refusal);
        self::assertSame([1, 2], $decision->lineIds);
        self::assertSame('B', $decision->letter);
        self::assertFalse($decision->isPartial());
    }

    public function testAPartialLetteringIsInSmallLettersWhereItIsPermitted(): void
    {
        // 415 812 owed, 200 000 paid: 215 812 left on the credit side.
        $account = [self::credit(5, 415_812), self::debit(6, 200_000)];
        $decision = LetteringRules::decide($account, $account, true);

        self::assertNull($decision->refusal);
        self::assertSame('a', $decision->letter);
        self::assertSame(-215_812, $decision->gap);
        self::assertTrue($decision->isPartial());
    }

    public function testLevelsGAndBAskASelectionThatBalances(): void
    {
        $account = [self::credit(5, 415_812), self::debit(6, 200_000)];
        $decision = LetteringRules::decide($account, $account, false);

        self::assertSame("La sélection n’est pas soldée : écart de 2\u{202F}158,12 au crédit. Ajoutez les lignes qui la soldent.", $decision->refusal);
    }

    public function testTheBalanceOfAPartialGroupCompletesItUnderItsOwnLetter(): void
    {
        // a = 415 812 C + 200 000 D; the balance 215 812 D completes it: A in capitals.
        $account = [self::credit(5, 415_812, 'a'), self::debit(6, 200_000, 'a'), self::debit(7, 215_812), self::credit(1, 787_800, 'B'), self::debit(2, 787_800, 'B')];
        $decision = LetteringRules::decide([$account[2], $account[0]], $account, false);

        self::assertNull($decision->refusal);
        self::assertSame([5, 6, 7], $decision->lineIds, 'The whole partial group comes along.');
        self::assertSame('A', $decision->letter);
        self::assertSame(['a'], $decision->merged);
    }

    public function testTwoPartialGroupsMergeUnderTheSmallerLetter(): void
    {
        $account = [self::credit(1, 100_000, 'c'), self::debit(2, 40_000, 'c'), self::credit(3, 50_000, 'b'), self::debit(4, 20_000, 'b'), self::debit(5, 90_000)];
        $decision = LetteringRules::decide([$account[0], $account[2], $account[4]], $account, false);

        self::assertSame([1, 2, 3, 4, 5], $decision->lineIds);
        self::assertSame('B', $decision->letter, '40 000 + 20 000 + 90 000 = 150 000: it balances.');
    }

    public function testWhatTheRulesRefuse(): void
    {
        $account = [self::credit(1, 787_800, 'A'), self::debit(2, 787_800, 'A'), self::credit(3, 470_400), self::credit(4, 415_812), self::credit(5, 100_000, 'b'), self::debit(6, 40_000, 'b')];

        self::assertSame('Choisissez les lignes à lettrer : des débits et les crédits qui les soldent.', LetteringRules::decide([], $account, true)->refusal);
        self::assertSame('Une ligne choisie est déjà lettrée A : délettrez-la d’abord.', LetteringRules::decide([$account[0], $account[2]], $account, true)->refusal);
        self::assertSame('Un lettrage rapproche des débits et des crédits : choisissez des lignes des deux côtés.', LetteringRules::decide([$account[2], $account[3]], $account, true)->refusal);
        self::assertSame('Ces lignes forment déjà le lettrage partiel b : ajoutez celles qui le soldent.', LetteringRules::decide([$account[4]], $account, true)->refusal);
    }

    private static function debit(int $id, int $cents, ?string $letter = null): LetteringLine
    {
        return new LetteringLine($id, $cents, 0, $letter, '2026-01-'.str_pad((string) $id, 2, '0', \STR_PAD_LEFT));
    }

    private static function credit(int $id, int $cents, ?string $letter = null): LetteringLine
    {
        return new LetteringLine($id, 0, $cents, $letter, '2026-01-'.str_pad((string) $id, 2, '0', \STR_PAD_LEFT));
    }
}
