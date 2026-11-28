<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\EntryLine;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
use App\Service\Accounting\Fec\FecConformity;
use App\Service\Accounting\Fec\FecFinding;
use App\Service\Accounting\Fec\FecReader;
use App\Service\Accounting\Journal\JournalDraft;
use App\Service\Accounting\Journal\JournalSheet;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Accounting\Lettering\Lettering;
use App\Service\Accounting\Lettering\LetteringRefused;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\Library\TemplateFactory;
use App\Service\Dossier\Library\TemplateStart;
use App\Service\Shared\FrenchFormat;
use App\Service\ThirdParty\ThirdPartyDraft;
use App\Service\ThirdParty\ThirdPartyRules;
use App\Service\ThirdParty\ThirdPartySheet;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Nouveau dossier modèle › Depuis un FEC » (lot 1, step 1.9b; design-ux.md § 3.3): a template of
 * the teacher's own made from another tool's FEC - its plan, journals, third parties and validated
 * entries, its lettering.
 *
 * In order: the template's fields (TemplateFactory::prepare()), the file's form and balance
 * (FecConformity, without the name nor the sequence: Peyra numbers what it validates), what the file
 * makes of a template (FecImportReading); then, in one transaction, the template opened with its
 * exercise and reference chart, the file's accounts added or given its label and boxes, its
 * journals through JournalSheet, its third parties through ThirdPartyRules, its entries drafted
 * then validated through the Ledger (Peyra's numbers, validated on the dossier's date), its letters
 * through Lettering. **A refused file writes nothing**: every fault, whichever door finds it, comes
 * back on the line of the file that carries it, and the transaction is rolled back.
 */
final readonly class FecImport
{
    /**
     * Entries drafted, entries validated, groups lettered between two clearings of the entity
     * manager: each draft and each lettering flushes, and a flush looks at every entity managed.
     */
    private const int DRAFT_BATCH = 5;

    /** Every entry goes through the Ledger, about a hundredth of a second each: a teaching template is a few months of a small company. */
    public const int MAX_ENTRIES = 1000;
    private const int VALIDATION_BATCH = 200;
    private const int LETTERING_BATCH = 10;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TemplateFactory $factory,
        private AccountRepository $accounts,
        private JournalSheet $journals,
        private Ledger $ledger,
        private Lettering $lettering,
        private EntryRepository $entries,
        private DossierContext $context,
        private AuditTrail $auditTrail,
    ) {
    }

    /** The template made, or null with the draft's faults written. */
    public function import(FecImportDraft $draft, User $by, \DateTimeImmutable $at): ?FecImportResult
    {
        $draft->findings = [];
        $start = $this->factory->prepare($draft->template);
        if (null === $draft->bytes && null === $draft->fileError) {
            $draft->fileError = 'Choisissez le fichier FEC à importer.';
        }
        if (null === $start || null === $draft->bytes || null !== $draft->fileError) {
            return null;
        }

        $report = FecConformity::check($draft->bytes, null, $start->exerciseStart, $start->exerciseEnd, false);
        $document = FecReader::read($draft->bytes);
        if (!$report->isConform() || \is_string($document)) {
            $draft->findings = $report->findings;

            return null;
        }
        if ($report->entries > self::MAX_ENTRIES) {
            $draft->findings = [new FecFinding(null, null, \sprintf(
                'Un FEC importé compte %s écritures au plus ; celui-ci en compte %s. Pour un dossier modèle, gardez une partie de l’exercice (un trimestre, par exemple).',
                FrenchFormat::number(self::MAX_ENTRIES),
                FrenchFormat::number($report->entries),
            ))];

            return null;
        }
        $plan = FecImportReading::read($document, $start->exerciseStart);
        if (!$plan->isApplicable()) {
            $draft->findings = $plan->findings;

            return null;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $this->write($start, $plan, $draft, $by, $at);
            $connection->commit();

            return $result;
        } catch (FecImportRefused $refused) {
            $connection->rollBack();
            $this->entityManager->clear();
            $draft->findings = $refused->findings;

            return null;
        } catch (\Throwable $failure) {
            $connection->rollBack();
            throw $failure;
        }
    }

    private function write(TemplateStart $start, FecImportPlan $plan, FecImportDraft $draft, User $by, \DateTimeImmutable $at): FecImportResult
    {
        $fileName = $draft->fileName ?? 'FEC';
        $template = $this->factory->open($start, $draft->template->title, \sprintf('depuis le FEC %s', $fileName), $by, $at);
        $created = $this->context->within($template, function () use ($template, $plan, $fileName, $by): int {
            $created = $this->accounts($template, $plan, $fileName, $by);
            $this->journals($template, $plan, $by);
            $this->thirdParties($template, $plan, $fileName, $by);

            return $created;
        });
        $templateId = (int) $template->getId();
        $byId = (int) $by->getId();
        $lineIds = $this->entries($templateId, $plan, $byId);
        $this->letter($templateId, $plan, $lineIds, $byId);

        return new FecImportResult($this->fresh($templateId, $byId)[0], \count($lineIds), $created, \count($plan->journals), \count($plan->thirdParties), \count($plan->letterings), $plan->zeroLines, $plan->lettersLeft);
    }

    /**
     * The template and the actor read again in an entity manager cleared of what the previous batch
     * loaded: a flush looks at every entity it manages, and a FEC of a thousand entries would
     * otherwise make each flush longer than the one before.
     *
     * @return array{Dossier, User}
     */
    private function fresh(int $templateId, int $byId): array
    {
        $this->entityManager->clear();

        return [
            $this->entityManager->find(Dossier::class, $templateId) ?? throw new \LogicException('The template is being written.'),
            $this->entityManager->find(User::class, $byId) ?? throw new \LogicException('The teacher exists.'),
        ];
    }

    /** The file's accounts added to the plan, or given the file's label and boxes; answers how many were added. */
    private function accounts(Dossier $template, FecImportPlan $plan, string $fileName, User $by): int
    {
        $created = 0;
        $updated = 0;
        foreach ($plan->accounts as $read) {
            $account = $this->accounts->findOneByNumber($template, $read->number);
            if (null === $account) {
                $this->entityManager->persist(new Account($template, $read->number, $read->label, $read->lettrable, $read->collective, AccountOrigin::Imported));
                ++$created;
            } elseif ($account->getLabel() !== $read->label || $account->isLettrable() !== $read->lettrable || $account->isCollective() !== $read->collective) {
                $account->describe($account->getNumber(), $read->label, $read->lettrable, $read->collective);
                ++$updated;
            }
        }
        $this->auditTrail->inDossier(
            $template,
            AuditAction::ChartImported,
            $by,
            \sprintf('Plan de comptes repris du FEC %s : %d créés, %d modifiés', $fileName, $created, $updated),
            ['dossier' => $template->getId(), 'file' => $fileName, 'created' => $created, 'updated' => $updated],
        );
        $this->entityManager->flush();

        return $created;
    }

    private function journals(Dossier $template, FecImportPlan $plan, User $by): void
    {
        $findings = [];
        foreach ($plan->journals as $read) {
            $journal = new JournalDraft();
            $journal->code = $read->code;
            $journal->label = $read->label;
            $journal->type = $read->type->value;
            $journal->counterpart = $read->counterpart ?? '';
            $fields = $this->journals->check($journal, $template);
            if (null === $fields) {
                foreach ($journal->errors as $field => $error) {
                    $findings[] = new FecFinding($read->line, 'label' === $field ? 'JournalLib' : 'JournalCode', $error);
                }
                continue;
            }
            $this->journals->create($template, $journal, $fields, $by);
            $this->entityManager->flush();
        }
        self::refuseUnlessEmpty($findings);
    }

    private function thirdParties(Dossier $template, FecImportPlan $plan, string $fileName, User $by): void
    {
        $findings = [];
        foreach ($plan->thirdParties as $read) {
            $thirdParty = new ThirdPartyDraft();
            $thirdParty->code = $read->code;
            $thirdParty->kind = $read->kind->value;
            $thirdParty->name = $read->name;
            $thirdParty->account = $read->account;
            $thirdParty->auxiliaryCode = $read->auxiliaryCode;
            $thirdParty->termKind = 'net';
            $thirdParty->termDays = '30';
            $thirdParty->paymentMethod = 'transfer';
            $fields = ThirdPartyRules::check(
                $thirdParty,
                fn (string $number): ?Account => $this->accounts->findOneByNumber($template, $number),
                static fn (string $code): ?string => null,
                static fn (string $code): ?string => null,
            );
            if (null === $fields) {
                foreach ($thirdParty->errors as $error) {
                    $findings[] = new FecFinding($read->line, 'CompAuxNum', $error);
                }
                continue;
            }
            $entity = new ThirdParty($template, $thirdParty->code, $fields->kind, $thirdParty->name);
            ThirdPartySheet::write($entity, $thirdParty, $fields);
            $this->entityManager->persist($entity);
        }
        self::refuseUnlessEmpty($findings);
        $this->auditTrail->inDossier(
            $template,
            AuditAction::ThirdPartiesImported,
            $by,
            \sprintf('Tiers repris du FEC %s : %d créés', $fileName, \count($plan->thirdParties)),
            ['dossier' => $template->getId(), 'file' => $fileName, 'created' => \count($plan->thirdParties)],
        );
        $this->entityManager->flush();
    }

    /**
     * The file's entries drafted in date order (the file's order within a day), then validated in
     * that order - Peyra numbers them so -, a batch at a time.
     *
     * @return array<int, list<int>> by the entry's index in the plan, the ids of its lines
     */
    private function entries(int $templateId, FecImportPlan $plan, int $byId): array
    {
        $opening = [];
        foreach ($plan->journals as $journal) {
            $opening[$journal->code] = JournalType::Opening === $journal->type;
        }
        $order = array_keys($plan->entries);
        usort($order, static fn (int $a, int $b): int => [$plan->entries[$a]->date, $a] <=> [$plan->entries[$b]->date, $b]);

        $findings = [];
        $entryIds = [];
        $lineIds = [];
        foreach (array_chunk($order, self::DRAFT_BATCH) as $batch) {
            [$template, $by] = $this->fresh($templateId, $byId);
            $this->context->within($template, function () use ($template, $by, $batch, $plan, $opening, &$findings, &$entryIds, &$lineIds): void {
                foreach ($batch as $index) {
                    $read = $plan->entries[$index];
                    $lines = array_map(
                        static fn (FecImportLine $line): EntryLineDraft => new EntryLineDraft($line->account, $line->thirdParty, $line->label, $line->debit, $line->credit),
                        $read->lines,
                    );
                    try {
                        $entry = $this->ledger->draft(
                            $template,
                            new EntryDraft($read->journal, $read->date, $read->document, $read->label, $lines),
                            $by,
                            ($opening[$read->journal] ?? false) ? EntryOrigin::Opening : EntryOrigin::Manual,
                        );
                        $entryIds[] = (int) $entry->getId();
                        $lineIds[$index] = array_map(static fn (EntryLine $line): int => (int) $line->getId(), $entry->getLines());
                    } catch (EntryRefused $refused) {
                        foreach ($refused->errors as $key => $error) {
                            $findings[] = self::entryFinding($read, $key, $error);
                        }
                    }
                }
            });
        }
        self::refuseUnlessEmpty($findings);

        foreach (array_chunk($entryIds, self::VALIDATION_BATCH) as $batch) {
            [$template, $by] = $this->fresh($templateId, $byId);
            $this->context->within($template, function () use ($batch, $by): void {
                $entries = $this->entries->findBy(['id' => $batch], ['id' => 'ASC']);
                try {
                    $this->ledger->validate($entries, $by);
                } catch (EntryRefused $refused) {
                    throw new FecImportRefused(array_map(static fn (string $error): FecFinding => new FecFinding(null, null, $error), array_values($refused->errors)));
                }
            });
        }
        ksort($lineIds);

        return $lineIds;
    }

    /**
     * Each group of the file lettered as a whole.
     *
     * @param array<int, list<int>> $lineIds by the entry's index in the plan
     */
    private function letter(int $templateId, FecImportPlan $plan, array $lineIds, int $byId): void
    {
        $findings = [];
        foreach (array_chunk($plan->letterings, self::LETTERING_BATCH) as $batch) {
            [$template, $by] = $this->fresh($templateId, $byId);
            $this->context->within($template, function () use ($template, $by, $batch, $plan, $lineIds, &$findings): void {
                foreach ($batch as $group) {
                    $ids = array_map(static fn (array $member): int => $lineIds[$member[0]][$member[1]], $group);
                    try {
                        $this->lettering->letter($template, $ids, $by);
                    } catch (LetteringRefused $refused) {
                        $findings[] = new FecFinding($plan->entries[$group[0][0]]->lines[$group[0][1]]->fecLine, 'EcritureLet', $refused->getMessage());
                    }
                }
            });
        }
        self::refuseUnlessEmpty($findings);
    }

    /** A fault of the Ledger, under its key, on the line of the file it names. */
    private static function entryFinding(FecImportEntry $entry, string $key, string $error): FecFinding
    {
        if (1 === preg_match('/^line\.(\d+)\.(\w+)$/', $key, $parts)) {
            $zone = match ($parts[2]) {
                'account' => 'CompteNum',
                'thirdParty' => 'CompAuxNum',
                'label' => 'EcritureLib',
                'amount' => 'Debit',
                default => null,
            };

            return new FecFinding($entry->lines[(int) $parts[1]]->fecLine ?? $entry->line, $zone, $error);
        }

        return new FecFinding($entry->line, match ($key) {
            'journal' => 'JournalCode',
            'date' => 'EcritureDate',
            'document' => 'PieceRef',
            'label' => 'EcritureLib',
            default => null,
        }, $error);
    }

    /** @param list<FecFinding> $findings */
    private static function refuseUnlessEmpty(array $findings): void
    {
        if ([] !== $findings) {
            usort($findings, static fn (FecFinding $a, FecFinding $b): int => ($a->line ?? \PHP_INT_MAX) <=> ($b->line ?? \PHP_INT_MAX));

            throw new FecImportRefused($findings);
        }
    }
}
