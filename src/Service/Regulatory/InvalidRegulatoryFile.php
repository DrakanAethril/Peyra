<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

/** A millésime file that cannot be installed; nothing of it is: its problems, in French. */
final class InvalidRegulatoryFile extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly string $fileName, public readonly array $problems)
    {
        parent::__construct(\sprintf("%s ne peut pas être installé :\n- %s", $fileName, implode("\n- ", $problems)));
    }
}
