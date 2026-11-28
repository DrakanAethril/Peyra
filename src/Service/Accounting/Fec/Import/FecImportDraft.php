<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Service\Accounting\Fec\FecFinding;
use App\Service\Dossier\Library\EmptyTemplateDraft;

/**
 * « Nouveau dossier modèle › Depuis un FEC » as a teacher sent it: the fields of an empty
 * template (its card's title, the company, the exercise, the dossier's date, the reference chart)
 * and the file - then what is wrong with either: the fields' errors on `template`, a sentence for
 * the file itself, the file's anomalies line by line.
 */
final class FecImportDraft
{
    public EmptyTemplateDraft $template;

    public ?string $fileName = null;
    public ?string $bytes = null;
    public ?string $fileError = null;

    /** @var list<FecFinding> */
    public array $findings = [];

    public function __construct()
    {
        $this->template = new EmptyTemplateDraft();
    }

    public function isRefused(): bool
    {
        return null !== $this->fileError || [] !== $this->findings || $this->template->errorCount() > 0;
    }
}
