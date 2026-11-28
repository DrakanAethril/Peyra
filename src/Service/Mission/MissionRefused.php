<?php

declare(strict_types=1);

namespace App\Service\Mission;

/** A mission's gesture refused (step 2.9a): every fault under its field, nothing written. */
final class MissionRefused extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, string> $errors */
    public static function of(array $errors): self
    {
        return new self(array_values($errors)[0] ?? 'La mission n’est pas enregistrée.', $errors);
    }
}
