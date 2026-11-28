<?php

declare(strict_types=1);

namespace App\Enum\Banking;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where a bank statement's reconciliation stands (design-ux.md § 3.2, « Relevés »): nothing pointed
 * yet, pointing under way, validated. Only « validated » is stored (BankStatement::$reconciledOn);
 * the other two are read from its pointings.
 */
enum BankStatementStatus: string implements HasBadge
{
    case ToReconcile = 'to_reconcile';
    case InProgress = 'in_progress';
    case Reconciled = 'reconciled';

    public function label(): string
    {
        return match ($this) {
            self::ToReconcile => 'À rapprocher',
            self::InProgress => 'En cours',
            self::Reconciled => 'Validé',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::ToReconcile => BadgeTone::Warning,
            self::InProgress => BadgeTone::Info,
            self::Reconciled => BadgeTone::Success,
        };
    }
}
