<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryTemplate;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\TemplateAmount;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Accounting\Entry\GridContext;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\JournalGrid;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * Comptabilité › Saisie › guidée (design-ux.md § 3.2, gabarit F): a template chosen, a few fields
 * - date, document, third party, amount, rate -, the entry it makes shown before F10 writes it to
 * the brouillard, checked as the grid checks it (JournalGrid::check(): EntryRules, the controls of
 * the assistance level, the third party's due date). It offers the templates whose every line is
 * computed; one with a line « À saisir » is called with F6 in the grid.
 *
 * Open or hidden by the assistance level (Assistance::opensGuidedEntry(); « sauf réglage de la
 * mission » comes with the missions, step 2.9).
 */
final readonly class GuidedEntry
{
    public function __construct(
        private JournalGrid $grid,
        private PeriodRepository $periods,
        private AccountRepository $accounts,
        private Ledger $ledger,
    ) {
    }

    /** The next document of a series: « LOY-2026-01 » → « LOY-2026-02 », the width kept (as the grid's). */
    public static function nextDocument(string $document): string
    {
        if (1 !== preg_match('/^(.*?)(\d+)(\D*)$/', $document, $match)) {
            return $document;
        }

        return $match[1].str_pad((string) ((int) $match[2] + 1), \strlen($match[2]), '0', \STR_PAD_LEFT).$match[3];
    }

    /** A template the guided entry can complete: no line is left to type. */
    public static function offers(EntryTemplate $template): bool
    {
        foreach ($template->getLines() as $line) {
            if (TemplateAmount::Typed === $line->getAmount()) {
                return false;
            }
        }

        return [] !== $template->getLines();
    }

    public function asksThirdParty(Dossier $dossier, EntryTemplate $template): bool
    {
        return TemplateCall::asksThirdParty($template, fn (string $number) => $this->accounts->findOneByNumber($dossier, $number));
    }

    public function preview(Dossier $dossier, EntryTemplate $template, GuidedEntryInput $input): GuidedEntryPreview
    {
        $call = TemplateCall::lines(
            $template,
            $input->date ?? $dossier->getDossierDate(),
            null === $input->amount ? null : Money::cents($input->amount),
            $input->rate,
            $input->thirdParty,
            fn (string $number) => $this->accounts->findOneByNumber($dossier, $number),
            true,
        );
        $errors = $call['errors'];
        if (null === $input->date) {
            $errors['date'] = 'Indiquez la date de l’écriture : JJ/MM/AAAA.';
        }
        $period = null === $input->date ? null : $this->periods->findAt($dossier, $input->date);
        if (null !== $input->date && null === $period) {
            $errors['date'] = \sprintf('Le %s n’est dans aucun exercice du dossier.', FrenchFormat::date($input->date));
        }
        if (null === $period) {
            return new GuidedEntryPreview([], $errors, [], null);
        }

        $context = $this->grid->context($dossier, $template->getJournal(), $period);
        $check = $this->grid->check($context, new GridGroup('guided', null, $input->date->format('Y-m-d'), $input->document, $call['lines']));
        foreach ($check->errors as $key => $message) {
            // The amount not yet typed already says so: its lines need not repeat it.
            if ((isset($call['errors']['amount']) || isset($call['errors']['rate'])) && (str_ends_with($key, '.amount') || 'lines' === $key)) {
                continue;
            }
            $errors[$key] ??= $message;
        }

        $lines = [];
        $counterpart = $context->counterpart();
        $typed = \count($call['lines']);
        foreach ($check->draft->lines as $index => $line) {
            $lines[] = $this->previewLine($context, $line, null !== $counterpart && $index >= $typed);
        }

        return new GuidedEntryPreview($lines, $errors, $check->controls, $check);
    }

    /**
     * F10: the entry written to the brouillard when nothing stops it - else null, and the preview
     * says why.
     */
    public function save(Dossier $dossier, GuidedEntryPreview $preview, User $me): ?Entry
    {
        if (!$preview->isWritable() || null === $preview->check) {
            return null;
        }

        return $this->ledger->draft($dossier, $preview->check->draft, $me);
    }

    /** @return array{account: string, title: string, thirdParty: string, label: string, debit: Money, credit: Money, automatic: bool} */
    private function previewLine(GridContext $context, EntryLineDraft $line, bool $automatic): array
    {
        $thirdParty = '' === $line->thirdParty ? null : $context->thirdParty($line->thirdParty);

        return [
            'account' => $line->account,
            'title' => $context->account($line->account)?->getLabel() ?? '',
            'thirdParty' => null === $thirdParty ? $line->thirdParty : (($thirdParty->getAuxiliaryCode() ?? $thirdParty->getCode()).' · '.$thirdParty->getName()),
            'label' => $line->label,
            'debit' => $line->debit,
            'credit' => $line->credit,
            'automatic' => $automatic,
        ];
    }
}
