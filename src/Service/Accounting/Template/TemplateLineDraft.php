<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

/** A line of an entry template as typed: the Compte cell, the Tiers cell, the label, side, formula, fixed amount (cents). */
final class TemplateLineDraft
{
    public function __construct(
        public string $account = '',
        public string $thirdParty = '',
        public string $label = '',
        public string $side = '',
        public string $amount = '',
        public ?int $fixed = null,
    ) {
    }

    /** Neither account, nor third party, nor label, nor fixed amount: the form's spare line. */
    public function isBlank(): bool
    {
        return '' === trim($this->account) && '' === trim($this->thirdParty) && '' === trim($this->label) && null === $this->fixed;
    }
}
