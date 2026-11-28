<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Enum\Dossier\TemplateShelf;
use App\Service\Accounting\Chart\AccountSearch;

/**
 * A template as the library shows it to one teacher: where it sits for them, the exercise of its
 * date, how many students' and groups' dossiers were made from it, how many colleagues it is
 * shared with (their own), and whether their share lets them change it (shared with them).
 */
final readonly class TemplateCard
{
    public function __construct(
        public Dossier $template,
        public TemplateShelf $shelf,
        public ?Exercise $exercise,
        public int $copies,
        public int $shares,
        public bool $sharedForChange,
    ) {
    }

    /** Words typed in the library's search, against its title, company, author and description. */
    public function matches(string $text): bool
    {
        $text = AccountSearch::fold($text);
        if ('' === $text) {
            return true;
        }
        $haystack = AccountSearch::fold(implode(' ', [
            $this->template->getLibraryTitle(),
            $this->template->getName(),
            $this->template->getOwner()?->getDisplayName() ?? '',
            $this->template->getLibraryDescription() ?? '',
        ]));
        foreach (explode(' ', $text) as $word) {
            if (!str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
