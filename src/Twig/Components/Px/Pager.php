<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The pager at the foot of a list card (design-ui.md § 4.7): « 1 à 50 sur 431 » on the left, the
 * pages on the right. Each page is a GET link to the current route with the current query, only
 * `page` changed, so a filtered list stays filtered.
 */
#[AsTwigComponent('Px:Pager')]
final class Pager
{
    public int $page = 1;
    public int $perPage = 50;
    public int $total = 0;
    public string $parameter = 'page';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function mount(int $total, int $perPage = 50, int $page = 1): void
    {
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Px:Pager needs a positive page size.');
        }
        $this->total = max(0, $total);
        $this->perPage = $perPage;
        $this->page = max(1, min($page, $this->pageCount()));
    }

    public function pageCount(): int
    {
        return max(1, intdiv($this->total + $this->perPage - 1, $this->perPage));
    }

    public function first(): int
    {
        return 0 === $this->total ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function last(): int
    {
        return min($this->page * $this->perPage, $this->total);
    }

    /** @return list<int|null> */
    public function window(): array
    {
        return self::windowOf($this->page, $this->pageCount());
    }

    /**
     * The page numbers to show, null for a gap: every page up to seven, beyond that the first,
     * the last, and the current one with its neighbours (« 1 … 4 5 6 … 12 »). A gap that would
     * hide a single page shows that page instead.
     *
     * @return list<int|null>
     */
    public static function windowOf(int $page, int $pages): array
    {
        if ($pages <= 7) {
            return range(1, max(1, $pages));
        }
        $kept = array_values(array_unique(array_filter(
            [1, $page - 1, $page, $page + 1, $pages],
            static fn (int $n): bool => $n >= 1 && $n <= $pages,
        )));
        sort($kept);

        $window = [];
        $previous = null;
        foreach ($kept as $n) {
            if (null !== $previous && 2 === $n - $previous) {
                $window[] = $previous + 1;
            } elseif (null !== $previous && $n - $previous > 2) {
                $window[] = null;
            }
            $window[] = $n;
            $previous = $n;
        }

        return $window;
    }

    public function url(int $page): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return '?'.http_build_query([$this->parameter => $page]);
        }
        $route = $request->attributes->get('_route');
        $routeParams = $request->attributes->get('_route_params');
        $query = array_merge($request->query->all(), [$this->parameter => $page]);
        if (!\is_string($route) || !\is_array($routeParams)) {
            return '?'.http_build_query($query);
        }

        return $this->urls->generate($route, array_merge($routeParams, $query));
    }
}
