<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Audit\AuditAction;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\PaymentTermKind;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Chart\AccountSearch;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\Csv\CsvRefused;
use App\Service\Shared\Csv\CsvText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Third parties imported from a CSV file (Tiers › Importer): a header line naming the columns -
 * at least `code`, `nature` and `nom` - then one third party per line, as ThirdPartyCsv writes it
 * or as a spreadsheet saves it. Every line is checked by ThirdPartyRules, as the form does.
 *
 * A code missing from the dossier is added; a code already there is left as it is unless the person
 * asks for the existing ones to be updated. Without a `compte` column, a third party goes on its
 * kind's usual account (411000, 401000, 421000) when the plan holds it; on a collective account
 * without an auxiliary code, one is given. **One refused line and nothing is written**: the report
 * names every line and why.
 *
 * @phpstan-type ParsedRow array{line: int, draft: ThirdPartyDraft, refusal: ?string}
 */
final readonly class ThirdPartyImport
{
    public const int MAX_ROWS = 5000;

    private const array COLUMNS = [
        'code' => ['code', 'code tiers', 'numero'],
        'kind' => ['nature', 'type'],
        'name' => ['nom', 'raison sociale', 'name', 'intitule'],
        'individual' => ['particulier'],
        'account' => ['compte', 'compte collectif', 'collectif', 'comptenum'],
        'auxiliaryCode' => ['auxiliaire', 'code auxiliaire', 'compte auxiliaire', 'compauxnum'],
        'siren' => ['siren'],
        'vatNumber' => ['tva', 'numero de tva', 'tva intracommunautaire'],
        'street' => ['adresse', 'adresse 1', 'rue'],
        'street2' => ['complement', 'adresse 2', 'complement d adresse'],
        'postalCode' => ['code postal', 'cp'],
        'city' => ['ville', 'commune'],
        'country' => ['pays'],
        'contactName' => ['contact'],
        'email' => ['email', 'e mail', 'courriel'],
        'phone' => ['telephone', 'tel'],
        'termKind' => ['conditions', 'conditions de reglement'],
        'termDays' => ['jours', 'nombre de jours'],
        'termDay' => ['jour', 'jour du mois'],
        'paymentMethod' => ['mode', 'mode de reglement'],
        'note' => ['note', 'notes', 'remarque'],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ThirdPartyRepository $thirdParties,
        private AccountRepository $accounts,
        private AuditTrail $auditTrail,
    ) {
    }

    public function prepare(Dossier $dossier, string $bytes, bool $updateExisting): ThirdPartyImportReport
    {
        try {
            [$rows, $hasAccountColumn] = self::parse($bytes);
        } catch (CsvRefused $refused) {
            return ThirdPartyImportReport::refused($refused->getMessage());
        }
        if ([] === $rows) {
            return ThirdPartyImportReport::refused('Ce fichier ne contient aucun tiers.');
        }
        $byCode = [];
        $byAuxiliary = [];
        foreach ($this->thirdParties->findByDossier($dossier) as $thirdParty) {
            $byCode[$thirdParty->getCode()] = $thirdParty;
            if (null !== $thirdParty->getAuxiliaryCode()) {
                $byAuxiliary[$thirdParty->getAuxiliaryCode()] = $thirdParty;
            }
        }
        $accountOf = fn (string $number) => $this->accounts->findOneByNumber($dossier, $number);
        /** @var array<string, array{line: int, name: string}> $codesInFile */
        $codesInFile = [];
        /** @var array<string, array{line: int, name: string}> $auxiliariesInFile */
        $auxiliariesInFile = [];
        $lines = [];
        foreach ($rows as $row) {
            $draft = $row['draft'];
            if (null !== $row['refusal']) {
                $lines[] = new ThirdPartyImportLine($row['line'], $draft->code, $draft->name, ThirdPartyImportLine::REFUSED, $row['refusal']);
                continue;
            }
            $self = $byCode[ThirdPartyCode::normalize($draft->code)] ?? null;
            $kind = ThirdPartyKind::tryFrom($draft->kind);
            if (!$hasAccountColumn && null !== $kind && null !== ($default = $kind->defaultAccountNumber()) && null !== $accountOf($default)) {
                $draft->account = $default;
            }
            $fields = ThirdPartyRules::check(
                $draft,
                $accountOf,
                static function (string $code) use ($codesInFile): ?string {
                    return isset($codesInFile[$code]) ? \sprintf('%s (ligne %d)', $codesInFile[$code]['name'], $codesInFile[$code]['line']) : null;
                },
                static function (string $code) use ($auxiliariesInFile, $byAuxiliary, $self): ?string {
                    if (isset($auxiliariesInFile[$code])) {
                        return \sprintf('%s (ligne %d)', $auxiliariesInFile[$code]['name'], $auxiliariesInFile[$code]['line']);
                    }
                    $holder = $byAuxiliary[$code] ?? null;

                    return null === $holder || $holder === $self ? null : $holder->getName();
                },
            );
            $codesInFile[$draft->code] ??= ['line' => $row['line'], 'name' => $draft->name];
            if (null === $fields) {
                $lines[] = new ThirdPartyImportLine($row['line'], $draft->code, $draft->name, ThirdPartyImportLine::REFUSED, implode(' ', array_values($draft->errors)));
                continue;
            }
            if (null !== $fields->auxiliaryCode) {
                $auxiliariesInFile[$fields->auxiliaryCode] = ['line' => $row['line'], 'name' => $draft->name];
            }
            if (null === $self) {
                $lines[] = new ThirdPartyImportLine($row['line'], $draft->code, $draft->name, ThirdPartyImportLine::CREATE, $draft->notice, $draft, $fields);
                continue;
            }
            $changes = $updateExisting && ThirdPartySheet::snapshot(ThirdPartyDraft::of($self)) !== ThirdPartySheet::snapshot($draft);
            $lines[] = new ThirdPartyImportLine($row['line'], $draft->code, $draft->name, $changes ? ThirdPartyImportLine::UPDATE : ThirdPartyImportLine::SAME, $changes ? $draft->notice : null, $draft, $fields);
        }

        return new ThirdPartyImportReport($lines);
    }

    /** Writes what an applicable report says; the caller flushes. */
    public function apply(Dossier $dossier, ThirdPartyImportReport $report, User $by, string $fileName): void
    {
        if (!$report->isApplicable()) {
            throw new \LogicException('A refused import writes nothing.');
        }
        foreach ($report->lines as $line) {
            if (null === $line->draft || null === $line->fields) {
                continue;
            }
            if (ThirdPartyImportLine::CREATE === $line->action) {
                $thirdParty = new ThirdParty($dossier, $line->draft->code, $line->fields->kind, $line->draft->name);
                ThirdPartySheet::write($thirdParty, $line->draft, $line->fields);
                $this->entityManager->persist($thirdParty);
            } elseif (ThirdPartyImportLine::UPDATE === $line->action) {
                $thirdParty = $this->thirdParties->findOneByCode($dossier, $line->draft->code) ?? throw new \LogicException('Gone since prepared.');
                ThirdPartySheet::write($thirdParty, $line->draft, $line->fields);
            }
        }
        $created = $report->count(ThirdPartyImportLine::CREATE);
        $updated = $report->count(ThirdPartyImportLine::UPDATE);
        $this->auditTrail->record(
            AuditAction::ThirdPartiesImported,
            $by,
            'dossier',
            (string) $dossier->getId(),
            \sprintf('Tiers importés de %s : %d créés, %d modifiés', $fileName, $created, $updated),
            ['dossier' => $dossier->getId(), 'file' => $fileName, 'created' => $created, 'updated' => $updated, 'unchanged' => $report->count(ThirdPartyImportLine::SAME)],
        );
    }

    /**
     * The file's lines, each read or refused on its own, and whether it has a `compte` column.
     *
     * @return array{0: list<ParsedRow>, 1: bool}
     *
     * @throws CsvRefused when the file as a whole cannot be read
     */
    public static function parse(string $bytes): array
    {
        $rows = CsvText::read($bytes, self::MAX_ROWS + 1);
        if ([] === $rows) {
            return [[], false];
        }
        $columns = self::header(array_shift($rows)['cells']);
        if (\count($rows) > self::MAX_ROWS) {
            throw new CsvRefused(\sprintf('Ce fichier dépasse %d tiers : découpez-le.', self::MAX_ROWS));
        }
        $read = [];
        foreach ($rows as $row) {
            $cell = static fn (string $field): string => isset($columns[$field]) ? ($row['cells'][$columns[$field]] ?? '') : '';
            $draft = new ThirdPartyDraft();
            foreach (['code', 'name', 'account', 'auxiliaryCode', 'siren', 'vatNumber', 'street', 'street2', 'postalCode', 'city', 'country', 'contactName', 'email', 'phone', 'termDays', 'termDay', 'note'] as $field) {
                $draft->{$field} = $cell($field);
            }
            $refusals = [];
            $kind = ThirdPartyKind::fromCsv(AccountSearch::fold($cell('kind')));
            if (null === $kind) {
                $refusals[] = '' === $cell('kind') ? 'Il manque la nature du tiers (client, fournisseur, salarie ou autre).' : \sprintf('Nature « %s » inconnue : client, fournisseur, salarie ou autre.', $cell('kind'));
            }
            $draft->kind = $kind->value ?? '';
            $individual = match (AccountSearch::fold($cell('individual'))) {
                '', 'non', 'n', 'no', '0', 'faux', 'false' => false,
                'oui', 'o', 'yes', 'y', 'x', '1', 'vrai', 'true' => true,
                default => null,
            };
            if (null === $individual) {
                $refusals[] = 'La colonne « particulier » vaut oui ou non.';
            }
            $draft->individual = true === $individual;
            if ('' === $cell('termKind')) {
                $draft->termKind = PaymentTermKind::Net->value;
                $draft->termDays = '' === $cell('termDays') ? '30' : $cell('termDays');
            } else {
                $term = PaymentTermKind::fromCsv(AccountSearch::fold($cell('termKind')));
                if (null === $term) {
                    $refusals[] = \sprintf('Conditions « %s » inconnues : %s.', $cell('termKind'), implode(', ', array_map(static fn (PaymentTermKind $k): string => $k->csvValue(), PaymentTermKind::cases())));
                }
                $draft->termKind = $term->value ?? '';
            }
            $method = '' === $cell('paymentMethod') ? PaymentMethod::Transfer : PaymentMethod::fromCsv(AccountSearch::fold($cell('paymentMethod')));
            if (null === $method) {
                $refusals[] = \sprintf('Mode « %s » inconnu : %s.', $cell('paymentMethod'), implode(', ', array_map(static fn (PaymentMethod $m): string => $m->csvValue(), PaymentMethod::cases())));
            }
            $draft->paymentMethod = $method->value ?? '';
            $read[] = ['line' => $row['line'], 'draft' => $draft, 'refusal' => [] === $refusals ? null : implode(' ', $refusals)];
        }

        return [$read, isset($columns['account'])];
    }

    /**
     * The columns the header line names.
     *
     * @param list<string> $cells
     *
     * @return array<string, int>
     *
     * @throws CsvRefused when it names no code, nature or name column
     */
    private static function header(array $cells): array
    {
        $columns = [];
        foreach ($cells as $index => $cell) {
            $name = AccountSearch::fold($cell);
            foreach (self::COLUMNS as $column => $names) {
                if (!isset($columns[$column]) && \in_array($name, $names, true)) {
                    $columns[$column] = $index;
                }
            }
        }
        if (!isset($columns['code'], $columns['kind'], $columns['name'])) {
            throw new CsvRefused('La première ligne doit nommer les colonnes, au moins « code », « nature » et « nom » : c’est le format de « Exporter ».');
        }

        return $columns;
    }
}
