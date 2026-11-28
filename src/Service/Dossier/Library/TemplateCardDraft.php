<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Dossier\Dossier;
use App\Service\Shared\PostValue;
use Symfony\Component\HttpFoundation\Request;

/** A template's card as a teacher typed it (TemplateCards checks it), and the error of each field. */
final class TemplateCardDraft
{
    public string $title = '';
    public string $description = '';
    public string $level = '';

    /** @var list<string> */
    public array $processes = [];

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(Dossier $template): self
    {
        $draft = new self();
        $draft->title = $template->hasOwnLibraryTitle() ? $template->getLibraryTitle() : '';
        $draft->description = $template->getLibraryDescription() ?? '';
        $draft->level = $template->getLibraryLevel()->value ?? '';
        $draft->processes = array_map(static fn ($process): string => $process->value, $template->getLibraryProcesses());

        return $draft;
    }

    /** The card's fields of a posted form (`title`, `description`, `level`, `processes[]`). */
    public static function fromRequest(Request $request): self
    {
        $draft = new self();
        $draft->title = PostValue::trimmed($request, 'title');
        $draft->description = PostValue::trimmed($request, 'description');
        $draft->level = PostValue::trimmed($request, 'level');
        $draft->processes = array_values(PostValue::stringsByKey($request, 'processes'));

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
