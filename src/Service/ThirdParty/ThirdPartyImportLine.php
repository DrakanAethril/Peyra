<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

/** One line of an imported file of third parties, as ThirdPartyImport reads it and what it will do with it. */
final readonly class ThirdPartyImportLine
{
    public const string CREATE = 'create';
    public const string UPDATE = 'update';
    public const string SAME = 'same';
    public const string REFUSED = 'refused';

    public function __construct(
        public int $line,
        public string $code,
        public string $name,
        public string $action,
        public ?string $message = null,
        public ?ThirdPartyDraft $draft = null,
        public ?ThirdPartyFields $fields = null,
    ) {
    }
}
