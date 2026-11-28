<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Enum\Dossier\DossierKind;
use App\Service\Accounting\Edition\EditionWindow;
use App\Tests\Support\DossierFixtures;
use PHPUnit\Framework\TestCase;

final class EditionWindowTest extends TestCase
{
    /** @var list<Exercise> */
    private array $exercises;

    private \DateTimeImmutable $dossierDate;

    protected function setUp(): void
    {
        $this->dossierDate = new \DateTimeImmutable('2026-03-15');
        $dossier = new Dossier(DossierKind::Template, DossierFixtures::company(), $this->dossierDate, null, null, new \DateTimeImmutable('2026-11-27 10:00'), null);
        $this->exercises = [
            new Exercise($dossier, '2026', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')),
            new Exercise($dossier, '2025', new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31')),
        ];
    }

    public function testTheDefaultIsTheExerciseUpToTheDossiersDate(): void
    {
        $window = EditionWindow::read('', '', $this->exercises, $this->dossierDate);

        self::assertSame(['2026-01-01', '2026-03-15'], [$window->from->format('Y-m-d'), $window->to->format('Y-m-d')]);
        self::assertSame('2026', $window->exercise?->getCode());
        self::assertNull($window->error);
        self::assertFalse($window->hasOpening());
        self::assertTrue($window->isDefault($this->dossierDate));
    }

    public function testAWindowAfterTheExercisesStartOpensOnTheEve(): void
    {
        $window = EditionWindow::read('2026-02-01', '2026-02-28', $this->exercises, $this->dossierDate);

        self::assertTrue($window->hasOpening());
        self::assertSame('2026-01-31', $window->eve()->format('Y-m-d'));
        self::assertSame('2026-01-01', $window->exerciseStart()->format('Y-m-d'));
        self::assertFalse($window->isDefault($this->dossierDate));
    }

    public function testAPastExerciseIsReadWhole(): void
    {
        $window = EditionWindow::read('2025-01-01', '2025-12-31', $this->exercises, $this->dossierDate);

        self::assertNull($window->error);
        self::assertSame('2025', $window->exercise?->getCode());
    }

    public function testAWindowAcrossTwoExercisesIsRefused(): void
    {
        $window = EditionWindow::read('2025-12-01', '2026-01-31', $this->exercises, $this->dossierDate);

        self::assertSame('Une édition tient dans un exercice : l’exercice 2025 va du 01/01/2025 au 31/12/2025.', $window->error);
        self::assertSame(['2026-01-01', '2026-03-15'], [$window->from->format('Y-m-d'), $window->to->format('Y-m-d')]);
    }

    public function testADayNoExerciseHoldsIsSaid(): void
    {
        self::assertSame('Aucun exercice du dossier ne contient le 01/06/2024.', EditionWindow::read('2024-06-01', '2024-06-30', $this->exercises, $this->dossierDate)->error);
        self::assertNotNull(EditionWindow::read('2026-04-01', '2026-02-01', $this->exercises, $this->dossierDate)->error, 'A window ends after it starts.');
        self::assertNotNull(EditionWindow::read('31/02/2026', '', $this->exercises, $this->dossierDate)->error);
    }

    public function testWithoutExerciseThereIsNothingToEdit(): void
    {
        $window = EditionWindow::read('', '', [], $this->dossierDate);

        self::assertNull($window->exercise);
        self::assertNull($window->error);
    }

    public function testAStateReadAtADayStartsWithItsExercise(): void
    {
        $default = EditionWindow::upTo('', $this->exercises, $this->dossierDate);
        self::assertSame(['2026-01-01', '2026-03-15'], [$default->from->format('Y-m-d'), $default->to->format('Y-m-d')]);

        $past = EditionWindow::upTo('2025-11-30', $this->exercises, $this->dossierDate);
        self::assertSame(['2025-01-01', '2025-11-30'], [$past->from->format('Y-m-d'), $past->to->format('Y-m-d')]);

        self::assertSame('Aucun exercice du dossier ne contient le 01/01/2027.', EditionWindow::upTo('2027-01-01', $this->exercises, $this->dossierDate)->error);
        self::assertNotNull(EditionWindow::upTo('demain', $this->exercises, $this->dossierDate)->error);
    }
}
