<?php

declare(strict_types=1);

namespace App\Service\Shared;

use Symfony\Component\Form\FormInterface;

/**
 * Reads a submitted field of a Symfony form as the type the caller needs (taken from Formatool,
 * same author and licence; without float(): an amount is read with money()).
 *
 * FormInterface::getData() answers mixed by design: a text field holds a string once submitted, but
 * nothing in the type system says so, and a form read before submission - or built around a
 * different data class - holds something else entirely.
 *
 * Static because there is nothing to inject and nothing to remember - same shape as
 * JsonRequestPayload's own entry points, which solve the same problem one boundary over.
 */
final class FormValue
{
    /** Deliberately does not trim: a rich-text body must keep exactly what the editor sent. */
    public static function string(FormInterface $form, string $field, string $default = ''): string
    {
        $value = $form->get($field)->getData();

        return \is_scalar($value) ? (string) $value : $default;
    }

    public static function trimmed(FormInterface $form, string $field, string $default = ''): string
    {
        $value = $form->get($field)->getData();

        return \is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function int(FormInterface $form, string $field, int $default = 0): int
    {
        $value = $form->get($field)->getData();

        return is_numeric($value) ? (int) $value : $default;
    }

    /** Null for a field left blank - where blank means « not given » rather than zero. */
    public static function nullableInt(FormInterface $form, string $field): ?int
    {
        $value = $form->get($field)->getData();

        return is_numeric($value) ? (int) $value : null;
    }

    /** A Money held by the field (a MoneyType-like field), or null for anything else. */
    public static function money(FormInterface $form, string $field): ?Money
    {
        $value = $form->get($field)->getData();

        return $value instanceof Money ? $value : null;
    }

    public static function bool(FormInterface $form, string $field): bool
    {
        return true === $form->get($field)->getData();
    }
}
