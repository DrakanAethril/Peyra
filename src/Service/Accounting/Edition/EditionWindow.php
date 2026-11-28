<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Dossier\Exercise;
use App\Service\Accounting\Consultation\ConsultationWindow;
use App\Service\Shared\DateValue;

/**
 * The days an edition covers (lot 1, step 1.5b): from `from` to `to` inclusive, **inside one
 * exercise** - an exercise opens on its own à-nouveaux, so a window across two would count the
 * same balances twice. Left empty, the window is the exercise of the dossier's date, from its
 * first day up to that date (never the computer's). A bound that is not a day, a window that ends
 * before it starts, a day no exercise holds or a window across two exercises is said in a sentence
 * and the defaults are read instead. No exercise at all: `exercise` is null and there is nothing to
 * edit.
 */
final readonly class EditionWindow
{
    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?Exercise $exercise,
        public ?string $error,
    ) {
    }

    /**
     * @param string          $fromIso   « 2026-01-01 » as the date field's hidden input carries it, or empty
     * @param list<Exercise>  $exercises the dossier's exercises
     */
    public static function read(string $fromIso, string $toIso, array $exercises, \DateTimeImmutable $dossierDate): self
    {
        $home = self::holding($exercises, $dossierDate);
        $defaultFrom = $home?->getStartsOn() ?? $dossierDate;
        $read = ConsultationWindow::read($fromIso, $toIso, $defaultFrom, $dossierDate);
        $default = new self($defaultFrom, $dossierDate, $home, $read->error);
        if (null !== $read->error) {
            return $default;
        }

        $exercise = self::holding($exercises, $read->from);
        if (null === $exercise) {
            return new self($defaultFrom, $dossierDate, $home, null === $home ? null : \sprintf('Aucun exercice du dossier ne contient le %s.', $read->from->format('d/m/Y')));
        }
        if (!$exercise->contains($read->to)) {
            return new self($defaultFrom, $dossierDate, $home, \sprintf(
                'Une édition tient dans un exercice : l’exercice %s va du %s au %s.',
                $exercise->getCode(),
                $exercise->getStartsOn()->format('d/m/Y'),
                $exercise->getEndsOn()->format('d/m/Y'),
            ));
        }

        return new self($read->from, $read->to, $exercise, null);
    }

    /**
     * The window of a state read at one day (the aged balance): from the first day of that day's
     * exercise up to it. Left empty, the day is the dossier's date.
     *
     * @param list<Exercise> $exercises the dossier's exercises
     */
    public static function upTo(string $atIso, array $exercises, \DateTimeImmutable $dossierDate): self
    {
        $home = self::holding($exercises, $dossierDate);
        $default = new self($home?->getStartsOn() ?? $dossierDate, $dossierDate, $home, null);
        if ('' === trim($atIso)) {
            return $default;
        }
        $at = DateValue::parse(trim($atIso));
        if (null === $at) {
            return new self($default->from, $default->to, $home, 'La date s’écrit jj/mm/aaaa, par exemple 31/01/2026.');
        }
        $exercise = self::holding($exercises, $at);
        if (null === $exercise) {
            return new self($default->from, $default->to, $home, \sprintf('Aucun exercice du dossier ne contient le %s.', $at->format('d/m/Y')));
        }

        return new self($exercise->getStartsOn(), $at, $exercise, null);
    }

    /** Whether the exercise began before the window: the edition then opens on what each account carried the eve. */
    public function hasOpening(): bool
    {
        return null !== $this->exercise && $this->exercise->getStartsOn() < $this->from;
    }

    /** The first day of the exercise, where what is carried before the window starts to be read. */
    public function exerciseStart(): \DateTimeImmutable
    {
        return $this->exercise?->getStartsOn() ?? $this->from;
    }

    /** The eve of the window, the day the opening balances are read at. */
    public function eve(): \DateTimeImmutable
    {
        return $this->from->modify('-1 day');
    }

    /** Whether the window is the screen's default one (no « Réinitialiser » to offer). */
    public function isDefault(\DateTimeImmutable $dossierDate): bool
    {
        return $this->to == $dossierDate && $this->from == ($this->exercise?->getStartsOn() ?? $dossierDate);
    }

    /** @param list<Exercise> $exercises */
    private static function holding(array $exercises, \DateTimeImmutable $day): ?Exercise
    {
        foreach ($exercises as $exercise) {
            if ($exercise->contains($day)) {
                return $exercise;
            }
        }

        return null;
    }
}
