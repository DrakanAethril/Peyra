<?php

declare(strict_types=1);

namespace App\Service\Dossier\Search;

/**
 * One line of the global search (design-ui.md § 4.6, the account picker's option): a code in mono
 * (an account number, an auxiliary code, a journal's code), a label and what places it (`detail`:
 * « Comptabilité › Éditions », the entry's number and date), a figure on the right (a balance, an
 * amount), the address it opens. A screen or an action of the catalogue carries its `key` - what
 * the recent screens remember - and the `terms` it answers besides its label.
 */
final readonly class SearchItem
{
    public function __construct(
        public SearchGroup $group,
        public string $label,
        public string $href,
        public ?string $detail = null,
        public ?string $code = null,
        public ?string $aside = null,
        public ?string $icon = null,
        public string $key = '',
        public string $terms = '',
        /** How many leading characters of the code the query typed (drawn in <mark>). */
        public int $codeMark = 0,
    ) {
    }

    /** The same line under another group (a screen shown among the recent ones). */
    public function in(SearchGroup $group): self
    {
        return new self($group, $this->label, $this->href, $this->detail, $this->code, $this->aside, $this->icon, $this->key, $this->terms);
    }

    /** The same line with the typed start of its code marked. */
    public function marked(int $length): self
    {
        return new self($this->group, $this->label, $this->href, $this->detail, $this->code, $this->aside, $this->icon, $this->key, $this->terms, max(0, min($length, mb_strlen((string) $this->code))));
    }
}
