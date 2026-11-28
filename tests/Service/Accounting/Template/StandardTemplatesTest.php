<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Template;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryTemplate;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\EntrySide;
use App\Enum\Dossier\DossierKind;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Accounting\Template\TemplateCall;
use App\Service\Accounting\Template\TemplateFile;
use App\Service\Accounting\Template\TemplateRules;
use App\Service\Dossier\Library\TemplateFactory;
use App\Service\Shared\Money;
use App\Tests\Support\DossierFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The standard entry templates of fixtures/accounting/standard-templates.yaml (step 3.3), in a
 * dossier of the PCG 2025 and of the PCG 2026 with the journals of a new dossier: each is a
 * template the fiche accepts, and its call - amounts written here by hand, the lines « à saisir »
 * filled as a student would - gives a balanced entry on the expected accounts.
 */
final class StandardTemplatesTest extends TestCase
{
    /** @var array<int|string, Account> account numbers are numeric strings, hence integer keys */
    private array $accounts = [];

    /** @var array<string, Journal> */
    private array $journals = [];

    /** @return iterable<string, array{string}> */
    public static function charts(): iterable
    {
        yield 'PCG 2025' => ['pcg-2025'];
        yield 'PCG 2026' => ['pcg-2026'];
    }

    #[DataProvider('charts')]
    public function testEachTemplateGivesABalancedEntryOnTheRightAccounts(string $chart): void
    {
        $this->dossierOn($chart);
        // code => [amount asked, in cents; the amounts « à saisir », in cents, by line; the entry expected: [account, debit, credit]]
        $cases = [
            // 40 000,00 of capital subscribed.
            'CAPSOUS' => [4_000_000, [], [['456100', 4_000_000, 0], ['101100', 0, 4_000_000]]],
            // The fifth of it paid in at the bank: the journal's 512000 is the counterpart.
            'CAPLIBN' => [800_000, [], [['456100', 0, 800_000], ['512000', 800_000, 0]]],
            'CAPVERS' => [800_000, [], [['101100', 800_000, 0], ['101300', 0, 800_000]]],
            // A machine of 12 500,00 brought in kind.
            'CAPNAT' => [1_250_000, [], [['215400', 1_250_000, 0], ['456100', 0, 1_250_000]]],
            // 1 440,00 TTC at 20 %: TVA = 1 440 × 20 / 120 = 240,00, HT 1 200,00.
            'CAPFRAIS' => [144_000, [], [['622700', 120_000, 0], ['445660', 24_000, 0], ['401000', 0, 144_000]]],
            // 30 000,00 subscribed: 20 000,00 of nominal and 10 000,00 of premium.
            'CAPAUGN' => [3_000_000, [1 => 2_000_000, 2 => 1_000_000], [['456100', 3_000_000, 0], ['101100', 0, 2_000_000], ['104100', 0, 1_000_000]]],
            'CAPINCR' => [1_500_000, [], [['106800', 1_500_000, 0], ['101300', 0, 1_500_000]]],
            // A profit of 50 000,00: 5 % to the legal reserve, 20 000,00 kept, 25 000,00 paid out, the rest carried forward.
            'AFFRES' => [5_000_000, [1 => 250_000, 2 => 2_000_000, 3 => 2_500_000, 4 => 250_000], [['120000', 5_000_000, 0], ['106100', 0, 250_000], ['106800', 0, 2_000_000], ['457000', 0, 2_500_000], ['110000', 0, 250_000]]],
            'AFFPERTE' => [735_040, [], [['119000', 735_040, 0], ['129000', 0, 735_040]]],
        ];

        $file = TemplateFile::read(\dirname(__DIR__, 4).'/fixtures/accounting/standard-templates.yaml');
        self::assertSame(array_keys($cases), array_map(static fn ($draft): string => $draft->code, $file->drafts));

        foreach ($file->drafts as $index => $draft) {
            [$amount, $typed, $expected] = $cases[$draft->code];
            $type = $file->journalTypes[$index];
            self::assertNotNull($type, $draft->code.' names its journal by its type');
            $journal = null;
            foreach ($this->journals as $candidate) {
                if ($candidate->getType() === $type) {
                    $journal = $candidate;
                    break;
                }
            }
            self::assertNotNull($journal, $draft->code);
            $draft->journal = $journal->getCode();

            $fields = TemplateRules::check($draft, fn (string $code): ?Journal => $this->journals[$code] ?? null, fn (string $number): ?Account => $this->accounts[$number] ?? null, static fn (): null => null, static fn (): null => null);
            self::assertNotNull($fields, $draft->code.' : '.implode(' ', $draft->errors));
            $template = new EntryTemplate($journal->getDossier(), $fields->code, $fields->label, $fields->journal);
            $template->describe($fields->code, $fields->label, $fields->journal, $fields->base, $fields->vatRate, $fields->lines);

            $call = TemplateCall::lines($template, new \DateTimeImmutable('2026-06-30'), Money::cents($amount), '', '', fn (string $number): ?Account => $this->accounts[$number] ?? null);
            self::assertSame([], $call['errors'], $draft->code);

            $entry = [];
            foreach ($call['lines'] as $position => $line) {
                $debit = $line->debit;
                $credit = $line->credit;
                if (isset($typed[$position])) {
                    self::assertSame([0, 0], [$debit, $credit], $draft->code.' : line '.$position.' is to type');
                    EntrySide::Debit === $fields->lines[$position]['side'] ? $debit = $typed[$position] : $credit = $typed[$position];
                }
                $entry[] = [$line->account, $debit, $credit];
            }
            $counterpart = $journal->getCounterpartAccount();
            if (null !== $counterpart) {
                $gap = array_sum(array_column($entry, 1)) - array_sum(array_column($entry, 2));
                $entry[] = [$counterpart->getNumber(), max(0, -$gap), max(0, $gap)];
            }

            self::assertSame($expected, $entry, $draft->code);
            self::assertSame(array_sum(array_column($entry, 1)), array_sum(array_column($entry, 2)), $draft->code.' balances');
        }
    }

    /** A dossier of that chart, every reference account copied, with the journals of a new dossier. */
    private function dossierOn(string $code): void
    {
        $chart = new ReferenceCharts(\dirname(__DIR__, 4).'/fixtures/pcg')->get($code);
        self::assertNotNull($chart);
        $dossier = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-06-30'), null, null, new \DateTimeImmutable('2026-11-28 10:00'), null);
        foreach ($chart->copiedAccounts() as $reference) {
            $number = $reference->dossierNumber();
            $this->accounts[$number] = new Account($dossier, $number, $reference->label, AccountNumber::isLettrableByDefault($number), AccountNumber::isCollectiveByDefault($number), AccountOrigin::Reference);
        }
        foreach (TemplateFactory::JOURNALS as [$journal, $label, $type, $counterpart]) {
            $this->journals[$journal] = new Journal($dossier, $journal, $label, $type, '' === $counterpart ? null : $this->accounts[$counterpart]);
        }
    }
}
