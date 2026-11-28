<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Enum\Dossier\DistributionCopyState;

/**
 * How far a distribution stands (design-ux.md § 4.2, step 3): its dossiers counted by state, and
 * the line that says it - « Distribution en cours · 9 sur 24 ».
 */
final readonly class DistributionProgress
{
    private function __construct(
        public int $total,
        public int $ready,
        public int $failed,
        public int $pending,
    ) {
    }

    /** @param array<string, int> $counts by DistributionCopyState value */
    public static function of(array $counts): self
    {
        $ready = $counts[DistributionCopyState::Ready->value] ?? 0;
        $failed = $counts[DistributionCopyState::Failed->value] ?? 0;
        $pending = ($counts[DistributionCopyState::Waiting->value] ?? 0) + ($counts[DistributionCopyState::Copying->value] ?? 0);

        return new self($ready + $failed + $pending, $ready, $failed, $pending);
    }

    public function isRunning(): bool
    {
        return $this->pending > 0;
    }

    /** The share made, ready or failed, in percent (the progress bar). */
    public function percent(): int
    {
        return 0 === $this->total ? 100 : intdiv(100 * ($this->ready + $this->failed), $this->total);
    }

    public function sentence(): string
    {
        $failed = $this->failed > 0 ? \sprintf(' · %d en échec', $this->failed) : '';
        if ($this->isRunning()) {
            return \sprintf('Distribution en cours · %d sur %d', $this->ready, $this->total).$failed;
        }

        return \sprintf('Distribution terminée · %d %s sur %d', $this->ready, $this->ready > 1 ? 'dossiers prêts' : 'dossier prêt', $this->total).$failed;
    }
}
