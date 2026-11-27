<?php

declare(strict_types=1);

namespace App\Service\Shared;

use Symfony\Component\HttpFoundation\Request;

/**
 * The page of a paginated list a request asks for (design-ux.md § 6.6): 50 rows by default, 25, 50
 * or 100 at the reader's choice, remembered per screen for the session. `?page=` and `?per=` are
 * read through QueryValue, so a hand-edited or empty value reads as « not given », never as a 400;
 * a size outside the three is ignored, which is what keeps a request from asking for a whole table.
 *
 *     $page = ListPage::fromRequest($request, 'ledger.entries')->within($total);
 *     $rows = $repository->findPage($page->offset(), $page->perPage);
 *     <twig:Px:Pager :total="total" :perPage="page.perPage" :page="page.page" :sizes="true"/>
 */
final readonly class ListPage
{
    public const int DEFAULT_SIZE = 50;
    public const array SIZES = [25, 50, 100];

    private const string SESSION_KEY = 'px_list_sizes';

    private function __construct(
        public int $page,
        public int $perPage,
    ) {
    }

    public static function of(int $page, int $perPage = self::DEFAULT_SIZE): self
    {
        return new self(max(1, $page), \in_array($perPage, self::SIZES, true) ? $perPage : self::DEFAULT_SIZE);
    }

    /**
     * The page and size of the request. A size given in the query is remembered for this screen
     * (`$screen`, a stable name such as « ledger.entries »); without one, the remembered size applies.
     */
    public static function fromRequest(Request $request, string $screen): self
    {
        $asked = QueryValue::int($request, 'per');
        $session = $request->hasSession() ? $request->getSession() : null;
        $remembered = $session?->get(self::SESSION_KEY);
        $sizes = \is_array($remembered) ? $remembered : [];

        if (\in_array($asked, self::SIZES, true)) {
            $perPage = $asked;
            if (null !== $session && ($sizes[$screen] ?? null) !== $asked) {
                $sizes[$screen] = $asked;
                $session->set(self::SESSION_KEY, $sizes);
            }
        } else {
            $stored = $sizes[$screen] ?? null;
            $perPage = \is_int($stored) && \in_array($stored, self::SIZES, true) ? $stored : self::DEFAULT_SIZE;
        }

        return new self(max(1, QueryValue::int($request, 'page', 1)), $perPage);
    }

    /** The same page, brought back to the last one when the list is shorter than the link said. */
    public function within(int $total): self
    {
        $pages = max(1, intdiv(max(0, $total) + $this->perPage - 1, $this->perPage));

        return new self(min($this->page, $pages), $this->perPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
