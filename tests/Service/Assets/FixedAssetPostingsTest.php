<?php

declare(strict_types=1);

namespace App\Tests\Service\Assets;

use App\Entity\Assets\FixedAsset;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\EntryState;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Assets\FixedAssetRepository;
use App\Service\Assets\Dotation\DotationLine;
use App\Service\Assets\FixedAssetPostings;
use App\Service\Assets\FixedAssetSheet;
use App\Service\Assets\Statement\FixedAssetStatements;
use App\Service\Dossier\Demo\DemoInstaller;
use App\Service\Dossier\DossierContext;
use App\Service\Regulatory\RegulatorySync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The demonstration's fixed assets through the year 2026 (step 3.4b; donnees/demo/immobilisations.yaml):
 * the dossier moved to 30/06/2026 sells the van I07, then to 31/12/2026 takes its dotations, and the state
 * of fixed assets reads the year. Every expected amount is worked out in its comment from the prepared
 * data, never read from the code.
 */
final class FixedAssetPostingsTest extends KernelTestCase
{
    private Dossier $dossier;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
        $installer = self::getContainer()->get(DemoInstaller::class);
        $installer->installTemplate('test', new \DateTimeImmutable('2026-11-28 09:00'));
        $this->dossier = $installer->findTemplate() ?? throw new \LogicException('The demonstration is installed.');
    }

    public function testTheVanIsSoldOnThe30thOfJune(): void
    {
        $this->within(function (): void {
            $postings = self::getContainer()->get(FixedAssetPostings::class);
            $van = $this->asset('I07');
            self::assertNull($postings->disposalProposal($this->dossier, $van), 'Au 31/03, la cession du 30/06 ne se propose pas encore.');

            $this->dossier->moveDate(new \DateTimeImmutable('2026-06-30'), new \DateTimeImmutable('2026-11-28 09:05'));
            // 16 841,67 at 31/12/2025; 21 500,00 × 180 / 1 800 = 2 150,00 up to 30/06; 21 500,00 − 18 991,67 = 2 508,33;
            // 7 500,00 − 2 508,33 = 4 991,67; 7 500,00 + 1 500,00 = 9 000,00 owed by D0001.
            $calculation = $postings->disposalCalculation($this->dossier, $van);
            self::assertNotNull($calculation);
            self::assertSame([1_684_167, 215_000, 1_899_167, 250_833, 499_167, 900_000, 0, 0], [
                $calculation->before->cents, $calculation->complement->cents, $calculation->depreciation()->cents,
                $calculation->netBookValue()->cents, $calculation->result()->cents, $calculation->receivable()->cents,
                $calculation->derogatory->cents, $calculation->vatRegularisation->cents,
            ]);
            $proposal = $postings->disposalProposal($this->dossier, $van);
            self::assertNotNull($proposal);
            self::assertSame(['OD', '2026-06-30', 'CES-I07'], [$proposal->journal, $proposal->date, $proposal->document]);
            self::assertSame(
                [['681120', '', 215_000, 0], ['281820', '', 0, 215_000], ['281820', '', 1_899_167, 0], ['657000', '', 250_833, 0], ['218200', '', 0, 2_150_000], ['462000', 'D0001', 900_000, 0], ['757000', '', 0, 750_000], ['445710', '', 0, 150_000]],
                array_map(static fn ($line): array => [$line->account, $line->thirdParty, $line->debit, $line->credit], $proposal->lines),
            );

            $check = $postings->postDisposal($this->dossier, $van, 'test');
            self::assertTrue($check->posted, implode(' ', $check->errors));
            $this->entityManager()->flush();
            $entries = $postings->standing($this->dossier, 'CES-I07');
            self::assertCount(1, $entries);
            self::assertSame(EntryState::Draft, $entries[0]->getState());
            self::assertFalse(self::getContainer()->get(FixedAssetSheet::class)->isDeletable($van), 'Une fiche qui porte une écriture ne se supprime plus.');
            self::assertTrue(self::getContainer()->get(FixedAssetSheet::class)->isDeletable($this->asset('I14')));

            // At 31/12/2026, the year's dotations leave the van to its way out: 29 002,88 − 2 150,00 = 26 852,88,
            // and the planer's dérogatoire is taken back (592,84).
            $this->dossier->moveDate(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable('2026-11-28 09:10'));
            $exercise = $postings->dotationExercise($this->dossier) ?? throw new \LogicException('2026');
            $lines = $postings->dotationLines($this->dossier, $exercise, new \DateTimeImmutable('2026-12-31'));
            $byCode = array_combine(array_map(static fn (DotationLine $line): string => $line->code, $lines), $lines);
            self::assertArrayNotHasKey('I07', $byCode);
            self::assertSame(2_685_288, array_sum(array_map(static fn (DotationLine $line): int => $line->dotation->cents, $lines)));
            self::assertSame(8_542, $byCode['I14']->dotation->cents);
            self::assertSame(-59_284, $byCode['I05']->derogatory?->cents);

            $check = $postings->postDotations($this->dossier, 'test');
            self::assertTrue($check->posted, implode(' ', $check->errors));
            $this->entityManager()->flush();
            self::assertSame([], $postings->dotationLines($this->dossier, $exercise, new \DateTimeImmutable('2026-12-31')), 'Tout est passé jusqu’au 31/12.');
            $entry = $postings->standing($this->dossier, 'DOT-2026-12-31')[0];
            // 681110: I02 377,77; 681120: the rest, 26 852,88 − 377,77 = 26 475,11; 787250: 592,84.
            $amounts = [];
            foreach ($entry->getLines() as $line) {
                $amounts[$line->getAccount()->getNumber()] = ($amounts[$line->getAccount()->getNumber()] ?? 0) + $line->getDebit()->cents - $line->getCredit()->cents;
            }
            self::assertSame(37_777, $amounts['681110']);
            self::assertSame(2_647_511, $amounts['681120']);
            self::assertSame(59_284, $amounts['145000']);
            self::assertSame(-59_284, $amounts['787250']);

            // The state of 2026: 225 200,00 at the opening, I13 (86 500,00) and I14 (6 000,00) in, I07 (21 500,00)
            // out: 296 200,00; depreciation 117 034,42 + 29 002,88 − 18 991,67 = 127 045,63.
            $statement = self::getContainer()->get(FixedAssetStatements::class)->at($this->dossier) ?? throw new \LogicException('2026');
            $total = $statement->total;
            self::assertSame([22_520_000, 9_250_000, 2_150_000, 29_620_000], [$total->grossOpening->cents, $total->grossIncrease->cents, $total->grossDecrease->cents, $total->grossClosing()->cents]);
            self::assertSame([11_703_442, 2_900_288, 1_899_167, 12_704_563], [$total->depreciationOpening->cents, $total->dotations->cents, $total->depreciationDecrease->cents, $total->depreciationClosing()->cents]);
            self::assertSame(['intangible', 'tangible', 'financial'], array_map(static fn ($group): string => $group->key, $statement->groups));
        });
    }

    private function asset(string $code): FixedAsset
    {
        return self::getContainer()->get(FixedAssetRepository::class)->findOneByCode($this->dossier, $code) ?? throw new \LogicException($code);
    }

    private function within(callable $test): void
    {
        self::getContainer()->get(DossierContext::class)->within($this->dossier, $test);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
