<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Service\Dossier\CompanyDraft;
use App\Service\Shared\PostValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * « Nouveau dossier modèle › Dossier vide » as a teacher typed it (TemplateFactory checks it): the
 * card's title, the company's fiche, the first exercise's bounds, the dossier's date, the
 * reference chart of its plan - and the error of each field (the company's under its own fields).
 */
final class EmptyTemplateDraft
{
    public string $title = '';
    public CompanyDraft $company;
    /** Days as the date fields post them (`2026-01-01`). */
    public string $exerciseStart = '';
    public string $exerciseEnd = '';
    public string $dossierDate = '';
    public string $chart = '';

    /** @var array<string, string> by field, the company's apart */
    public array $errors = [];

    public function __construct()
    {
        $this->company = new CompanyDraft();
    }

    public static function fromRequest(Request $request): self
    {
        $draft = new self();
        $draft->title = PostValue::trimmed($request, 'title');
        $draft->company = CompanyDraft::fromRequest($request);
        $draft->exerciseStart = PostValue::trimmed($request, 'exerciseStart');
        $draft->exerciseEnd = PostValue::trimmed($request, 'exerciseEnd');
        $draft->dossierDate = PostValue::trimmed($request, 'dossierDate');
        $draft->chart = PostValue::trimmed($request, 'chart');

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    public function errorCount(): int
    {
        return \count($this->errors) + \count($this->company->errors);
    }
}
