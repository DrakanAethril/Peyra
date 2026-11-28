<?php

declare(strict_types=1);

namespace App\Service\Dossier\Search;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The screens a person opened last in a dossier (design-ux.md § 2.1: Ctrl K on an empty field
 * shows the five recent ones), kept in their session by catalogue key (SearchScreens), the most
 * recent first, without repeats. Nothing is written in the database: a new session starts empty.
 */
final readonly class RecentScreens
{
    public const int KEPT = 5;

    private const string SESSION_KEY = 'px_recent_screens';

    public function __construct(private RequestStack $requests)
    {
    }

    public function record(int $dossierId, string $key): void
    {
        $session = $this->requests->getCurrentRequest()?->hasSession() ? $this->requests->getSession() : null;
        if (null === $session) {
            return;
        }
        $all = $this->all();
        $keys = array_values(array_filter($all[$dossierId] ?? [], static fn (string $kept): bool => $kept !== $key));
        array_unshift($keys, $key);
        $all[$dossierId] = \array_slice($keys, 0, self::KEPT);
        $session->set(self::SESSION_KEY, $all);
    }

    /** @return list<string> the keys, the most recent first */
    public function keys(int $dossierId): array
    {
        return $this->all()[$dossierId] ?? [];
    }

    /** @return array<int, list<string>> */
    private function all(): array
    {
        $request = $this->requests->getCurrentRequest();
        $stored = null !== $request && $request->hasSession() ? $request->getSession()->get(self::SESSION_KEY) : null;
        if (!\is_array($stored)) {
            return [];
        }
        $all = [];
        foreach ($stored as $dossierId => $keys) {
            if (\is_int($dossierId) && \is_array($keys)) {
                $all[$dossierId] = array_values(array_filter($keys, \is_string(...)));
            }
        }

        return $all;
    }
}
