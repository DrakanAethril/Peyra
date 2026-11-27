<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Chart;

use App\Service\Accounting\Chart\ReferenceAccount;
use App\Service\Accounting\Chart\ReferenceCharts;
use PHPUnit\Framework\TestCase;

/**
 * The reference charts shipped in fixtures/pcg/ (lot 1, « plan millésimé »): which one an exercise
 * receives, what the ANC's regulation 2024-07 changed for 2026, and how a number finds the account
 * of the plan it subdivides.
 */
final class ReferenceChartsTest extends TestCase
{
    private static function charts(): ReferenceCharts
    {
        return new ReferenceCharts(\dirname(__DIR__, 4).'/fixtures/pcg');
    }

    public function testTheChartOfAnExerciseIsTheLatestInForceAtItsStart(): void
    {
        $charts = self::charts();

        self::assertSame(['pcg-2026', 'pcg-2025', 'pcg-anterieur'], array_map(static fn ($c): string => $c->code, $charts->all()));
        self::assertSame('pcg-2026', $charts->forExerciseStartingOn(new \DateTimeImmutable('2026-01-01'))->code);
        self::assertSame('pcg-2026', $charts->forExerciseStartingOn(new \DateTimeImmutable('2027-04-01'))->code);
        self::assertSame('pcg-2025', $charts->forExerciseStartingOn(new \DateTimeImmutable('2025-07-01'))->code);
        self::assertSame('pcg-2025', $charts->forExerciseStartingOn(new \DateTimeImmutable('2025-12-31'))->code);
        self::assertSame('pcg-anterieur', $charts->forExerciseStartingOn(new \DateTimeImmutable('2024-10-01'))->code);
        foreach ($charts->all() as $chart) {
            self::assertTrue($chart->verified, $chart->code);
            self::assertStringStartsWith('https://www.anc.gouv.fr/', $chart->source, $chart->code);
        }
    }

    public function testThe2026ChartCarriesTheRegulation2024Of07(): void
    {
        $charts = self::charts();
        $chart2025 = $charts->get('pcg-2025');
        $chart2026 = $charts->get('pcg-2026');
        self::assertNotNull($chart2025);
        self::assertNotNull($chart2026);

        self::assertCount(853, $chart2025->accounts);
        self::assertCount(853, $chart2026->accounts, '4 comptes supprimés, 4 créés.');
        foreach (['1675', '16718', '16748', '16758'] as $removed) {
            self::assertNotNull($chart2025->find($removed));
            self::assertNull($chart2026->find($removed), $removed);
        }
        self::assertSame('Emprunts participatifs', $chart2026->find('1682')?->label);
        self::assertSame('Avances conditionnées montant principal', $chart2026->find('1673')?->label);
        self::assertSame('Bons de souscription de titres en capital', $chart2026->find('1045')?->label);
        self::assertSame('Bons de souscription d\'actions', $chart2025->find('1045')?->label);
        self::assertSame('Fonds non remboursables et avances conditionnées', $chart2026->find('167')?->label);
        self::assertCount(1012, $charts->get('pcg-anterieur')->accounts ?? []);
    }

    public function testANumberFindsTheAccountItSubdivides(): void
    {
        $chart = self::charts()->get('pcg-2026');
        self::assertNotNull($chart);

        self::assertSame('601', $chart->rootOf('601300')?->number);
        self::assertSame('44566', $chart->rootOf('445660')?->number);
        self::assertSame('5121', $chart->rootOf('512100')?->number);
        self::assertSame('58', $chart->rootOf('580000')?->number, 'Un compte à deux chiffres se subdivise aussi.');
        self::assertSame('6061', $chart->rootOf('60611000')?->number);
        self::assertNull($chart->rootOf('190000'), 'Aucun compte 19 au plan.');
        self::assertSame('Achats (sauf 603)', $chart->groupOf('601300')?->label);
        self::assertSame('Comptes de charges', $chart->classLabel(6));
    }

    public function testADossierReceivesTheAccountsOfThreeDigitsAndMorePaddedToSix(): void
    {
        $chart = self::charts()->get('pcg-2026');
        self::assertNotNull($chart);
        $copied = $chart->copiedAccounts();

        self::assertNotContains('60', array_map(static fn (ReferenceAccount $a): string => $a->number, $copied));
        $numbers = array_map(static fn (ReferenceAccount $a): string => $a->dossierNumber(), $copied);
        self::assertContains('601000', $numbers);
        self::assertContains('445660', $numbers);
        self::assertCount(\count($numbers), array_unique($numbers), 'Deux comptes ne tombent jamais sur le même numéro.');
        foreach ($numbers as $number) {
            self::assertSame(6, \strlen($number));
        }
    }

    public function testABrokenFileNamesItsLineAndServesNothing(): void
    {
        $directory = sys_get_temp_dir().'/peyra-pcg-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/charts.yaml', "classes: {'1': Capitaux}\ncharts:\n  - {code: x, label: X, regulation: R, applies_from: ~, file: x.csv, source: s, consulted: '2026-11-27', verified: true, note: n}\n");
        file_put_contents($directory.'/x.csv', "numero;intitule;classe;niveau;verifie\n10;Capital;1;2;oui\n6A1;Achats;6;3;oui\n");

        try {
            new ReferenceCharts($directory)->all();
            self::fail('Le fichier fautif est refusé.');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('x.csv, ligne 3', $refused->getMessage());
        } finally {
            array_map(unlink(...), glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }
}
