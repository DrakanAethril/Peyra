<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryLineRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\Csv\CsvRefused;
use App\Service\Shared\Csv\CsvText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A plan of accounts imported from a CSV file (Plan de comptes › Importer): `numero;intitule`,
 * optionally `lettrable;collectif`, with or without a header line (the columns are then read in
 * that order), as ChartCsv writes it or as a spreadsheet saves it.
 *
 * Accounts missing from the plan are added; an account already there is left as it is unless the
 * person asks for the existing ones to be updated. **One refused line and nothing is written**:
 * the report names every line and why (lot 1, « un fichier refusé n'écrit rien »). A number that
 * subdivides no account of the reference chart is written with a note, as on the account form.
 */
final readonly class ChartImport
{
    public const int MAX_ROWS = 5000;

    private const array COLUMNS = [
        'number' => ['numero', 'n', 'no', 'compte', 'numero de compte', 'comptenum', 'account'],
        'label' => ['intitule', 'libelle', 'intitule du compte', 'comptelib', 'label'],
        'lettrable' => ['lettrable', 'lettrage'],
        'collective' => ['collectif', 'compte collectif'],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AccountRepository $accounts,
        private AccountSheet $sheet,
        private AuditTrail $auditTrail,
        private ThirdPartyRepository $thirdParties,
        private EntryLineRepository $entryLines,
    ) {
    }

    public function prepare(Dossier $dossier, string $bytes, bool $updateExisting): ChartImportReport
    {
        try {
            $rows = self::parse($bytes);
        } catch (CsvRefused $refused) {
            return ChartImportReport::refused($refused->getMessage());
        }
        if ([] === $rows) {
            return ChartImportReport::refused('Ce fichier ne contient aucun compte.');
        }
        $existing = [];
        foreach ($this->accounts->findPlan($dossier) as $account) {
            $existing[$account->getNumber()] = $account;
        }
        $carried = $this->thirdParties->countByAccount($dossier);
        $withLines = $this->entryLines->countByAccount($dossier);
        $refusesOutside = $this->sheet->refusesOutsideChart($dossier);
        $seen = [];
        $lines = [];
        foreach ($rows as $row) {
            $refusal = $row['refusal'];
            $number = $row['number'];
            if (null === $refusal && isset($seen[$number])) {
                $refusal = \sprintf('Le compte %s figure déjà ligne %d.', $number, $seen[$number]);
            }
            $seen[$number] ??= $row['line'];
            $collective = $row['collective'];
            if (null === $refusal && true === $collective && !AccountNumber::mayBeCollective($number)) {
                $refusal = 'Seul un compte de tiers (classe 4) est collectif.';
            }
            if (null !== $refusal) {
                $lines[] = new ChartImportLine($row['line'], $number, $row['label'], $row['lettrable'], $collective, ChartImportLine::REFUSED, $refusal);
                continue;
            }
            $account = $existing[$number] ?? null;
            if (null === $account) {
                $outside = $this->sheet->outsideChart($dossier, $number);
                $lines[] = null !== $outside && $refusesOutside
                    ? new ChartImportLine($row['line'], $number, $row['label'], $row['lettrable'], $collective, ChartImportLine::REFUSED, AccountSheet::outsideChartRefusal($outside))
                    : new ChartImportLine($row['line'], $number, $row['label'], $row['lettrable'], $collective, ChartImportLine::CREATE, $outside);
                continue;
            }
            $carries = $carried[(int) $account->getId()] ?? 0;
            if ($updateExisting && null !== $collective && $collective !== $account->isCollective() && $carries > 0) {
                $lines[] = new ChartImportLine($row['line'], $number, $row['label'], $row['lettrable'], $collective, ChartImportLine::REFUSED, AccountSheet::collectiveRefusal($account->isCollective(), $carries));
                continue;
            }
            if ($updateExisting && null !== $collective && $collective !== $account->isCollective() && ($withLines[(int) $account->getId()] ?? 0) > 0) {
                $lines[] = new ChartImportLine($row['line'], $number, $row['label'], $row['lettrable'], $collective, ChartImportLine::REFUSED, AccountSheet::entriesCollectiveRefusal($account->isCollective()));
                continue;
            }
            if ($updateExisting && false === $row['lettrable'] && $account->isLettrable() && $this->entryLines->countLetteredOnAccount($account) > 0) {
                $lines[] = new ChartImportLine($row['line'], $number, $row['label'], $row['lettrable'], $collective, ChartImportLine::REFUSED, AccountSheet::LETTERED_REFUSAL);
                continue;
            }
            $changes = $updateExisting && (
                $account->getLabel() !== $row['label']
                || (null !== $row['lettrable'] && $account->isLettrable() !== $row['lettrable'])
                || (null !== $collective && $account->isCollective() !== $collective)
            );
            $lines[] = new ChartImportLine($row['line'], $number, $row['label'], $row['lettrable'], $collective, $changes ? ChartImportLine::UPDATE : ChartImportLine::SAME);
        }

        return new ChartImportReport($lines);
    }

    /** Writes what an applicable report says; the caller flushes. */
    public function apply(Dossier $dossier, ChartImportReport $report, User $by, string $fileName): void
    {
        if (!$report->isApplicable()) {
            throw new \LogicException('A refused import writes nothing.');
        }
        $existing = [];
        foreach ($this->accounts->findPlan($dossier) as $account) {
            $existing[$account->getNumber()] = $account;
        }
        foreach ($report->lines as $line) {
            if (ChartImportLine::CREATE === $line->action) {
                $this->entityManager->persist(new Account(
                    $dossier,
                    $line->number,
                    $line->label,
                    $line->lettrable ?? AccountNumber::isLettrableByDefault($line->number),
                    $line->collective ?? AccountNumber::isCollectiveByDefault($line->number),
                    AccountOrigin::Imported,
                ));
            } elseif (ChartImportLine::UPDATE === $line->action) {
                $account = $existing[$line->number];
                $account->describe($line->number, $line->label, $line->lettrable ?? $account->isLettrable(), $line->collective ?? $account->isCollective());
            }
        }
        $created = $report->count(ChartImportLine::CREATE);
        $updated = $report->count(ChartImportLine::UPDATE);
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::ChartImported,
            $by,
            \sprintf('Plan de comptes importé de %s : %d créés, %d modifiés', $fileName, $created, $updated),
            ['dossier' => $dossier->getId(), 'file' => $fileName, 'created' => $created, 'updated' => $updated, 'unchanged' => $report->count(ChartImportLine::SAME)],
        );
    }

    /**
     * The file's lines, each read or refused on its own.
     *
     * @return list<array{line: int, number: string, label: string, lettrable: ?bool, collective: ?bool, refusal: ?string}>
     *
     * @throws CsvRefused when the file as a whole cannot be read
     */
    public static function parse(string $bytes): array
    {
        $rows = CsvText::read($bytes, self::MAX_ROWS + 1);
        if ([] === $rows) {
            return [];
        }
        $columns = self::header($rows[0]['cells']);
        if (null !== $columns) {
            array_shift($rows);
        } else {
            $columns = ['number' => 0, 'label' => 1, 'lettrable' => 2, 'collective' => 3];
        }
        if (\count($rows) > self::MAX_ROWS) {
            throw new CsvRefused(\sprintf('Ce fichier dépasse %d comptes : découpez-le.', self::MAX_ROWS));
        }
        $read = [];
        foreach ($rows as $row) {
            $cells = $row['cells'];
            $number = AccountNumber::normalize($cells[$columns['number']] ?? '');
            $label = trim((string) preg_replace('/\s+/u', ' ', $cells[$columns['label']] ?? ''));
            $refusal = AccountNumber::fault($number);
            if (null === $refusal && '' === $label) {
                $refusal = 'Il manque l’intitulé du compte.';
            } elseif (null === $refusal && mb_strlen($label) > 200) {
                $refusal = 'Un intitulé tient en 200 caractères.';
            }
            [$lettrableRead, $lettrable] = self::flag($cells[$columns['lettrable'] ?? -1] ?? '');
            [$collectiveRead, $collective] = self::flag($cells[$columns['collective'] ?? -1] ?? '');
            if (null === $refusal && (!$lettrableRead || !$collectiveRead)) {
                $refusal = 'Les colonnes « lettrable » et « collectif » valent oui ou non.';
            }
            $read[] = [
                'line' => $row['line'],
                'number' => $number,
                'label' => $label,
                'lettrable' => $lettrable,
                'collective' => $collective,
                'refusal' => $refusal,
            ];
        }

        return $read;
    }

    /**
     * The columns named by a header line, or null when the first line is an account.
     *
     * @param list<string> $cells
     *
     * @return array{number: int, label: int, lettrable?: int, collective?: int}|null
     *
     * @throws CsvRefused when a header names no number column
     */
    private static function header(array $cells): ?array
    {
        if (1 === preg_match('/^\d/', $cells[0] ?? '')) {
            return null;
        }
        $columns = [];
        foreach ($cells as $index => $cell) {
            $name = AccountSearch::fold($cell);
            foreach (self::COLUMNS as $column => $names) {
                if (!isset($columns[$column]) && \in_array($name, $names, true)) {
                    $columns[$column] = $index;
                }
            }
        }
        if (!isset($columns['number'], $columns['label'])) {
            throw new CsvRefused('La première ligne doit nommer les colonnes « numero » et « intitule », ou être déjà un compte.');
        }

        return $columns;
    }

    /**
     * A yes-or-no cell: [read, value], the value null when the cell is empty (the account's default).
     *
     * @return array{0: bool, 1: ?bool}
     */
    private static function flag(string $cell): array
    {
        return match (AccountSearch::fold($cell)) {
            '' => [true, null],
            'oui', 'o', 'yes', 'y', 'x', '1', 'vrai', 'true' => [true, true],
            'non', 'n', 'no', '0', 'faux', 'false' => [true, false],
            default => [false, null],
        };
    }
}
