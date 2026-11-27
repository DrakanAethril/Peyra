<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity;

use App\Service\Identity\PlacementResult;
use PHPUnit\Framework\TestCase;

/** The status bar's sentence after a placement (design-ux.md § 4.1, step 5). */
final class PlacementResultTest extends TestCase
{
    public function testSaysWhatHappened(): void
    {
        self::assertSame('3 étudiants placés en BTS CG 1.', new PlacementResult(3, 0, 0)->sentence('BTS CG 1'));
        self::assertSame('1 étudiant placé en BTS CG 1.', new PlacementResult(1, 0, 0)->sentence('BTS CG 1'));
        self::assertSame('1 étudiant placé en BTS CG 2 ; 2 étudiants changés de classe en BTS CG 2 ; 1 déjà dans la classe.', new PlacementResult(1, 2, 1)->sentence('BTS CG 2'));
        self::assertSame('Aucun étudiant placé.', new PlacementResult(0, 0, 0)->sentence('BTS CG 1'));
    }
}
