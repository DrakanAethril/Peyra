<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Enum\Audit\AuditAction;
use App\Enum\Audit\AuditActorRole;

/**
 * The filters of a dossier's trail as the screen reads them: who acted (a teacher, a student), what
 * (one gesture), and words of the line. A value that names nothing reads as no filter.
 */
final readonly class DossierTrailCriteria
{
    public function __construct(
        public ?AuditActorRole $role = null,
        public ?AuditAction $action = null,
        public string $text = '',
    ) {
    }

    /** @param array<string, string> $filters as typed (`role`, `action`, `q`) */
    public static function read(array $filters): self
    {
        return new self(
            AuditActorRole::tryFrom($filters['role'] ?? ''),
            AuditAction::tryFrom($filters['action'] ?? ''),
            mb_substr(trim($filters['q'] ?? ''), 0, 100),
        );
    }

    public function withRole(?AuditActorRole $role): self
    {
        return new self($role, $this->action, $this->text);
    }
}
