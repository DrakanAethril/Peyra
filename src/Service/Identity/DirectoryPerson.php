<?php

declare(strict_types=1);

namespace App\Service\Identity;

/**
 * A person as the directory describes them, read with the service account. Carries no password:
 * Directory::checkPassword() binds as the person and answers yes or no.
 */
final readonly class DirectoryPerson
{
    /** « Nom Prénom » (`sn givenName`), the name lists sort on; the display name without a surname. */
    public string $sortName;

    /** @param list<string> $groups the `cn` of every group naming the person as `member` */
    public function __construct(
        public string $dn,
        public string $login,
        public string $stableId,
        public string $displayName,
        public ?string $email,
        public array $groups,
        ?string $sortName = null,
    ) {
        $this->sortName = $sortName ?? $displayName;
    }
}
