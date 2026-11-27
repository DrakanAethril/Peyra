<?php

declare(strict_types=1);

namespace App\Service\Identity;

/**
 * What becomes of one student in a « passage de classe » (design-ux.md § 4.12): the next class
 * (the box is ticked), another class of the new year (a student who repeats), no class (« Ne pas
 * placer », they wait in « À placer »), or out of the field. A student already enrolled in the new
 * year is left as is.
 */
final readonly class PromotionChoice
{
    public const string NEXT = 'next';
    public const string NONE = 'none';
    public const string DEPARTED = 'departed';

    private function __construct(public string $kind, public ?int $classId)
    {
    }

    public static function next(): self
    {
        return new self(self::NEXT, null);
    }

    public static function none(): self
    {
        return new self(self::NONE, null);
    }

    public static function departed(): self
    {
        return new self(self::DEPARTED, null);
    }

    public static function otherClass(int $classId): self
    {
        return new self('class', $classId);
    }

    /**
     * The choice a row of the form submits: ticked → the next class; else its select, a class id,
     * « departed » or anything else, which means « Ne pas placer ».
     */
    public static function fromForm(bool $ticked, string $other): self
    {
        if ($ticked) {
            return self::next();
        }
        if (self::DEPARTED === $other) {
            return self::departed();
        }
        if (ctype_digit($other) && (int) $other > 0) {
            return self::otherClass((int) $other);
        }

        return self::none();
    }

    /** The value the select carries back, so that a summary returns to the same choices. */
    public function formValue(): string
    {
        return null === $this->classId ? $this->kind : (string) $this->classId;
    }
}
