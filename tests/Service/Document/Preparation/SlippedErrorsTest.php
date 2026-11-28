<?php

declare(strict_types=1);

namespace App\Tests\Service\Document\Preparation;

use App\Enum\Document\SlipNature;
use App\Enum\Document\SlipPlace;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Document\Preparation\AnswerKey;
use App\Service\Document\Preparation\PieceReading;
use App\Service\Document\Preparation\SlippedError;
use App\Service\Document\Preparation\SlippedErrors;
use App\Service\Document\Processing\PieceParty;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * The errors slipped into a pièce (step 2.2b), on the Scierie's FA-2603-0187: 2 155,00 HT at 20 %,
 * 431,00 VAT, 2 586,00 TTC, proposed on 601000 / 445660 / 401SCIE. Every expected amount is worked
 * out by hand in the test.
 */
final class SlippedErrorsTest extends TestCase
{
    public function testTheTargetsAndTheirNatures(): void
    {
        $key = $this->key();

        self::assertSame(['thirdParty', 'totalExcludingTax', 'totalVat', 'totalIncludingTax', 'vat.0.rate', 'line.0.account', 'line.0.amount', 'line.1.account', 'line.1.amount', 'line.2.account', 'line.2.amount'], SlippedErrors::targets($key->reading, $key->proposal));
        self::assertSame(SlipNature::ThirdParty, SlippedErrors::nature('thirdParty', $key->proposal));
        self::assertSame(SlipNature::Amount, SlippedErrors::nature('totalIncludingTax', $key->proposal));
        self::assertSame(SlipNature::VatRate, SlippedErrors::nature('vat.0.rate', $key->proposal));
        self::assertSame(SlipNature::Account, SlippedErrors::nature('line.0.account', $key->proposal));
        self::assertSame(SlipNature::ThirdParty, SlippedErrors::nature('line.2.account', $key->proposal), 'An auxiliary code is a third party.');
        self::assertSame(SlipNature::Amount, SlippedErrors::nature('line.1.amount', $key->proposal));
        self::assertSame('258600', SlippedErrors::rightValue('totalIncludingTax', $key->reading, $key->proposal));
        self::assertSame('43100', SlippedErrors::rightValue('line.1.amount', $key->reading, $key->proposal));
        self::assertSame('401SCIE', SlippedErrors::rightValue('line.2.account', $key->reading, $key->proposal));
    }

    public function testTheEntryFollowsAValueOfTheReadDataOnlyWhereALineDependsOnIt(): void
    {
        $key = $this->key();
        $both = [SlipPlace::Both, SlipPlace::Reading, SlipPlace::Proposal];

        foreach (['thirdParty', 'totalExcludingTax', 'totalVat', 'totalIncludingTax', 'vat.0.rate'] as $target) {
            self::assertSame($both, SlippedErrors::places($target, $key->reading, $key->proposal, $this->parties(...)), $target);
        }
        self::assertSame([SlipPlace::Proposal], SlippedErrors::places('line.0.account', $key->reading, $key->proposal, $this->parties(...)));

        // Two expense lines: no single line follows the total excluding tax.
        $split = $this->proposal([new GridLine('601000', '', '', 200_000), new GridLine('624100', '', '', 15_500), new GridLine('445660', '', '', 43_100), new GridLine('401SCIE', '', '', 0, 258_600)]);
        self::assertSame([SlipPlace::Reading], SlippedErrors::places('totalExcludingTax', $key->reading, $split, $this->parties(...)));
        self::assertSame($both, SlippedErrors::places('totalVat', $key->reading, $split, $this->parties(...)));
        self::assertSame([SlipPlace::Reading], SlippedErrors::places('thirdParty', $key->reading, null, $this->parties(...)), 'No proposal, nothing follows.');
    }

    public function testARateReadWrongRecomputesTheVatAndKeepsTheEntryBalanced(): void
    {
        // 2 155,00 × 10 % = 215,50; the supplier's line moves by 215,50 − 431,00: 2 586,00 − 215,50 = 2 370,50.
        [$reading, $proposal] = SlippedErrors::apply($this->key(new SlippedError('vat.0.rate', SlipNature::VatRate, SlipPlace::Both, '10')), $this->parties(...));
        self::assertSame('10', $reading->vatLines[0]->rate);
        self::assertSame(43_100, $reading->vatLines[0]->vat->cents, 'The VAT read stays as printed.');
        self::assertNotNull($proposal);
        self::assertSame([[215_500, 0], [21_550, 0], [0, 237_050]], $this->amounts($proposal));
        self::assertSame($proposal->totalDebit(), $proposal->totalCredit());

        // In the entry only, at 5,5 %: 2 155,00 × 5,5 % = 118,525, half up 118,53; 2 155,00 + 118,53 = 2 273,53.
        [$reading, $proposal] = SlippedErrors::apply($this->key(new SlippedError('vat.0.rate', SlipNature::VatRate, SlipPlace::Proposal, '5.5')), $this->parties(...));
        self::assertSame('20', $reading->vatLines[0]->rate);
        self::assertNotNull($proposal);
        self::assertSame([[215_500, 0], [11_853, 0], [0, 227_353]], $this->amounts($proposal));
    }

    public function testATotalReadWrongShowsOnTheLineItDecides(): void
    {
        // The TTC read 2 685,00: the supplier's line takes it, and the entry no longer balances.
        [$reading, $proposal] = SlippedErrors::apply($this->key(new SlippedError('totalIncludingTax', SlipNature::Amount, SlipPlace::Both, '268500')), $this->parties(...));
        self::assertSame(268_500, $reading->totalIncludingTax?->cents);
        self::assertNotNull($proposal);
        self::assertSame([[215_500, 0], [43_100, 0], [0, 268_500]], $this->amounts($proposal));

        // The HT in the read data only: the entry stays right.
        [$reading, $proposal] = SlippedErrors::apply($this->key(new SlippedError('totalExcludingTax', SlipNature::Amount, SlipPlace::Reading, '251500')), $this->parties(...));
        self::assertSame(251_500, $reading->totalExcludingTax?->cents);
        self::assertNotNull($proposal);
        self::assertSame([[215_500, 0], [43_100, 0], [0, 258_600]], $this->amounts($proposal));
    }

    public function testAThirdPartyReadWrongNamesTheOtherOneInTheEntry(): void
    {
        [$reading, $proposal] = SlippedErrors::apply($this->key(new SlippedError('thirdParty', SlipNature::ThirdParty, SlipPlace::Both, 'F0002')), $this->parties(...));
        self::assertSame('F0002', $reading->thirdParty);
        self::assertNotNull($proposal);
        self::assertSame(['601000', '445660', '401BOIS'], array_map(static fn (GridLine $line): string => $line->account, $proposal->lines));
        self::assertSame('Bois du Forez FA-2603-0187', $proposal->label);
        self::assertSame([[215_500, 0], [43_100, 0], [0, 258_600]], $this->amounts($proposal));
    }

    public function testErrorsOnTheProposalAndSeveralErrorsTogether(): void
    {
        $key = $this->key(
            new SlippedError('line.0.account', SlipNature::Account, SlipPlace::Proposal, '607000'),
            new SlippedError('line.1.amount', SlipNature::Amount, SlipPlace::Proposal, '34100'),
            new SlippedError('totalIncludingTax', SlipNature::Amount, SlipPlace::Reading, '256800'),
        );
        [$reading, $proposal] = SlippedErrors::apply($key, $this->parties(...));
        self::assertSame(256_800, $reading->totalIncludingTax?->cents);
        self::assertNotNull($proposal);
        self::assertSame(['607000', '445660', '401SCIE'], array_map(static fn (GridLine $line): string => $line->account, $proposal->lines));
        self::assertSame([[215_500, 0], [34_100, 0], [0, 258_600]], $this->amounts($proposal));
        self::assertSame('Scierie du Haut-Forez FA-2603-0187', $proposal->label);

        // Without its errors, the pièce is the right one again.
        [$reading, $proposal] = SlippedErrors::apply($key->without('line.0.account')->without('line.1.amount')->without('totalIncludingTax'), $this->parties(...));
        self::assertEquals($this->key()->reading, $reading);
        self::assertEquals($this->key()->proposal, $proposal);
    }

    public function testWhatIsRefused(): void
    {
        $key = $this->key(new SlippedError('vat.0.rate', SlipNature::VatRate, SlipPlace::Both, '10'));
        $accounts = static fn (string $number): bool => \in_array($number, ['601000', '607000', '445660'], true);
        $refusal = fn (string $target, SlipPlace $place, string $wrong): ?string => SlippedErrors::refusal($key, $target, $place, $wrong, $accounts, $this->parties(...));

        self::assertNull($refusal('line.0.account', SlipPlace::Proposal, '607000'));
        self::assertNull($refusal('totalIncludingTax', SlipPlace::Both, '268500'));
        self::assertNull($refusal('line.2.account', SlipPlace::Proposal, 'F0002'));
        self::assertSame('Une erreur est déjà glissée sur cette valeur : retirez-la d’abord.', $refusal('vat.0.rate', SlipPlace::Reading, '5.5'));
        self::assertSame('Cette valeur ne reçoit pas d’erreur.', $refusal('line.7.amount', SlipPlace::Proposal, '100'));
        self::assertSame('Une erreur sur une ligne de l’écriture n’apparaît que dans l’écriture.', $refusal('line.0.account', SlipPlace::Both, '607000'));
        $split = new AnswerKey($key->reading, $this->proposal([new GridLine('601000', '', '', 200_000), new GridLine('624100', '', '', 15_500), new GridLine('445660', '', '', 43_100), new GridLine('401SCIE', '', '', 0, 258_600)]));
        self::assertSame('L’écriture proposée ne dépend pas de cette valeur : l’erreur ne peut être que dans les données lues.', SlippedErrors::refusal($split, 'totalExcludingTax', SlipPlace::Both, '251500', $accounts, $this->parties(...)));
        self::assertSame('La valeur fausse doit différer de la valeur juste.', $refusal('totalVat', SlipPlace::Reading, '43100'));
        self::assertSame('La valeur fausse doit différer de la valeur juste.', $refusal('line.2.account', SlipPlace::Proposal, 'F0001'), 'The same third party by its code.');
        self::assertSame('Indiquez un montant supérieur à zéro.', $refusal('totalVat', SlipPlace::Reading, '0'));
        self::assertSame('Indiquez un taux de TVA, par exemple 10 ou 5,5.', $this->freshRefusal('vat.0.rate', '5,5'));
        self::assertSame('Choisissez un compte du plan du dossier.', $refusal('line.0.account', SlipPlace::Proposal, '609999'));
        self::assertSame('Choisissez un tiers du dossier tenu sur un compte.', $refusal('thirdParty', SlipPlace::Reading, 'F0404'));
    }

    private function freshRefusal(string $target, string $wrong): ?string
    {
        return SlippedErrors::refusal($this->key(), $target, SlipPlace::Reading, $wrong, static fn (): bool => true, $this->parties(...));
    }

    private function key(SlippedError ...$errors): AnswerKey
    {
        return new AnswerKey(
            new PieceReading('purchase_invoice', 'F0001', 'FA-2603-0187', '2026-03-24', '2026-04-23', 'CF-0102', [new VatLine('20', Money::cents(215_500), Money::cents(43_100))], Money::cents(215_500), Money::cents(43_100), Money::cents(258_600)),
            $this->proposal([new GridLine('601000', '', '', 215_500), new GridLine('445660', '', '', 43_100), new GridLine('401SCIE', '', '', 0, 258_600, '2026-04-23')]),
            array_values($errors),
        );
    }

    /** @param list<GridLine> $lines */
    private function proposal(array $lines): PieceProposal
    {
        return new PieceProposal('ACH', '2026-03-24', 'FA-2603-0187', 'Scierie du Haut-Forez FA-2603-0187', $lines, 'basis');
    }

    private function parties(string $code): ?PieceParty
    {
        return match ($code) {
            'F0001' => new PieceParty(ThirdPartyKind::Supplier, '401SCIE', '', 'Scierie du Haut-Forez'),
            'F0002' => new PieceParty(ThirdPartyKind::Supplier, '401BOIS', '', 'Bois du Forez'),
            default => null,
        };
    }

    /** @return list<array{int, int}> */
    private function amounts(PieceProposal $proposal): array
    {
        return array_map(static fn (GridLine $line): array => [$line->debit, $line->credit], $proposal->lines);
    }
}
