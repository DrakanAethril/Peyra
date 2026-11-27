<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use App\Entity\Accounting\EntryTemplate;

/**
 * An entry template as a person typed it, before TemplateRules checks it, with the error of each
 * field: `code`, `label`, `journal`, `base`, `rate`, `lines` (the lines as a whole) and
 * `line.<i>.<field>` (account, thirdParty, label, side, amount, fixed) for the line at index i.
 */
final class TemplateDraft
{
    public string $code = '';
    public string $label = '';
    /** The journal's code. */
    public string $journal = '';
    /** TemplateBase's value, or empty. */
    public string $base = '';
    /** As typed (« 20 », « 5,5 »). */
    public string $rate = '';
    /** @var list<TemplateLineDraft> */
    public array $lines = [];

    /** @var array<string, string> by key */
    public array $errors = [];

    public static function of(EntryTemplate $template): self
    {
        $draft = new self();
        $draft->code = $template->getCode();
        $draft->label = $template->getLabel();
        $draft->journal = $template->getJournal()->getCode();
        $draft->base = $template->getBase()->value ?? '';
        $draft->rate = null === $template->getVatRate() ? '' : str_replace('.', ',', $template->getVatRate());
        foreach ($template->getLines() as $line) {
            $draft->lines[] = new TemplateLineDraft($line->getAccount(), $line->getThirdParty(), $line->getLabel(), $line->getSide()->value, $line->getAmount()->value, $line->getFixed()?->cents);
        }

        return $draft;
    }

    public function error(string $key): ?string
    {
        return $this->errors[$key] ?? null;
    }

    /** @return list<TemplateLineDraft> the lines with something on them: a line left empty in the form is not one */
    public function typedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (TemplateLineDraft $line): bool => !$line->isBlank()));
    }
}
