<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollBody;
use App\Entity\Payroll\PayrollEstablishment;
use App\Entity\Sales\SalesSequence;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Payroll\EmployeeRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Accounting\Chart\ChartInstaller;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Accounting\Lettering\Lettering;
use App\Service\Accounting\Lettering\LetteringKey;
use App\Service\Accounting\Lettering\LetteringRefused;
use App\Service\Accounting\Subscription\SubscriptionRun;
use App\Service\Accounting\Subscription\SubscriptionSheet;
use App\Service\Accounting\Template\StandardTemplates;
use App\Service\Accounting\Template\TemplateFile;
use App\Service\Accounting\Template\TemplateSheet;
use App\Service\Assets\FixedAssetSheet;
use App\Service\Audit\AuditTrail;
use App\Service\Banking\Reconciliation\Pointings;
use App\Service\Banking\Rule\BankRules;
use App\Service\Banking\Statement\BankStatements;
use App\Service\Banking\Statement\StatementCsv;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\Exercises;
use App\Service\Inventory\ArticleSheet;
use App\Service\Inventory\Stock\StockMovements;
use App\Service\Payroll\Employee\EmployeeSheet;
use App\Service\Payroll\Hiring\HiringFormalities;
use App\Service\Payroll\Plan\PayrollPlanInstaller;
use App\Service\Payroll\Settings\PayrollEstablishmentRules;
use App\Service\Sales\SalesDocumentDraft;
use App\Service\Sales\SalesDocuments;
use App\Service\Shared\Portal\PortalFault;
use App\Service\ThirdParty\ThirdPartyRules;
use App\Service\ThirdParty\ThirdPartySheet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The demonstration dossier, Menuiserie Vasseur SARL (plan README § 11), built from
 * `fixtures/demo/`: installed once as a template of the common library (app:demo:install, in
 * development and in production alike: it is the first template the référents get), which
 * app:dev:dossiers distributes to the development classes. Grows with each step.
 */
final readonly class DemoInstaller
{
    public const string DENOMINATION = 'Menuiserie Vasseur';

    /**
     * Entries drafted, entries validated between two clearings of the entity manager: a flush
     * looks at every entity it manages, and a quarter of entries drafted one flush at a time over
     * a growing unit of work took six times as long (as the FEC import found).
     */
    private const int DRAFT_BATCH = 20;
    private const int VALIDATION_BATCH = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DossierRepository $dossiers,
        private Exercises $exercises,
        private AuditTrail $auditTrail,
        private ReferenceCharts $charts,
        private ChartInstaller $chartInstaller,
        private AccountRepository $accounts,
        private DossierContext $context,
        private Ledger $ledger,
        private TemplateSheet $templateSheet,
        private StandardTemplates $standardTemplates,
        private FixedAssetSheet $fixedAssetSheet,
        private SubscriptionSheet $subscriptionSheet,
        private SubscriptionRun $subscriptionRun,
        private Lettering $lettering,
        private ThirdPartyRepository $thirdParties,
        private PayrollPlanInstaller $payrollPlan,
        private DemoPayroll $payroll,
        private EmployeeSheet $employees,
        private EmployeeRepository $employeeRepository,
        private HiringFormalities $hiring,
        private EntryRepository $entries,
        private ArticleSheet $articleSheet,
        private SalesDocuments $salesDocuments,
        private StockMovements $stockMovements,
        private BankRules $bankRules,
        private BankStatements $bankStatements,
        private Pointings $pointings,
        private LedgerReader $ledgerReader,
        private JournalRepository $journalRepository,
        #[Autowire('%kernel.project_dir%/fixtures/demo')]
        private string $directory,
    ) {
    }

    /**
     * The common library's demonstration template; null when it is already installed. The install
     * clears the entity manager as it goes: entities the caller loaded before are detached after.
     */
    public function installTemplate(string $actor, \DateTimeImmutable $at): ?Dossier
    {
        if (null !== $this->dossiers->findCommonTemplateNamed(self::DENOMINATION)) {
            return null;
        }

        return $this->build($actor, $at);
    }

    public function findTemplate(): ?Dossier
    {
        return $this->dossiers->findCommonTemplateNamed(self::DENOMINATION);
    }

    private function build(string $actor, \DateTimeImmutable $at): Dossier
    {
        $file = DemoCompanyFile::read($this->directory.'/company.yaml');
        $chartFile = DemoChartFile::read($this->directory);
        $thirdPartyFile = DemoThirdPartyFile::read($this->directory.'/third_parties.yaml');
        $entryFile = DemoEntryFile::read($this->directory.'/entries.yaml');
        $templateFile = TemplateFile::read($this->directory.'/templates.yaml');
        $fixedAssetFile = DemoFixedAssetFile::read($this->directory.'/fixed_assets.yaml');
        $subscriptionFile = DemoSubscriptionFile::read($this->directory.'/subscriptions.yaml');
        $letteringFile = DemoLetteringFile::read($this->directory.'/letterings.yaml');
        $payrollFile = DemoPayrollFile::read($this->directory.'/payroll.yaml');
        $employeeFile = DemoEmployeeFile::read($this->directory.'/employees.yaml');
        $hiringFile = DemoHiringFile::read($this->directory.'/hiring.yaml');
        $salesFile = DemoSalesFile::read($this->directory.'/sales.yaml');
        $stockFile = DemoStockFile::read($this->directory.'/stock.yaml');
        $bankFile = DemoBankFile::read($this->directory.'/bank.yaml');
        $payslipFile = DemoPayslipFile::read($this->directory.'/payslips.yaml');
        $dossier = new Dossier(DossierKind::Template, $file->company, $file->dossierDate, null, null, $at, null);
        $dossier->describeInLibrary($file->library['title'], $file->library['description'], $file->library['level'], $file->library['processes']);
        $this->entityManager->persist($dossier);
        // An id first: the exercises enter the dossier's context.
        $this->entityManager->flush();
        $current = null;
        foreach ($file->exercises as $exercise) {
            $this->exercises->open($dossier, $exercise['code'], $exercise['start'], $exercise['end'], $exercise['closed']);
            if ($exercise['start'] <= $file->dossierDate && $file->dossierDate <= $exercise['end']) {
                $current = $exercise['start'];
            }
        }
        // The plan of the exercise the dossier starts in (PCG 2026 for an exercise opened in 2026).
        $this->chartInstaller->install($dossier, $this->charts->forExerciseStartingOn($current ?? $file->dossierDate), $actor);
        $this->entityManager->flush();
        $this->context->within($dossier, fn () => $this->installChart($dossier, $chartFile));
        $this->entityManager->flush();
        $this->context->within($dossier, fn () => $this->installThirdParties($dossier, $thirdPartyFile));
        $this->entityManager->flush();
        $dossierId = (int) $dossier->getId();
        // The payroll before the entries: its payslips write their entries into the brouillard, validated with
        // the quarter's by date (review 4.7a), so that each journal's numbering stays chronological.
        $this->context->within($dossier, fn () => $this->installPayroll($dossier, $payrollFile, $actor));
        $this->context->within($dossier, fn () => $this->installEmployees($dossier, $employeeFile, $actor));
        $this->context->within($dossier, fn () => $this->installHiring($dossier, $hiringFile, $actor));
        $payrollEntries = [];
        $this->context->within($dossier, function () use ($dossier, $payslipFile, $actor, &$payrollEntries): void {
            $payrollEntries = $this->payroll->install($dossier, $payslipFile, $actor);
        });
        $this->installEntries($dossierId, $entryFile, $actor, $payrollEntries);
        $dossier = $this->fresh($dossierId);
        $this->context->within($dossier, fn () => $this->installTemplates($dossier, $templateFile, $actor));
        $this->context->within($dossier, fn () => $this->installFixedAssets($dossier, $fixedAssetFile, $actor));
        $this->context->within($dossier, fn () => $this->installSubscriptions($dossier, $subscriptionFile, $actor, $at));
        $this->context->within($dossier, fn () => $this->installLetterings($dossier, $letteringFile, $actor));
        $this->context->within($dossier, fn () => $this->installSales($dossier, $salesFile, $actor));
        $this->context->within($dossier, fn () => $this->installStock($dossier, $stockFile, $actor));
        $this->context->within($dossier, fn () => $this->installBank($dossier, $bankFile, $actor));
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::DossierCreated,
            $actor,
            \sprintf('%s créé (%s)', $dossier->getName(), DossierKind::Template->label()),
            ['dossier' => $dossier->getId(), 'kind' => DossierKind::Template->value],
        );
        $this->entityManager->flush();

        return $dossier;
    }

    /**
     * Its payroll settings (step 4.1): the establishment and the bodies, then the standard plan de
     * paie - paid to those bodies - and its own rubriques, each checked by the fiche's rules.
     */
    private function installPayroll(Dossier $dossier, DemoPayrollFile $file, string $actor): void
    {
        $fields = PayrollEstablishmentRules::check($file->establishment) ?? throw new \InvalidArgumentException('payroll.yaml : establishment : '.implode(' ', $file->establishment->errors));
        $this->entityManager->persist(new PayrollEstablishment($dossier, $fields->headcount, $fields->workAccidentRate, $fields->mobilityRate, $fields->monthlyHours));
        foreach ($file->bodies as $body) {
            $this->entityManager->persist(new PayrollBody($dossier, $body['kind'], $body['name'], $body['reference'], $body['account']));
        }
        $this->entityManager->flush();
        $this->payrollPlan->installStandard($dossier, $actor);
        $this->entityManager->flush();
        $this->payrollPlan->install($dossier, $file->items, [], 'payroll.yaml');
        $this->entityManager->flush();
    }

    /**
     * Its employees (step 4.2a), through the fiche's own door: each hired with its first terms, then
     * each later terms from its day. A fault names the matricule and installs nothing more.
     */
    private function installEmployees(Dossier $dossier, DemoEmployeeFile $file, string $actor): void
    {
        foreach ($file->employees as $drafts) {
            $employee = null;
            foreach ($drafts as $draft) {
                $fields = $this->employees->check($draft, $dossier, $employee)
                    ?? throw new \InvalidArgumentException(\sprintf('employees.yaml : %s (%s) : %s', $draft->registrationNumber, $draft->termsFrom, implode(' ', $draft->errors)));
                if (null === $employee) {
                    $employee = $this->employees->create($dossier, $fields, $actor);
                } else {
                    $this->employees->update($employee, $fields, $actor);
                }
                $this->entityManager->flush();
            }
        }
    }

    /** Its DPAE (step 4.2b), deposited on the simulated social portal on their day: a rejection installs nothing more. */
    private function installHiring(Dossier $dossier, DemoHiringFile $file, string $actor): void
    {
        foreach ($file->declarations as $declaration) {
            $employee = $this->employeeRepository->findOneByNumber($dossier, $declaration['number']) ?? throw new \InvalidArgumentException('hiring.yaml : le salarié '.$declaration['number'].' n’existe pas.');
            $outcome = $this->hiring->declare($employee, $declaration['on'], $actor);
            if (\is_array($outcome)) {
                throw new \InvalidArgumentException(\sprintf('hiring.yaml : %s : %s', $declaration['number'], implode(' ', array_map(static fn (PortalFault $fault): string => $fault->motive, $outcome))));
            }
            $this->entityManager->flush();
        }
    }

    /**
     * Its sales (step 2.3): the articles through their fiche's door, the numbering the books already
     * used, then the commercial documents through SalesDocuments - drafted, transformed, validated
     * in the file's order. A fault names its place and installs nothing more.
     */
    private function installSales(Dossier $dossier, DemoSalesFile $file, string $actor): void
    {
        foreach ($file->articles as $draft) {
            $fields = $this->articleSheet->check($draft, $dossier) ?? throw new \InvalidArgumentException(\sprintf('sales.yaml : article %s : %s', $draft->code, implode(' ', $draft->errors)));
            $this->articleSheet->create($dossier, $fields, $actor);
            $this->entityManager->flush();
        }
        foreach ($file->numbering as $numbering) {
            $this->entityManager->persist(new SalesSequence($dossier, $numbering['type'], $numbering['year'], $numbering['last'], $numbering['date']));
        }
        $this->entityManager->flush();
        $made = [];
        foreach ($file->documents as $index => $entry) {
            if (null !== $entry['draft']) {
                $fields = $this->salesDocuments->check($entry['draft'], $dossier, $entry['type']) ?? throw new \InvalidArgumentException(\sprintf('sales.yaml : documents[%d] : %s', $index, implode(' ', $entry['draft']->errors)));
                $document = $this->salesDocuments->create($dossier, $entry['type'], $fields, $actor);
            } else {
                $document = $this->salesDocuments->transform($made[$entry['madeFrom'] ?? 0], $entry['type'], $actor);
                if (isset($entry['date'])) {
                    $draft = SalesDocumentDraft::of($document);
                    $draft->date = $entry['date']->format('Y-m-d');
                    $this->salesDocuments->update($document, $this->salesDocuments->check($draft, $dossier, $entry['type']) ?? throw new \InvalidArgumentException(\sprintf('sales.yaml : documents[%d] : %s', $index, implode(' ', $draft->errors))), $actor);
                }
            }
            $this->entityManager->flush();
            if ($entry['validate']) {
                $refusals = $this->salesDocuments->validationRefusals($document);
                if ([] !== $refusals) {
                    throw new \InvalidArgumentException(\sprintf('sales.yaml : documents[%d] : %s', $index, implode(' ', $refusals)));
                }
                $this->salesDocuments->validate($document, $actor);
            }
            $made[$index] = $document;
        }
    }

    /** Its letterings, each an automatic lettering of one account: one that finds nothing installs nothing more. */
    /**
     * Its bank (step 2.6a): the assignment rules, then each statement imported through its CSV - the
     * account's movements of its days as the books carry them, but those the bank has not passed,
     * plus the bank's own lines -, the reconciled ones pointed entry by entry and validated.
     */
    private function installBank(Dossier $dossier, DemoBankFile $file, string $actor): void
    {
        foreach ($file->rules as $rule) {
            $this->bankRules->save($dossier, null, $rule['pattern'], $rule['account'], $rule['vatRate'], $rule['label'], $actor);
        }
        $closing = [];
        foreach ($file->statements as $index => $definition) {
            $journal = $this->journalRepository->findOneByCode($dossier, $definition['journal']) ?? throw new \InvalidArgumentException(\sprintf('bank.yaml : statements[%d] : journal %s inconnu.', $index, $definition['journal']));
            $account = $journal->getCounterpartAccount() ?? throw new \InvalidArgumentException(\sprintf('bank.yaml : statements[%d] : %s n’est pas un journal de banque.', $index, $definition['journal']));
            // Each line with the entry it comes from (none for the bank's own), in date order - the
            // bank's lines take their place among the books', which keep theirs.
            $rows = [];
            foreach ($this->ledgerReader->movements($account, $definition['from'], $definition['to'], EntryScope::WithDrafts) as $movement) {
                $entry = $movement->line->getEntry();
                if (JournalType::Opening === $entry->getJournal()->getType() || \in_array($entry->getDocument(), $definition['omit'], true)) {
                    continue;
                }
                $rows[] = [['date' => $entry->getEntryDate(), 'label' => mb_strtoupper($entry->getLabel()), 'amount' => $movement->line->getDebit()->minus($movement->line->getCredit())], $entry];
            }
            foreach ($definition['extra'] as $line) {
                $rows[] = [$line, null];
            }
            usort($rows, static fn (array $one, array $other): int => $one[0]['date'] <=> $other[0]['date']);
            $opening = $closing[$definition['journal']] ?? $this->bankStatements->booksBefore($journal, $definition['from']);
            $csv = StatementCsv::write(array_map(static fn (array $row): array => $row[0], $rows));
            $statement = $this->bankStatements->import($journal, $definition['number'], $definition['from'], $definition['to'], $opening, $csv, \sprintf('releve-%s-%02d.csv', mb_strtolower($definition['journal']), $definition['number']), $actor);
            $closing[$definition['journal']] = $statement->getClosingBalance();
            if ($definition['reconciled']) {
                foreach ($statement->getLines() as $position => $line) {
                    $entry = $rows[$position][1];
                    if (null !== $entry) {
                        $this->pointings->point($statement, [(int) $line->getId()], [(int) $entry->getId()], $actor);
                    }
                }
                $this->pointings->validate($statement, $actor);
            }
        }
        $this->entityManager->flush();
    }

    /** Its stock (step 2.5): each movement checked and recorded by StockMovements, the screen's door. */
    private function installStock(Dossier $dossier, DemoStockFile $file, string $actor): void
    {
        foreach ($file->movements as $index => $draft) {
            $fields = $this->stockMovements->check($draft, $dossier) ?? throw new \InvalidArgumentException(\sprintf('stock.yaml : movements[%d] : %s', $index, implode(' ', $draft->errors)));
            $this->stockMovements->record($dossier, $fields, $actor);
        }
    }

    private function installLetterings(Dossier $dossier, DemoLetteringFile $file, string $actor): void
    {
        foreach ($file->letterings as $index => $lettering) {
            $where = 'letterings.yaml : letterings['.$index.']';
            $account = $this->accounts->findOneByNumber($dossier, $lettering['account']) ?? throw new \InvalidArgumentException($where.' : le compte '.$lettering['account'].' n’est pas dans le plan.');
            $thirdParty = null === $lettering['thirdParty'] ? null : ($this->thirdParties->findOneByCode($dossier, $lettering['thirdParty']) ?? throw new \InvalidArgumentException($where.' : le tiers '.$lettering['thirdParty'].' n’existe pas.'));
            try {
                $this->lettering->letterProposals($dossier, LetteringKey::of($account, $thirdParty), $lettering['mode'], $actor);
            } catch (LetteringRefused $refused) {
                throw new \InvalidArgumentException($where.' : '.$refused->getMessage(), previous: $refused);
            }
        }
    }

    /** Its third parties, each checked as the form checks it: a fault names the code and installs nothing. */
    private function installThirdParties(Dossier $dossier, DemoThirdPartyFile $file): void
    {
        $codes = [];
        $auxiliaries = [];
        foreach ($file->drafts as $draft) {
            $fields = ThirdPartyRules::check(
                $draft,
                fn (string $number) => $this->accounts->findOneByNumber($dossier, $number),
                static fn (string $code): ?string => $codes[$code] ?? null,
                static fn (string $code): ?string => $auxiliaries[$code] ?? null,
            );
            if (null === $fields) {
                throw new \InvalidArgumentException(\sprintf('third_parties.yaml : %s : %s', $draft->code, implode(' ', $draft->errors)));
            }
            $codes[$draft->code] = $draft->name;
            if (null !== $fields->auxiliaryCode) {
                $auxiliaries[$fields->auxiliaryCode] = $draft->name;
            }
            $thirdParty = new ThirdParty($dossier, $draft->code, $fields->kind, $draft->name);
            ThirdPartySheet::write($thirdParty, $draft, $fields);
            $this->entityManager->persist($thirdParty);
        }
    }

    /**
     * Its entries, through the Ledger like any other: drafted one by one in the file's order (by
     * date), then those marked validated validated in that order, a batch at a time (numbered by
     * date, as a person validating each month would). One the Ledger refuses names the entry and
     * stops the install.
     */
    /**
     * Its entries, drafted then validated with those the payroll wrote (`$alsoValidate`), all by date - each
     * journal numbered in the order of its days.
     *
     * @param list<int> $alsoValidate
     */
    private function installEntries(int $dossierId, DemoEntryFile $file, string $actor, array $alsoValidate = []): void
    {
        $toValidate = $alsoValidate;
        foreach (array_chunk($file->entries, self::DRAFT_BATCH, true) as $batch) {
            $dossier = $this->fresh($dossierId);
            $this->context->within($dossier, function () use ($dossier, $batch, $actor, &$toValidate): void {
                foreach ($batch as $index => $entry) {
                    try {
                        $written = $this->ledger->draft($dossier, $entry['draft'], $actor, $entry['origin']);
                    } catch (EntryRefused $refusal) {
                        throw new \InvalidArgumentException(\sprintf('entries.yaml : entries[%d] (%s) : %s', $index, $entry['draft']->document, implode(' ', $refusal->errors)), 0, $refusal);
                    }
                    if ($entry['validated']) {
                        $toValidate[] = (int) $written->getId();
                    }
                }
            });
        }
        $dossier = $this->fresh($dossierId);
        $this->context->within($dossier, function () use (&$toValidate): void {
            $toValidate = array_map(static fn (Entry $entry): int => (int) $entry->getId(), $this->entries->findBy(['id' => $toValidate], ['entryDate' => 'ASC', 'id' => 'ASC']));
        });
        foreach (array_chunk($toValidate, self::VALIDATION_BATCH) as $batch) {
            $dossier = $this->fresh($dossierId);
            $this->context->within($dossier, function () use ($batch, $actor): void {
                try {
                    $this->ledger->validate($this->entries->findBy(['id' => $batch], ['entryDate' => 'ASC', 'id' => 'ASC']), $actor);
                } catch (EntryRefused $refusal) {
                    throw new \InvalidArgumentException('entries.yaml : '.implode(' ', $refusal->errors), 0, $refusal);
                }
            });
        }
    }

    /** The dossier read again in an entity manager cleared of what the previous batch loaded. */
    private function fresh(int $dossierId): Dossier
    {
        $this->entityManager->clear();

        return $this->entityManager->find(Dossier::class, $dossierId) ?? throw new \LogicException('The demonstration is being written.');
    }

    /** Its entry templates, each checked as the fiche checks it: a fault names the code and installs nothing. */
    private function installTemplates(Dossier $dossier, TemplateFile $file, string $actor): void
    {
        foreach ($file->drafts as $draft) {
            $fields = $this->templateSheet->check($draft, $dossier);
            if (null === $fields) {
                throw new \InvalidArgumentException(\sprintf('templates.yaml : %s : %s', $draft->code, implode(' ', $draft->errors)));
            }
            $this->templateSheet->create($dossier, $fields, $actor);
            // Flushed one by one: the next one's code is checked against the database.
            $this->entityManager->flush();
        }
        // Then those every dossier receives (constitution, capital, allocation of the result).
        $this->standardTemplates->install($dossier, $actor);
    }

    /** Its fixed assets (step 3.4), each checked as the fiche checks it: a fault names the code and installs nothing. */
    private function installFixedAssets(Dossier $dossier, DemoFixedAssetFile $file, string $actor): void
    {
        foreach ($file->drafts as $draft) {
            $values = $this->fixedAssetSheet->check($draft, $dossier);
            if (null === $values) {
                throw new \InvalidArgumentException(\sprintf('fixed_assets.yaml : %s : %s', $draft->code, implode(' ', $draft->errors)));
            }
            $this->fixedAssetSheet->create($dossier, $values, $actor);
            // Flushed one by one: the next one's code is checked against the database.
            $this->entityManager->flush();
        }
    }

    /**
     * Its subscriptions, each checked as the fiche checks it (a fault names the subscription and
     * installs nothing), then generated up to the dossier's date like « Générer jusqu'au ».
     */
    private function installSubscriptions(Dossier $dossier, DemoSubscriptionFile $file, string $actor, \DateTimeImmutable $at): void
    {
        foreach ($file->drafts as $draft) {
            $fields = $this->subscriptionSheet->check($draft, $dossier);
            if (null === $fields) {
                throw new \InvalidArgumentException(\sprintf('subscriptions.yaml : %s : %s', $draft->label, implode(' ', $draft->errors)));
            }
            $this->subscriptionSheet->create($dossier, $fields, $actor);
        }
        $this->entityManager->flush();
        $outcome = $this->subscriptionRun->generate($dossier, $dossier->getDossierDate(), $actor, $at);
        if ([] !== $outcome->faults) {
            throw new \InvalidArgumentException('subscriptions.yaml : '.implode(' ', $outcome->faults));
        }
    }

    /** The demonstration's own accounts over the reference copy, then its journals. */
    private function installChart(Dossier $dossier, DemoChartFile $file): void
    {
        $byNumber = [];
        foreach ($file->accounts as $entry) {
            $account = $this->accounts->findOneByNumber($dossier, $entry['number']);
            if (null === $account) {
                $account = new Account($dossier, $entry['number'], $entry['label'], AccountNumber::isLettrableByDefault($entry['number']), AccountNumber::isCollectiveByDefault($entry['number']), AccountOrigin::Created);
                $this->entityManager->persist($account);
            } else {
                $account->describe($entry['number'], $entry['label'], $account->isLettrable(), $account->isCollective());
            }
            $byNumber[$entry['number']] = $account;
        }
        foreach ($file->journals as $entry) {
            $counterpart = null;
            if (null !== $entry['counterpart']) {
                $counterpart = $byNumber[$entry['counterpart']] ?? $this->accounts->findOneByNumber($dossier, $entry['counterpart'])
                    ?? throw new \InvalidArgumentException(\sprintf('journals.yaml : %s : le compte %s n’est pas dans le plan.', $entry['code'], $entry['counterpart']));
            }
            $this->entityManager->persist(new Journal($dossier, $entry['code'], $entry['label'], $entry['type'], $counterpart));
        }
    }
}
