<?php

declare(strict_types=1);

namespace App\Tests\Service\Mission\AnswerKey;

use App\Service\Mission\AnswerKey\AnswerKeyReading;
use PHPUnit\Framework\TestCase;

/**
 * The answer key's rules, pure (step 2.9b): what an entry's fingerprint reads, which entries of the
 * teacher's copy are expected (those its starting state did not hold, one baseline fingerprint for
 * one entry), the optional marks kept across a new record, the tolerances read from the form.
 */
final class AnswerKeyReadingTest extends TestCase
{
    public function testTheFingerprintReadsWhatTheEntrySaysAndNothingElse(): void
    {
        $rent = self::rent('draft');

        self::assertSame(AnswerKeyReading::fingerprint($rent), AnswerKeyReading::fingerprint(self::rent('draft')));
        self::assertNotSame(AnswerKeyReading::fingerprint($rent), AnswerKeyReading::fingerprint(self::rent('validated')));
        $other = $rent;
        $other['lines'][0]['debit'] = 320001;
        self::assertNotSame(AnswerKeyReading::fingerprint($rent), AnswerKeyReading::fingerprint($other));
    }

    public function testTheExpectedEntriesAreTheOnesTheStartDidNotHold(): void
    {
        // The template held the rent as a draft, twice (two identical drafts), and the opening entry.
        $opening = self::entry('AN', '2026-01-01', 'AN-2026', 'validated', [['101300', null, 0, 500000], ['512100', null, 500000, 0]]);
        $baseline = array_map(AnswerKeyReading::fingerprint(...), [$opening, self::rent('draft'), self::rent('draft')]);
        // The teacher validated one of the two drafts and wrote a purchase.
        $purchase = self::entry('ACH', '2026-03-20', 'FA-2603-0112', 'draft', [['601000', null, 270700, 0], ['445660', null, 54140, 0], ['401000', 'F0001', 0, 324840]]);
        $copy = [$opening, self::rent('draft'), self::rent('validated'), $purchase];

        $expected = AnswerKeyReading::expected($copy, $baseline, []);

        self::assertSame(['LOY-2026-03', 'FA-2603-0112'], array_column($expected, 'document'));
        self::assertSame(['validated', 'draft'], array_column($expected, 'state'));
        self::assertSame([false, false, false], array_column($expected[1]['lines'], 'optional'));
        self::assertSame(['601000', '445660', '401000'], array_column($expected[1]['lines'], 'account'));
        self::assertSame('F0001', $expected[1]['lines'][2]['thirdParty']);
        self::assertSame(324840, $expected[1]['lines'][2]['credit']);
    }

    public function testRecordingAgainKeepsTheOptionalLinesOfTheEntriesThatDidNotMove(): void
    {
        $purchase = self::entry('ACH', '2026-03-20', 'FA-2603-0112', 'draft', [['601000', null, 270700, 0], ['445660', null, 54140, 0], ['401000', 'F0001', 0, 324840]]);
        $first = AnswerKeyReading::withOptional(AnswerKeyReading::expected([$purchase, self::rent('validated')], [], []), ['0.1', '1.0']);
        $changed = self::rent('validated');
        $changed['label'] = 'Loyer de mars';

        $again = AnswerKeyReading::expected([$purchase, $changed], [], $first);

        self::assertSame([false, true, false], array_column($again[0]['lines'], 'optional'));
        self::assertSame([false, false, false], array_column($again[1]['lines'], 'optional'));
    }

    public function testEquivalentAccountsAreGroupsOfTwoOrMoreNumbers(): void
    {
        self::assertSame([[['601000', '607000'], ['606300', '606400', '606800']], null], AnswerKeyReading::equivalentAccounts("601000 607000\n\n606300, 606400; 606800 = 606400\n"));
        self::assertSame([[], null], AnswerKeyReading::equivalentAccounts('  '));
        self::assertSame([[], 'Ligne 1 : « 601 » n’est pas un numéro de compte (6 à 8 chiffres).'], AnswerKeyReading::equivalentAccounts('601 607000'));
        self::assertSame([[], 'Ligne 2 : nommez au moins deux comptes admis l’un pour l’autre.'], AnswerKeyReading::equivalentAccounts("601000 607000\n606300 606300"));
    }

    public function testTheDateWindowIsZeroToThirtyOneDays(): void
    {
        self::assertSame([0, null], AnswerKeyReading::dateWindow(''));
        self::assertSame([3, null], AnswerKeyReading::dateWindow(' 3 '));
        self::assertSame([31, null], AnswerKeyReading::dateWindow('31'));
        self::assertSame([0, 'Un nombre de jours, de 0 à 31.'], AnswerKeyReading::dateWindow('32'));
        self::assertSame([0, 'Un nombre de jours, de 0 à 31.'], AnswerKeyReading::dateWindow('-2'));
    }

    /** @return array{journal: string, date: string, document: string, label: string, state: string, lines: list<array{account: string, thirdParty: ?string, label: string, debit: int, credit: int}>} */
    private static function rent(string $state): array
    {
        return self::entry('ACH', '2026-03-01', 'LOY-2026-03', $state, [['613200', null, 320000, 0], ['445660', null, 64000, 0], ['401000', 'F0009', 0, 384000]]);
    }

    /**
     * @param list<array{0: string, 1: ?string, 2: int, 3: int}> $lines
     *
     * @return array{journal: string, date: string, document: string, label: string, state: string, lines: list<array{account: string, thirdParty: ?string, label: string, debit: int, credit: int}>}
     */
    private static function entry(string $journal, string $date, string $document, string $state, array $lines): array
    {
        return ['journal' => $journal, 'date' => $date, 'document' => $document, 'label' => 'Écriture '.$document, 'state' => $state, 'lines' => array_map(static fn (array $line): array => ['account' => $line[0], 'thirdParty' => $line[1], 'label' => '', 'debit' => $line[2], 'credit' => $line[3]], $lines)];
    }
}
