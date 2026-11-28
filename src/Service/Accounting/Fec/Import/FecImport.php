<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\FecImportJob;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\EntryState;
use App\Enum\Accounting\FecImportStage;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\FecImportJobRepository;
use App\Service\Accounting\Fec\FecConformity;
use App\Service\Accounting\Fec\FecDocument;
use App\Service\Accounting\Fec\FecFinding;
use App\Service\Accounting\Fec\FecReader;
use App\Service\Accounting\Journal\JournalDraft;
use App\Service\Accounting\Journal\JournalSheet;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Accounting\Lettering\Lettering;
use App\Service\Accounting\Lettering\LetteringRefused;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\Library\TemplateFactory;
use App\Service\Shared\FrenchFormat;
use App\Service\ThirdParty\ThirdPartyRules;
use App\Service\ThirdParty\ThirdPartySheet;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Nouveau dossier modèle › Depuis un FEC » (lot 1, steps 1.9b and 1.10b; design-ux.md § 3.3): a
 * template of the teacher's own made from another tool's FEC - its plan, journals, third parties
 * and validated entries, its lettering -, up to MAX_ENTRIES entries, written in the background.
 *
 * **`launch()`, in the request, refuses a file before anything is written**: the template's fields
 * (TemplateFactory::prepare()), the file's form and balance (FecConformity, without the name nor
 * the sequence: Peyra numbers what it validates), what the file makes of a template
 * (FecImportReading), then what the doors would refuse of it (FecImportRehearsal: ThirdPartyRules
 * and EntryRules over the template built in memory). Only then, in one transaction, the template is
 * opened - hidden while it fills (`Dossier::$importing`) - and the import recorded with its file.
 *
 * **`pass()` is one pass of the background** (FecImportRunner, under the import's lock), in one
 * transaction, rolled back whole when it stops - the next pass starts again where it was: the plan,
 * journals and third parties; then a batch of entries drafted through the Ledger in date order;
 * then a batch validated (Peyra's numbers, validated on the dossier's date); then a batch of groups
 * lettered through Lettering. The last one shows the template in the library.
 */
final readonly class FecImport
{
    /** A small company's whole exercise, written in the background (about 15 ms an entry). */
    public const int MAX_ENTRIES = 10000;

    /** Entries drafted, or validated, in one pass; lettering groups lettered in one pass. */
    public const int PASS_ENTRIES = 500;
    public const int PASS_LETTERINGS = 200;

    /**
     * Entries drafted, entries validated, groups lettered between two clearings of the entity
     * manager: each draft and each lettering flushes, and a flush looks at every entity managed.
     */
    private const int DRAFT_BATCH = 5;
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
        private FecImportJobRepository $jobs,
        private DossierContext $context,
        private AuditTrail $auditTrail,
    ) {
    }

    /** The import recorded, its template opened and hidden; or null with the draft's faults written. */
    public function launch(FecImportDraft $draft, User $by, \DateTimeImmutable $at): ?FecImportJob
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
                'Un FEC importé compte %s écritures au plus ; celui-ci en compte %s. Pour un dossier modèle, gardez une partie de l’exercice (quelques mois, par exemple).',
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
        $findings = FecImportRehearsal::check($start, $plan);
        if ([] !== $findings) {
            usort($findings, static fn (FecFinding $a, FecFinding $b): int => ($a->line ?? \PHP_INT_MAX) <=> ($b->line ?? \PHP_INT_MAX));
            $draft->findings = $findings;

            return null;
        }

        $fileName = $draft->fileName ?? 'FEC';

        return $this->entityManager->wrapInTransaction(function () use ($start, $draft, $plan, $fileName, $by, $at): FecImportJob {
            $template = $this->factory->open($start, $draft->template->title, \sprintf('depuis le FEC %s', $fileName), $by, $at);
            $template->markImporting();
            $job = new FecImportJob($by, $template, $fileName, (string) $draft->bytes, $start->exerciseStart, \count($plan->entries), \count($plan->journals), \count($plan->thirdParties), \count($plan->letterings), $plan->zeroLines, $plan->lettersLeft, $at);
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            return $job;
        });
    }

    /**
     * One pass of an import still pending, in one transaction (a fault rolls it back and is thrown
     * to the caller, FecImportRunner, which stops the import). The entity manager is cleared on the
     * way: entities loaded before are detached after.
     *
     * @param int $size entries drafted or validated in this pass (lettering groups: PASS_LETTERINGS)
     *
     * @return bool whether passes are left
     */
    public function pass(int $jobId, \DateTimeImmutable $at, int $size = self::PASS_ENTRIES): bool
    {
        $job = $this->jobs->find($jobId);
        if (null === $job || !$job->isPending()) {
            return false;
        }
        $templateId = $job->getTemplate()?->getId() ?? throw new \LogicException('A pending import has its template.');
        $byId = (int) $job->getAuthor()->getId();
        $document = FecReader::read($job->bytes());
        if (!$document instanceof FecDocument) {
            throw new \LogicException('A launched FEC reads.');
        }
        $plan = FecImportReading::read($document, $job->getExerciseStart());
        $stage = $job->getStage();
        $position = $job->getPosition();

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $done = match ($stage) {
                FecImportStage::Setup => $this->setUp($templateId, $plan, $job->getFileName(), $byId),
                FecImportStage::Drafts => $this->draftEntries($templateId, $plan, $byId, $position, $size),
                FecImportStage::Validation => $this->validateEntries($templateId, $byId, $size),
                FecImportStage::Lettering => $this->letter($templateId, $plan, $byId, $position, self::PASS_LETTERINGS),
            };
            [$template] = $this->fresh($templateId, $byId);
            $job = $this->jobs->find($jobId) ?? throw new \LogicException('The import is being run.');
            if (FecImportStage::Setup === $stage) {
                $job->setUp($done);
            } else {
                $job->advance($done);
            }
            if ($job->isThrough()) {
                $template->markImported();
                $job->finish($at);
            }
            $this->entityManager->flush();
            $connection->commit();

            return $job->isPending();
        } catch (\Throwable $failure) {
            $connection->rollBack();
            throw $failure;
        }
    }

    /** The template's plan, journals and third parties; answers how many accounts were added. */
    private function setUp(int $templateId, FecImportPlan $plan, string $fileName, int $byId): int
    {
        [$template, $by] = $this->fresh($templateId, $byId);

        return $this->context->within($template, function () use ($template, $plan, $fileName, $by): int {
            $created = $this->accounts($template, $plan, $fileName, $by);
            $this->journals($template, $plan, $by);
            $this->thirdParties($template, $plan, $fileName, $by);

            return $created;
        });
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
            $thirdParty = FecImportRehearsal::thirdPartyDraft($read);
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
     * The file's entries in the order they are drafted - by date, the file's order within a day -,
     * hence the order of their ids: the k-th entry of the template is the k-th of this list.
     *
     * @return list<int> indexes in the plan
     */
    private static function order(FecImportPlan $plan): array
    {
        $order = array_keys($plan->entries);
        usort($order, static fn (int $a, int $b): int => [$plan->entries[$a]->date, $a] <=> [$plan->entries[$b]->date, $b]);

        return $order;
    }

    /** Entries drafted from `$from`, `$size` at most; answers how many. */
    private function draftEntries(int $templateId, FecImportPlan $plan, int $byId, int $from, int $size): int
    {
        $opening = [];
        foreach ($plan->journals as $journal) {
            $opening[$journal->code] = JournalType::Opening === $journal->type;
        }
        $slice = \array_slice(self::order($plan), $from, $size);
        $findings = [];
        foreach (array_chunk($slice, self::DRAFT_BATCH) as $batch) {
            [$template, $by] = $this->fresh($templateId, $byId);
            $this->context->within($template, function () use ($template, $by, $batch, $plan, $opening, &$findings): void {
                foreach ($batch as $index) {
                    $read = $plan->entries[$index];
                    try {
                        $this->ledger->draft($template, FecImportRehearsal::entryDraft($read), $by, ($opening[$read->journal] ?? false) ? EntryOrigin::Opening : EntryOrigin::Manual);
                    } catch (EntryRefused $refused) {
                        foreach ($refused->errors as $key => $error) {
                            $findings[] = FecImportRehearsal::entryFinding($read, $key, $error);
                        }
                    }
                }
            });
        }
        self::refuseUnlessEmpty($findings);

        return \count($slice);
    }

    /** The oldest drafts validated, `$size` at most, in their order; answers how many. */
    private function validateEntries(int $templateId, int $byId, int $size): int
    {
        [$template] = $this->fresh($templateId, $byId);
        /** @var list<int> $ids */
        $ids = $this->context->within($template, fn (): array => array_map(
            static fn (Entry $entry): int => (int) $entry->getId(),
            $this->entries->findBy(['state' => EntryState::Draft], ['id' => 'ASC'], $size),
        ));
        foreach (array_chunk($ids, self::VALIDATION_BATCH) as $batch) {
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

        return \count($ids);
    }

    /** The file's lettering groups from `$from`, `$size` at most, each lettered as a whole; answers how many. */
    private function letter(int $templateId, FecImportPlan $plan, int $byId, int $from, int $size): int
    {
        $groups = \array_slice($plan->letterings, $from, $size);
        if ([] === $groups) {
            return 0;
        }
        $lineIds = $this->lineIds($templateId, $plan, $byId);
        $findings = [];
        foreach (array_chunk($groups, self::LETTERING_BATCH) as $batch) {
            [$template, $by] = $this->fresh($templateId, $byId);
            $this->context->within($template, function () use ($template, $by, $batch, $plan, $lineIds, &$findings): void {
                foreach ($batch as $group) {
                    $ids = array_map(static fn (array $member): int => $lineIds[$member[0]][$member[1]] ?? throw new \LogicException('Every line of the plan was written.'), $group);
                    try {
                        $this->lettering->letter($template, $ids, $by);
                    } catch (LetteringRefused $refused) {
                        $findings[] = new FecFinding($plan->entries[$group[0][0]]->lines[$group[0][1]]->fecLine, 'EcritureLet', $refused->getMessage());
                    }
                }
            });
        }
        self::refuseUnlessEmpty($findings);

        return \count($groups);
    }

    /**
     * The ids of the template's lines by the entry's index in the plan, in the order of its lines:
     * entries and lines were written in the plan's order.
     *
     * @return array<int, list<int>>
     */
    private function lineIds(int $templateId, FecImportPlan $plan, int $byId): array
    {
        [$template] = $this->fresh($templateId, $byId);
        /** @var list<array{entry: int|string, id: int|string}> $rows */
        $rows = $this->context->within($template, fn (): array => $this->entityManager
            ->createQuery('SELECT IDENTITY(l.entry) AS entry, l.id AS id FROM App\Entity\Accounting\EntryLine l ORDER BY l.entry ASC, l.id ASC')
            ->getArrayResult());
        $byEntry = [];
        foreach ($rows as $row) {
            $byEntry[(int) $row['entry']][] = (int) $row['id'];
        }
        $order = self::order($plan);
        $lineIds = [];
        foreach (array_values($byEntry) as $rank => $ids) {
            if (isset($order[$rank])) {
                $lineIds[$order[$rank]] = $ids;
            }
        }

        return $lineIds;
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
