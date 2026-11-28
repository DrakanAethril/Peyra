<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Referential;

use App\Enum\Shared\BtsProcess;
use App\Service\Shared\Referential\InvalidReferentialFile;
use App\Service\Shared\Referential\Referential;
use App\Service\Shared\Referential\ReferentialActivity;
use PHPUnit\Framework\TestCase;

/**
 * The référentiel of the BTS CG as shipped (config/referential/bts-cg.yaml): seven processes,
 * 37 activities (P1 7, P2 8, P3 4, P4 4, P5 5, P6 6, P7 3), 115 components, four exams, and the
 * règlement d'examen's exclusions - figures counted in the published text, not read back from the code.
 */
final class ReferentialTest extends TestCase
{
    private static function referential(): Referential
    {
        return new Referential(\dirname(__DIR__, 4).'/config/referential/bts-cg.yaml');
    }

    public function testTheTextCountsSevenProcessesAnd37ActivitiesAnd115Components(): void
    {
        $referential = self::referential();

        self::assertSame('2014', $referential->edition());
        $perProcess = [];
        $components = 0;
        foreach ($referential->processes() as $process) {
            $perProcess[$process->process->value] = \count($process->activities);
            foreach ($process->activities as $activity) {
                $components += \count($activity->components);
            }
        }
        self::assertSame(['P1' => 7, 'P2' => 8, 'P3' => 4, 'P4' => 4, 'P5' => 5, 'P6' => 6, 'P7' => 3], $perProcess);
        self::assertCount(37, $referential->activities());
        self::assertSame(115, $components);
    }

    public function testEveryProcessCarriesTheWordingOfBtsProcess(): void
    {
        foreach (self::referential()->processes() as $process) {
            self::assertSame($process->process->label(), $process->title, $process->process->value);
            self::assertTrue($process->verified);
        }
    }

    public function testTheWordingsAreTheOfficialOnes(): void
    {
        $referential = self::referential();

        self::assertSame('Réalisation des rapprochements bancaires (comptabilité de trésorerie)', $referential->activity('A1.6')?->title);
        self::assertSame('Réalisation des travaux comptables relatifs à la constitution de l’entreprise et l’évolution du capital', $referential->activity('A2.2')?->title);
        self::assertSame('Production d’informations nécessaires à la consolidation', $referential->activity('A2.7')?->title);
        self::assertSame('Analyse de la rentabilité d’un investissement', $referential->activity('A6.2')?->title);
        self::assertSame('Contribuer à la qualité du système d’information', $referential->activity('A7.3')?->title);
        self::assertSame(BtsProcess::P4, $referential->activity('A4.3')?->process);
        self::assertSame('A1.5.4', $referential->activity('A1.5')?->components[3]->code);
        self::assertNull($referential->activity('A1.8'));
        foreach ($referential->activities() as $activity) {
            self::assertTrue($activity->verified, $activity->code);
        }
    }

    public function testTheExamsFollowTheReglementDExamen(): void
    {
        $referential = self::referential();

        self::assertSame(['E41', 'E42', 'E5', 'E6'], array_map(static fn ($exam): string => $exam->code, $referential->exams()));
        self::assertSame(9, $referential->exam('E41')?->coefficient);
        self::assertSame(4, $referential->exam('E42')?->coefficient);
        self::assertSame(5, $referential->exam('E5')?->coefficient);
        self::assertSame(5, $referential->exam('E6')?->coefficient);
        self::assertTrue($referential->exam('E42')->onWorkstation ?? false);
        self::assertFalse($referential->exam('E41')->onWorkstation ?? true);

        $byExam = static fn (string $code): array => array_values(array_map(
            static fn (ReferentialActivity $activity): string => $activity->code,
            array_filter($referential->activities(), static fn (ReferentialActivity $activity): bool => \in_array($code, $activity->exams, true)),
        ));
        self::assertSame(['A1.1', 'A1.7', 'A2.1', 'A2.8', 'A3.1', 'A4.1', 'A4.4'], $byExam('E6'));
        self::assertSame(['A5.1', 'A5.2', 'A5.3', 'A5.4', 'A5.5', 'A6.1', 'A6.2', 'A6.3', 'A6.4', 'A6.5', 'A6.6', 'A7.1', 'A7.2', 'A7.3'], $byExam('E5'));
        // P1 5, P2 6, P3 3, P4 2 once the seven E6 activities are out, and the three of P7.
        self::assertCount(19, $byExam('E41'));
        self::assertSame($byExam('E41'), $byExam('E42'));

        // Two components of A3.2 and A3.3 leave their activity for E6; the others keep its exams.
        $vat = $referential->activity('A3.2');
        self::assertNotNull($vat);
        self::assertSame(['E6'], $vat->components[4]->exams);
        self::assertSame(['E41', 'E42'], $vat->components[0]->exams);
        self::assertSame(['E6'], $referential->activity('A3.3')?->components[7]->exams);
    }

    public function testAFileThatSkipsACodeServesNothing(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 4).'/config/referential/bts-cg.yaml');
        $file = tempnam(sys_get_temp_dir(), 'referential');
        self::assertIsString($file);
        try {
            file_put_contents($file, str_replace('- code: A1.3', '- code: A1.9', $source));

            $this->expectException(InvalidReferentialFile::class);
            $this->expectExceptionMessage('A1.3 : attendu à cette place');
            (new Referential($file))->activities();
        } finally {
            unlink($file);
        }
    }

    public function testAnUnknownExamIsRefused(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 4).'/config/referential/bts-cg.yaml');
        $file = tempnam(sys_get_temp_dir(), 'referential');
        self::assertIsString($file);
        try {
            file_put_contents($file, preg_replace('/exams: \[E5\]/', 'exams: [E7]', $source, 1));

            $this->expectException(InvalidReferentialFile::class);
            $this->expectExceptionMessage('épreuve inconnue (E7)');
            (new Referential($file))->processes();
        } finally {
            unlink($file);
        }
    }
}
