<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

/**
 * One control of an entry before the brouillard (design-ux.md § 6.1): a rule of the trade said in a
 * sentence, under a key of EntryDraft (`date`, `document`, `line.<i>.account`). Blocking at levels
 * G and B, a warning at level A.
 */
final readonly class EntryControl
{
    public function __construct(
        public string $key,
        public string $message,
        public bool $blocking,
    ) {
    }

    /** @return array{key: string, message: string, blocking: bool} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'message' => $this->message, 'blocking' => $this->blocking];
    }
}
