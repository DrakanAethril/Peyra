<?php

declare(strict_types=1);

namespace App\Twig\Components\Px;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A sortable column head (design-ui.md § 4.7): the whole cell is a GET link to the current route
 * with `sort` and `dir` changed, page reset; aria-sort says how the column sorts now. The sort the
 * list applies is the controller's reading of the same two parameters.
 */
#[AsTwigComponent('Px:SortHeader')]
final class SortHeader
{
    public string $label;
    public string $field;
    public bool $right = false;
    public ?string $sort = null;
    public string $direction = 'asc';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function mount(string $label, string $field, ?string $sort = null, string $direction = 'asc'): void
    {
        if (!\in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException(\sprintf('Px:SortHeader sorts "asc" or "desc", not "%s".', $direction));
        }
        $this->label = $label;
        $this->field = $field;
        $this->sort = $sort;
        $this->direction = $direction;
    }

    public function isSorted(): bool
    {
        return $this->sort === $this->field;
    }

    /** The value of aria-sort, or null when the column does not sort the list. */
    public function ariaSort(): ?string
    {
        if (!$this->isSorted()) {
            return null;
        }

        return 'asc' === $this->direction ? 'ascending' : 'descending';
    }

    /** A click on the sorting column reverses it; on another column, sorts it ascending. */
    public function nextDirection(): string
    {
        return $this->isSorted() && 'asc' === $this->direction ? 'desc' : 'asc';
    }

    public function url(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $changes = ['sort' => $this->field, 'dir' => $this->nextDirection()];
        if (null === $request) {
            return '?'.http_build_query($changes);
        }
        $query = $request->query->all();
        unset($query['page']);
        $query = array_merge($query, $changes);
        $route = $request->attributes->get('_route');
        $routeParams = $request->attributes->get('_route_params');
        if (!\is_string($route) || !\is_array($routeParams)) {
            return '?'.http_build_query($query);
        }

        return $this->urls->generate($route, array_merge($routeParams, $query));
    }
}
