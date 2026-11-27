<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\EntrySide;
use App\Enum\Accounting\JournalType;
use App\Enum\Accounting\TemplateAmount;
use App\Enum\Accounting\TemplateBase;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * The one reading of an entry template (« modèle d'écriture »), a pure function over three
 * lookups - the fiche, the demonstration's file and every future import go through it:
 *
 * - a code of 1 to 12 capitals or digits, unique in the dossier; a label; a journal of the dossier
 *   that is not the à-nouveaux one;
 * - lines typed as the entry grid types them: an account number of the plan, or a third party's
 *   auxiliary code; a third party by code only on a collective account typed by number (empty
 *   there, the call asks for it); never the journal's own counterpart (a bank or cash journal adds
 *   it itself); two lines at least - one in a bank or cash journal;
 * - an amount for each: HT, TVA, TTC (the template then says whether the call asks the amount before
 *   VAT or VAT included, and its rate), a fixed amount, « Solde de l'écriture » (once), « À saisir »;
 * - and they must balance: tried on 1 000,00 at the template's rate, unless a line is to type, a
 *   balance line closes them, or the journal's counterpart does.
 */
final class TemplateRules
{
    public const int MAX_LINES = 30;

    /**
     * @param callable(string): ?Journal    $journalOf    by code
     * @param callable(string): ?Account    $accountOf    by number
     * @param callable(string): ?ThirdParty $thirdPartyOf by auxiliary code, then by code
     * @param callable(string): ?string     $codeHolder   the label of another template of that code
     */
    public static function check(TemplateDraft $draft, callable $journalOf, callable $accountOf, callable $thirdPartyOf, callable $codeHolder): ?TemplateFields
    {
        $draft->errors = [];
        $draft->code = strtoupper((string) preg_replace('/\s+/u', '', $draft->code));
        $draft->label = trim((string) preg_replace('/\s+/u', ' ', $draft->label));
        $draft->journal = strtoupper(trim($draft->journal));
        $draft->lines = $draft->typedLines();

        if ('' === $draft->code) {
            $draft->errors['code'] = 'Indiquez le code du modèle.';
        } elseif (1 !== preg_match('/^[A-Z0-9]{1,12}$/', $draft->code)) {
            $draft->errors['code'] = 'Un code de modèle compte de 1 à 12 lettres ou chiffres, sans accent ni espace (LOYER, VENTE20).';
        } elseif (null !== ($holder = $codeHolder($draft->code))) {
            $draft->errors['code'] = \sprintf('Le modèle %s existe déjà : %s.', $draft->code, $holder);
        }

        if ('' === $draft->label) {
            $draft->errors['label'] = 'Indiquez l’intitulé du modèle.';
        } elseif (mb_strlen($draft->label) > 80) {
            $draft->errors['label'] = 'Un intitulé de modèle tient en 80 caractères.';
        }

        $journal = '' === $draft->journal ? null : $journalOf($draft->journal);
        if ('' === $draft->journal) {
            $draft->errors['journal'] = 'Choisissez le journal du modèle.';
        } elseif (null === $journal) {
            $draft->errors['journal'] = \sprintf('Le journal %s n’existe pas dans le dossier.', $draft->journal);
        } elseif (JournalType::Opening === $journal->getType()) {
            $draft->errors['journal'] = 'Les à-nouveaux reprennent la balance d’ouverture : ils n’ont pas de modèle.';
        }
        $counterpart = $journal?->getCounterpartAccount()?->getNumber();

        $lines = [];
        $needsBase = false;
        $hasVat = false;
        $open = false;
        $balances = 0;
        foreach ($draft->lines as $index => $line) {
            $line->account = strtoupper(AccountNumber::normalize($line->account));
            $line->thirdParty = strtoupper(trim($line->thirdParty));
            $line->label = trim((string) preg_replace('/\s+/u', ' ', $line->label));
            $at = 'line.'.$index.'.';

            if ('' === $line->account) {
                $draft->errors[$at.'account'] = 'Indiquez le compte de la ligne.';
            } elseif (1 === preg_match('/^\d+$/', $line->account)) {
                $account = $accountOf($line->account);
                if (null === $account) {
                    $draft->errors[$at.'account'] = \sprintf('Le compte %s n’est pas dans le plan du dossier.', $line->account);
                } elseif ($line->account === $counterpart) {
                    $draft->errors[$at.'account'] = \sprintf('La contrepartie %s s’ajoute seule dans le journal %s : elle n’entre pas dans le modèle.', $counterpart, $draft->journal);
                } elseif ('' !== $line->thirdParty) {
                    $named = $thirdPartyOf($line->thirdParty);
                    if (!$account->isCollective()) {
                        $draft->errors[$at.'thirdParty'] = 'Seul un compte collectif (401000, 411000) tapé par son numéro prend un tiers.';
                    } elseif (null === $named) {
                        $draft->errors[$at.'thirdParty'] = \sprintf('Le tiers %s n’existe pas dans le dossier.', $line->thirdParty);
                    }
                }
            } else {
                $named = $thirdPartyOf($line->account);
                if (null === $named) {
                    $draft->errors[$at.'account'] = \sprintf('Ni compte ni tiers ne répond à %s.', $line->account);
                } elseif (null === $named->getAccount()) {
                    $draft->errors[$at.'account'] = \sprintf('Le tiers %s n’est tenu sur aucun compte : il ne se saisit pas sur une écriture.', $line->account);
                }
                if ('' !== $line->thirdParty) {
                    $draft->errors[$at.'thirdParty'] = 'Le code auxiliaire nomme déjà le tiers de la ligne.';
                }
            }

            if (mb_strlen($line->label) > 250) {
                $draft->errors[$at.'label'] = 'Un libellé tient en 250 caractères.';
            }
            $side = EntrySide::tryFrom($line->side);
            if (null === $side) {
                $draft->errors[$at.'side'] = 'Choisissez le sens de la ligne.';
            }
            $amount = TemplateAmount::tryFrom($line->amount);
            $fixed = null;
            if (null === $amount) {
                $draft->errors[$at.'amount'] = 'Choisissez le montant de la ligne.';
            } elseif (TemplateAmount::Fixed === $amount) {
                if (null === $line->fixed || $line->fixed <= 0) {
                    $draft->errors[$at.'fixed'] = 'Indiquez le montant fixe de la ligne.';
                } else {
                    $fixed = Money::cents($line->fixed);
                }
            } else {
                $line->fixed = null;
            }
            $needsBase = $needsBase || (null !== $amount && $amount->needsBase());
            $hasVat = $hasVat || TemplateAmount::Vat === $amount;
            $open = $open || TemplateAmount::Typed === $amount;
            if (TemplateAmount::Balance === $amount) {
                ++$balances;
                if ($balances > 1) {
                    $draft->errors[$at.'amount'] = 'Une seule ligne prend le solde de l’écriture.';
                }
            }
            if (null !== $side && null !== $amount) {
                $lines[] = ['account' => $line->account, 'thirdParty' => $line->thirdParty, 'label' => $line->label, 'side' => $side, 'amount' => $amount, 'fixed' => $fixed];
            }
        }

        $minimum = null === $counterpart ? 2 : 1;
        if (\count($draft->lines) < $minimum) {
            $draft->errors['lines'] = 1 === $minimum ? 'Un modèle a une ligne au moins.' : 'Un modèle a deux lignes au moins : une écriture a toujours un débit et un crédit.';
        } elseif (\count($draft->lines) > self::MAX_LINES) {
            $draft->errors['lines'] = \sprintf('Un modèle tient en %d lignes.', self::MAX_LINES);
        }

        $base = null;
        $rate = null;
        if ($needsBase) {
            $base = TemplateBase::tryFrom($draft->base);
            if (null === $base) {
                $draft->errors['base'] = 'Des lignes sont en HT, TVA ou TTC : dites quel montant l’appel demande.';
            }
            $rate = VatRate::parse($draft->rate);
            if (null === $rate) {
                $draft->errors['rate'] = '' === trim($draft->rate) ? 'Indiquez le taux de TVA proposé à l’appel (0 sans TVA).' : 'Un taux de TVA s’écrit en pourcentage, de 0 à 100 : 20, 5,5.';
            } elseif ($hasVat && $rate->isZero()) {
                $draft->errors['rate'] = 'Une ligne de TVA demande un taux supérieur à 0.';
            } else {
                $draft->rate = str_replace('.', ',', VatRate::canonical($rate));
            }
        } else {
            $draft->base = '';
            $draft->rate = '';
        }

        if ([] === $draft->errors && null === $counterpart && !$open && 0 === $balances) {
            $sample = Money::cents(100_000);
            $debit = Money::zero();
            $credit = Money::zero();
            foreach (TemplateComputation::lines($lines, $base, $sample, $rate) as $computed) {
                $amount = $computed['amount'] ?? Money::zero();
                EntrySide::Debit === $computed['side'] ? $debit = $debit->plus($amount) : $credit = $credit->plus($amount);
            }
            if (!$debit->equals($credit)) {
                $draft->errors['lines'] = \sprintf(
                    'Les lignes ne s’équilibrent pas : essayées sur %s%s, elles font %s au débit et %s au crédit. Ajoutez la ligne qui manque, ou une ligne « Solde de l’écriture ».',
                    FrenchFormat::amount($sample),
                    null === $base ? '' : ' '.(TemplateBase::Net === $base ? 'HT' : 'TTC'),
                    FrenchFormat::amount($debit),
                    FrenchFormat::amount($credit),
                );
            }
        }

        if ([] !== $draft->errors || null === $journal) {
            return null;
        }

        return new TemplateFields($draft->code, $draft->label, $journal, $base, null === $rate ? null : VatRate::canonical($rate), $lines);
    }
}
