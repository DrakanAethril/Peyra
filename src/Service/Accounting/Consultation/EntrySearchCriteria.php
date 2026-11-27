<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Entity\Accounting\Journal;
use App\Enum\Accounting\EntryState;
use App\Service\Shared\AmountParseError;
use App\Service\Shared\AmountParser;
use App\Service\Shared\Money;

/**
 * What « Recherche d'écritures » looks for (design-ux.md § 3.2: journal, période, compte, montant,
 * pièce, état - the document searched with the labels), read from the screen's query string: every
 * filter optional and ANDed. Narrowed to
 * one journal and a month, it is the journal's interrogation. Filters that cannot be read are kept
 * as typed and said under `errors`, by field, and do not narrow.
 */
final class EntrySearchCriteria
{
    public ?Journal $journal = null;
    public ?ConsultedSubject $subject = null;
    public ?Money $amount = null;
    /** A text the document or a label (the entry's or a line's) holds. */
    public string $text = '';
    public ?EntryState $state = null;
    public bool $newestFirst = true;

    /** @var array<string, string> by field: journal, account, amount, window */
    public array $errors = [];

    /** @var array<string, string> the filters as typed, for the form */
    public array $typed = [];

    public function __construct(public ConsultationWindow $window)
    {
    }

    /**
     * @param array<string, string>                        $query    the screen's query string, trimmed
     * @param callable(string): ?Journal                   $journal  a journal of the dossier by code
     * @param callable(string): ?ConsultedSubject          $subject  an account or third party as typed
     */
    public static function read(array $query, ConsultationWindow $window, callable $journal, callable $subject): self
    {
        $criteria = new self($window);
        $criteria->typed = $query;
        if (null !== $window->error) {
            $criteria->errors['window'] = $window->error;
        }

        $code = mb_strtoupper($query['journal'] ?? '');
        if ('' !== $code) {
            $criteria->journal = $journal($code);
            if (null === $criteria->journal) {
                $criteria->errors['journal'] = \sprintf('Le journal %s n’existe pas dans le dossier.', $code);
            }
        }
        $account = $query['account'] ?? '';
        if ('' !== $account) {
            $criteria->subject = $subject($account);
            if (null === $criteria->subject) {
                $criteria->errors['account'] = \sprintf('Ni compte ni tiers ne répond à %s.', mb_strtoupper($account));
            }
        }
        $amount = $query['amount'] ?? '';
        if ('' !== $amount) {
            try {
                $criteria->amount = AmountParser::parse($amount)->abs();
            } catch (AmountParseError $error) {
                $criteria->errors['amount'] = $error->getMessage();
            }
        }
        $criteria->text = $query['q'] ?? '';
        $criteria->state = EntryState::tryFrom($query['state'] ?? '');
        $criteria->newestFirst = 'asc' !== ($query['dir'] ?? '');

        return $criteria;
    }
}
