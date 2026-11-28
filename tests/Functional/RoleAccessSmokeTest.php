<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\FecImportJob;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Distribution;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\EmploymentTerms;
use App\Entity\Payroll\PayrollEstablishment;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\JournalType;
use App\Enum\Document\DocumentType;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\Sex;
use App\Enum\Payroll\WorkingTime;
use App\Enum\Sales\SalesDocumentType;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntrySubscriptionRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Payroll\PayrollBodyRepository;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Accounting\Subscription\SubscriptionDraft;
use App\Service\Accounting\Subscription\SubscriptionSheet;
use App\Service\Accounting\Template\TemplateDraft;
use App\Service\Accounting\Template\TemplateLineDraft;
use App\Service\Accounting\Template\TemplateSheet;
use App\Service\Document\Documents;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\Restore\RestorePoints;
use App\Service\Dossier\Restore\RestoreRunner;
use App\Service\Identity\SchoolClasses;
use App\Service\Inventory\ArticleDraft;
use App\Service\Inventory\ArticleSheet;
use App\Service\Payroll\Engine\PayslipAdjustments;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Payroll\Plan\PayrollPlanInstaller;
use App\Service\Regulatory\RegulatorySync;
use App\Service\Sales\SalesDocumentDraft;
use App\Service\Sales\SalesDocuments;
use App\Service\Shared\Money;
use App\Tests\Support\BuildsSchool;
use App\Tests\Support\RegulatoryFixtures;
use App\Tests\Support\SavesDossiers;
use App\Tests\Support\SignsIn;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Each main screen as each kind of account (README of the plan, § 6): the cheapest place to notice
 * that a screen gained or lost a role by accident. A refusal to a signed-in person is a 404; the
 * refused account (enzo.petit, outside the BTS CG) never gets a session, so it is sent to the
 * sign-in page like anybody else. Extend the table with every new screen.
 */
final class RoleAccessSmokeTest extends WebTestCase
{
    use SavesDossiers;
    use BuildsSchool;
    use SignsIn;

    /** login per role; `refused` is an account the entry rule turns away */
    private const array ACCOUNTS = [
        'student' => 'ines.morel',
        'teacher' => 'prof.durand',
        'admin' => 'prof.martin',
        'refused' => 'enzo.petit',
    ];

    /** @return iterable<string, array{string, array<string, int>}> url => expected status per role */
    public static function screens(): iterable
    {
        $public = ['student' => 200, 'teacher' => 200, 'admin' => 200, 'refused' => 200];
        yield 'À propos' => ['/about', $public];
        yield 'Santé' => ['/health', $public];
        yield 'Accueil' => ['/', ['student' => 302, 'teacher' => 302, 'admin' => 302, 'refused' => 302]];
        yield 'Mes dossiers' => ['/dossiers', ['student' => 200, 'teacher' => 404, 'admin' => 404, 'refused' => 302]];
        yield 'Accueil enseignant' => ['/teacher', ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302]];
        yield 'Enseignants de la filière' => ['/teacher/admin/teachers', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        yield 'Administrateurs' => ['/teacher/admin/administrators', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        yield 'Candidats administrateurs' => ['/teacher/admin/administrators/candidates?q=pr', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        yield 'Journal des connexions' => ['/teacher/admin/logins', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        yield 'Journal d’administration' => ['/teacher/admin/journal', ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302]];
        $teachers = ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302];
        $admins = ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302];
        yield 'Classes de l’année' => ['/teacher/classes', $teachers];
        yield 'À placer' => ['/teacher/classes/unplaced', $teachers];
        yield 'Sortis de la filière' => ['/teacher/classes/unplaced?show=departed', $teachers];
        yield 'Passage de classe' => ['/teacher/classes/promotion', $teachers];
        yield 'Années précédentes' => ['/teacher/classes/past', $teachers];
        yield 'Recherche d’étudiants' => ['/teacher/students/search?q=mo', $teachers];
        yield 'Années scolaires' => ['/teacher/admin/years', $admins];
        yield 'Classes (administration)' => ['/teacher/admin/classes', $admins];
        yield 'Correspondance avec l’annuaire' => ['/teacher/admin/directory', $admins];
        yield 'Recherche d’enseignants' => ['/teacher/admin/classes/teachers/search?q=pr', $admins];
        yield 'Paramètres réglementaires' => ['/teacher/regulatory', $teachers];
        yield 'Bibliothèque › Dossiers modèles' => ['/teacher/library', $teachers];
        yield 'Bibliothèque › Dossiers modèles, filtrés' => ['/teacher/library?shelf=mine&q=vasseur', $teachers];
        yield 'Bibliothèque › Partages' => ['/teacher/library/shares', $teachers];
        yield 'Bibliothèque › Distribution' => ['/teacher/library/distributions', $teachers];
        yield 'Nouveau dossier modèle' => ['/teacher/library/new', $teachers];
        yield 'Nouveau dossier modèle vide' => ['/teacher/library/new/empty', $teachers];
        yield 'Nouveau dossier modèle depuis un FEC' => ['/teacher/library/new/fec', $teachers];
        yield 'Pièces › Générateur' => ['/teacher/documents/generator', $teachers];
    }

    /** The sheet of a regulatory value and « Nouvelle valeur datée », on a value written for the test. */
    public function testTheScreensOfARegulatoryValue(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist(RegulatoryFixtures::fromFile('social.smic_hourly', '2026-01-01', '12.02', unit: 'EUR/heure'));
        $em->flush();

        $screens = [
            '/teacher/regulatory/social.smic_hourly' => ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302],
            '/teacher/regulatory/social.smic_hourly/new' => ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302],
        ];
        foreach ($screens as $url => $expected) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($expected, $actual, $url);
        }
    }

    /**
     * The screens of a class, a student and a passage, on a school built for the test: last year's
     * CG1 names this year's CG2 as its next class, and ines.morel is in CG1 this year.
     */
    public function testTheScreensOfAClassAndOfAStudent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, self::ACCOUNTS['admin']);
        $martin = $this->account($client, self::ACCOUNTS['admin']);
        \assert($martin instanceof User);
        [, $lastCg1] = $this->buildYear($client, $martin, 2025, false);
        [, $cg1, $cg2] = $this->buildYear($client, $martin, 2026, true);
        $lastCg1->setNextClass($cg2);
        $client->getContainer()->get('doctrine')->getManager()->flush();
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['student']);
        $ines = (int) $this->account($client, self::ACCOUNTS['student'])?->getId();

        $teachers = ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302];
        $admins = ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302];
        $screens = [
            '/teacher/classes/'.$cg1->getId() => $teachers,
            '/teacher/classes/'.$lastCg1->getId() => $teachers,
            '/teacher/classes/'.$cg1->getId().'/dossiers' => $teachers,
            '/teacher/classes/'.$lastCg1->getId().'/dossiers' => $teachers,
            '/teacher/students/'.$ines => $teachers,
            '/teacher/classes/promotion/'.$lastCg1->getId() => $teachers,
            '/teacher/classes/promotion/'.$lastCg1->getId().'/summary?step=2' => $teachers,
            '/teacher/classes/promotion/'.$cg1->getId() => ['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302],
            '/teacher/admin/classes/'.$cg1->getId() => $admins,
        ];
        foreach ($screens as $url => $expected) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($expected, $actual, $url);
        }
    }

    /**
     * A student's dossier (lot 1, steps 1.1, 1.2a and 1.2b): ines.morel holds it, prof.durand teaches her
     * class this year, prof.martin - an administrator - does not: no administrator enters a
     * student's dossier outside their classes. Its plan of accounts, journals and third parties included.
     */
    public function testTheScreensOfADossier(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, self::ACCOUNTS['teacher']);
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['admin']);
        $martin = $this->account($client, self::ACCOUNTS['admin']);
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $durand = $this->account($client, self::ACCOUNTS['teacher']);
        \assert($durand instanceof User);
        $client->getContainer()->get(SchoolClasses::class)->addTeacher($cg1, $durand, $martin, new \DateTimeImmutable('2026-11-27 10:00'));
        $client->getContainer()->get('doctrine')->getManager()->flush();
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['student']);
        $ines = $this->account($client, self::ACCOUNTS['student']);
        \assert($ines instanceof User);
        $saved = $this->savedDossier(DossierKind::Student, $ines, $client->getContainer()->get(SchoolYearRepository::class)->findCurrent());
        $this->installPlan($saved);
        $client->getContainer()->get(DossierContext::class)->within($saved, static function () use ($client, $saved): void {
            $bank = $client->getContainer()->get(AccountRepository::class)->findOneByNumber($saved, '512100');
            $client->getContainer()->get(EntityManagerInterface::class)->persist(new Journal($saved, 'BQ1', 'Crédit Coopératif', JournalType::Bank, $bank));
            $scierie = new ThirdParty($saved, 'F0001', ThirdPartyKind::Supplier, 'Scierie du Haut-Forez');
            $scierie->keepOn($client->getContainer()->get(AccountRepository::class)->findOneByNumber($saved, '401000'), '401SCIE');
            $client->getContainer()->get(EntityManagerInterface::class)->persist($scierie);
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
            $draft = new TemplateDraft();
            [$draft->code, $draft->label, $draft->journal] = ['VIRSCIE', 'Virement à la Scierie', 'BQ1'];
            $draft->lines = [new TemplateLineDraft('401SCIE', '', '', 'debit', 'typed')];
            $sheet = $client->getContainer()->get(TemplateSheet::class);
            $sheet->create($saved, $sheet->check($draft, $saved) ?? throw new \LogicException(implode(' ', $draft->errors)), 'test');
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
            $subscription = new SubscriptionDraft();
            [$subscription->label, $subscription->template, $subscription->day, $subscription->firstMonth, $subscription->occurrences, $subscription->pattern, $subscription->target] = ['Virement mensuel', 'VIRSCIE', '10', '2026-02-01', '6', 'VIR-{MM}', 'pending'];
            $subscriptions = $client->getContainer()->get(SubscriptionSheet::class);
            $subscriptions->create($saved, $subscriptions->check($subscription, $saved) ?? throw new \LogicException(implode(' ', $subscription->errors)), 'test');
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
        });
        $subscriptionId = $client->getContainer()->get(DossierContext::class)->within($saved, static fn (): ?int => $client->getContainer()->get(EntrySubscriptionRepository::class)->findByDossier($saved)[0]->getId());
        $bodyId = $client->getContainer()->get(DossierContext::class)->within($saved, static function () use ($client, $saved): ?int {
            $client->getContainer()->get(PayrollPlanInstaller::class)->installStandard($saved, 'test');
            $client->getContainer()->get(EntityManagerInterface::class)->flush();

            return $client->getContainer()->get(PayrollBodyRepository::class)->findByDossier($saved)[0]->getId();
        });
        $client->getContainer()->get(DossierContext::class)->within($saved, static function () use ($client, $saved, $ines): void {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $client->getContainer()->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
            $entityManager->persist(new PayrollEstablishment($saved, '8.69', '2.1', null, '151.67'));
            $paul = new Employee($saved, 'S001', 'Vasseur', 'Paul', Sex::Male, new \DateTimeImmutable('1988-02-11'), ContractType::Permanent, new \DateTimeImmutable('2012-09-03'));
            $entityManager->persist($paul);
            $entityManager->persist(new EmploymentTerms($paul, new \DateTimeImmutable('2012-09-03'), 'Chef d’atelier', '', true, WorkingTime::FullTime, '151.67', Money::cents(465_000)));
            $entityManager->flush();
            // A validated payslip, for its PDF (step 4.4b).
            $book = $client->getContainer()->get(PayslipBook::class);
            $payslip = $book->record($paul, $book->month($saved), PayslipElements::fromArray([]), PayslipAdjustments::none());
            $entityManager->flush();
            self::assertNull($book->validate($payslip, $ines));
            $entityManager->flush();
        });
        $monthPaid = $client->getContainer()->get(DossierContext::class)->within($saved, static fn (): string => $client->getContainer()->get(PayslipBook::class)->lastClosed($saved)?->getMonth()->format('Y-m') ?? $saved->getDossierDate()->format('Y-m'));
        $entry = $client->getContainer()->get(DossierContext::class)->within($saved, static fn (): Entry => $client->getContainer()->get(Ledger::class)->draft($saved, new EntryDraft('BQ1', new \DateTimeImmutable('2026-01-20'), 'VIR-SCIE', 'Virement Scierie', [
            EntryLineDraft::debit('401000', Money::cents(1_000), '', 'F0001'),
            EntryLineDraft::credit('512100', Money::cents(1_000)),
        ]), 'test'));
        $documentId = $client->getContainer()->get(DossierContext::class)->within($saved, static function () use ($client, $saved, $ines): ?int {
            $path = tempnam(sys_get_temp_dir(), 'piece');
            \assert(\is_string($path));
            file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
            $document = $client->getContainer()->get(Documents::class)->deposit($saved, new UploadedFile($path, 'FA-2601-0012.pdf', null, null, true), DocumentType::PurchaseInvoice, $ines);
            $client->getContainer()->get(EntityManagerInterface::class)->flush();

            return $document->getId();
        });
        $salesId = $client->getContainer()->get(DossierContext::class)->within($saved, static function () use ($client, $saved): ?int {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $customer = new ThirdParty($saved, 'C0019', ThirdPartyKind::Customer, 'Girard, Jean et Anne');
            $customer->keepOn($client->getContainer()->get(AccountRepository::class)->findOneByNumber($saved, '411000'), '411GIRA');
            $entityManager->persist($customer);
            $article = new ArticleDraft();
            [$article->code, $article->designation, $article->unit, $article->salePrice, $article->vatRate, $article->revenueAccount, $article->stockTracked] = ['PF001', 'Fenêtre chêne', 'pièce', '1380', '20', '701000', true];
            $articles = $client->getContainer()->get(ArticleSheet::class);
            $articles->create($saved, $articles->check($article, $saved) ?? throw new \LogicException(implode(' ', $article->errors)), 'test');
            $entityManager->flush();
            $quote = new SalesDocumentDraft();
            [$quote->customer, $quote->date] = ['C0019', '2026-01-20'];
            $quote->readLines([['article' => 'PF001', 'designation' => 'Fenêtre chêne', 'unit' => 'pièce', 'quantity' => '2', 'unitPrice' => '1380', 'discount' => '', 'rate' => '20']]);
            $sales = $client->getContainer()->get(SalesDocuments::class);
            $document = $sales->create($saved, SalesDocumentType::Quote, $sales->check($quote, $saved, SalesDocumentType::Quote) ?? throw new \LogicException(implode(' ', $quote->errors)), 'test');
            $entityManager->flush();

            return $document->getId();
        });
        [$orderId, $receiptId] = $client->getContainer()->get(DossierContext::class)->within($saved, static function () use ($client, $saved): array {
            $order = new SalesDocumentDraft();
            [$order->customer, $order->date] = ['F0001', $saved->getDossierDate()->format('Y-m-d')];
            $order->readLines([['article' => '', 'designation' => 'Chêne avivé 27 mm', 'unit' => 'm³', 'quantity' => '1,8', 'unitPrice' => '1150', 'discount' => '5', 'rate' => '20']]);
            $sales = $client->getContainer()->get(SalesDocuments::class);
            $document = $sales->create($saved, SalesDocumentType::PurchaseOrder, $sales->check($order, $saved, SalesDocumentType::PurchaseOrder) ?? throw new \LogicException(implode(' ', $order->errors)), 'test');
            $client->getContainer()->get(EntityManagerInterface::class)->flush();
            $sales->validate($document, 'test');
            $receipt = $sales->transform($document, SalesDocumentType::Receipt, 'test');
            $client->getContainer()->get(EntityManagerInterface::class)->flush();

            return [$document->getId(), $receipt->getId()];
        });
        $dossier = (int) $saved->getId();
        $point = $client->getContainer()->get(RestorePoints::class)->pose($saved, 'Avant validation', $ines, new \DateTimeImmutable('2026-11-28 10:00'));
        $pointId = (int) $point->getId();
        self::assertTrue($client->getContainer()->get(RestoreRunner::class)->preparePoint($pointId, new \DateTimeImmutable('2026-11-28 10:00')));

        $holders = ['student' => 200, 'teacher' => 200, 'admin' => 404, 'refused' => 302];
        $screens = [
            '', '/company',
            '/accounting/accounts', '/accounting/accounts?class=6&q=achats', '/accounting/accounts/601000',
            '/accounting/accounts/new', '/accounting/accounts/import', '/accounting/accounts/export.csv',
            '/accounting/accounts/search?q=51', '/accounting/journals', '/accounting/journals/new', '/accounting/journals/BQ1',
            '/accounting/third-parties', '/accounting/third-parties?kind=supplier&q=scie', '/accounting/third-parties/F0001',
            '/accounting/third-parties/new', '/accounting/third-parties/import', '/accounting/third-parties/export.csv',
            '/accounting/accounts/search?q=401S',
            '/accounting/entry', '/accounting/entry?journal=BQ1&period=2026-01-01', '/accounting/entries/'.$entry->getId(),
            '/accounting/templates', '/accounting/templates/new', '/accounting/templates/VIRSCIE',
            '/accounting/subscriptions', '/accounting/subscriptions/new', '/accounting/subscriptions/'.$subscriptionId,
            '/accounting/brouillard', '/accounting/brouillard/export.csv', '/accounting/consultation', '/accounting/consultation?account=401SCIE',
            '/accounting/consultation?account=512100&scope=validated', '/accounting/consultation/export.xlsx?account=512100',
            '/accounting/consultation/peek?account=401SCIE', '/accounting/entries', '/accounting/entries?journal=BQ1&period=2026-01-01',
            '/accounting/entries/export.csv?journal=BQ1', '/accounting/entries/'.$entry->getId().'?return=/d/'.$saved->getId().'/accounting/brouillard',
            '/accounting/editions/journals', '/accounting/editions/journals?journal=BQ1&scope=validated', '/accounting/editions/journals.pdf',
            '/accounting/editions/journals/export.xlsx', '/accounting/editions/general-ledger', '/accounting/editions/general-ledger?account_from=4&account_to=5',
            '/accounting/editions/general-ledger.pdf', '/accounting/editions/general-ledger/export.csv', '/accounting/editions/trial-balance',
            '/accounting/editions/trial-balance?from=2026-02-01', '/accounting/editions/trial-balance.pdf', '/accounting/editions/trial-balance/export.xlsx',
            '/accounting/editions/third-party-balance', '/accounting/editions/third-party-balance?kind=supplier', '/accounting/editions/third-party-balance.pdf',
            '/accounting/editions/third-party-balance/export.csv', '/accounting/editions/aged-balance', '/accounting/editions/aged-balance?kind=supplier',
            '/accounting/editions/aged-balance.pdf?kind=supplier', '/accounting/editions/aged-balance/export.xlsx',
            '/search', '/search?q=ach', '/search?q=401SCIE&here=/d/'.$saved->getId().'/accounting/brouillard',
            '/accounting/lettering', '/accounting/lettering?account=401SCIE', '/accounting/lettering?account=401SCIE&all=1&auto=amount',
            '/accounting/lettering?account=401000', '/accounting/opening', '/accounting/opening?exercise=2026',
            '/users', '/audit', '/audit?role=teacher&action=accounting.entry_validated&q=ACH', '/audit/export.csv', '/audit/export.xlsx',
            '/closing/fec', '/closing/fec?exercise=2026', '/closing/fec/download',
            '/payroll/items', '/payroll/items/new', '/payroll/items/2200', '/payroll/bodies', '/payroll/bodies/new',
            '/payroll/bodies/'.$bodyId, '/payroll/establishment',
            '/payroll/employees', '/payroll/employees?state=present&q=vas', '/payroll/employees/new', '/payroll/employees/S001',
            '/payroll/employees/S001?from=2012-09-03',
            '/payroll/employees/register', '/payroll/employees/register.pdf', '/payroll/employees/S001/formalities',
            '/payroll/employees/S001/contract.pdf', '/payroll/social-portal/dpae/S001', '/payroll/employees/S001/exit',
            '/payroll/payslips', '/payroll/payslips/S001', '/payroll/payslips/S001/payslip.pdf',
            '/payroll/employees/S001/payslips', '/payroll/employees/S001/payslips/'.$monthPaid.'.pdf',
            '/payroll/employees/S001/absences', '/payroll/employees/S001/year-to-date?year=2026',
            '/documents', '/documents?state=to_process&q=fa', '/documents/new', '/documents/'.$documentId, '/documents/'.$documentId.'/file',
            '/inventory/articles', '/inventory/articles?q=fen', '/inventory/articles/new', '/inventory/articles/PF001',
            '/inventory/stock', '/inventory/stock?view=below&at=2026-01-31', '/inventory/stock/export.csv', '/inventory/stock/movements',
            '/inventory/stock/movements?kind=entry&q=pf', '/inventory/stock/movements/new', '/inventory/stock/movements/new?article=PF001&kind=entry',
            '/inventory/stock/PF001', '/inventory/stock/PF001?at=2026-01-15',
            '/sales/customers', '/sales/customers?q=gir', '/sales/quote', '/sales/order', '/sales/invoice', '/sales/credit_note', '/sales/deposit',
            '/sales/quote/new', '/sales/invoice/new', '/sales/documents/'.$salesId, '/sales/documents/'.$salesId.'.pdf',
            '/purchases/suppliers', '/purchases/suppliers?q=scie', '/purchases/price_request', '/purchases/purchase_order', '/purchases/receipt',
            '/purchases/purchase_order/new', '/purchases/invoices', '/purchases/invoices?view=uninvoiced', '/purchases/documents/'.$orderId, '/purchases/documents/'.$receiptId, '/purchases/documents/'.$orderId.'.pdf',
            '/restore-points', '/restore-points/new', '/restore-points/'.$pointId.'/return', '/restoring/state',
        ];
        foreach (array_map(static fn (string $path): string => '/d/'.$dossier.$path, $screens) as $url) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($holders, $actual, $url);
        }

        // The guided entry: hidden from the student at the default level (« Avertissements »), open to their teacher.
        $actual = [];
        foreach (self::ACCOUNTS as $role => $login) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->submitLogin($client, $login);
            $client->request('GET', '/d/'.$dossier.'/accounting/entry/guided');
            $actual[$role] = $client->getResponse()->getStatusCode();
        }
        self::assertSame(['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302], $actual, '/accounting/entry/guided');

        // « Mode préparation » belongs to a template: on a student's pièce it is nobody's.
        $actual = [];
        foreach (self::ACCOUNTS as $role => $login) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->submitLogin($client, $login);
            $client->request('GET', '/d/'.$dossier.'/documents/'.$documentId.'/prepare');
            $actual[$role] = $client->getResponse()->getStatusCode();
        }
        self::assertSame(['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302], $actual, '/documents/{id}/prepare');

        // The waiting page sends on to the dashboard when no return is running.
        $actual = [];
        foreach (self::ACCOUNTS as $role => $login) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->submitLogin($client, $login);
            $client->request('GET', '/d/'.$dossier.'/restoring');
            $actual[$role] = $client->getResponse()->getStatusCode();
        }
        self::assertSame(['student' => 302, 'teacher' => 302, 'admin' => 404, 'refused' => 302], $actual, '/restoring');
    }

    /**
     * A template of the library: prof.durand's own (prof.martin, an administrator, is not let into
     * it), and one of the common library, which every teacher reads and administrators delete.
     */
    public function testTheScreensOfATemplate(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, self::ACCOUNTS['teacher']);
        $durand = $this->account($client, self::ACCOUNTS['teacher']);
        \assert($durand instanceof User);
        $ownTemplate = $this->savedDossier(DossierKind::Template, $durand);
        $own = (int) $ownTemplate->getId();
        $piece = $client->getContainer()->get(DossierContext::class)->within($ownTemplate, static function () use ($client, $ownTemplate, $durand): ?int {
            $path = tempnam(sys_get_temp_dir(), 'piece');
            \assert(\is_string($path));
            file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
            $document = $client->getContainer()->get(Documents::class)->deposit($ownTemplate, new UploadedFile($path, 'FA-2603-0187.pdf', null, null, true), DocumentType::PurchaseInvoice, $durand);
            $client->getContainer()->get(EntityManagerInterface::class)->flush();

            return $document->getId();
        });
        $common = (int) $this->savedDossier(DossierKind::Template, null)->getId();
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['admin']);
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['student']);
        $ines = $this->account($client, self::ACCOUNTS['student']);
        \assert($ines instanceof User);
        $student = (int) $this->savedDossier(DossierKind::Student, $ines)->getId();

        $table = [
            '/teacher/library/'.$own => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$own.'/delete' => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$own.'/colleagues?q=ma' => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$common => ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302],
            '/teacher/library/'.$common.'/delete' => ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302],
            '/teacher/library/'.$common.'/colleagues?q=ma' => ['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$student => ['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$own.'/distribute' => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/teacher/library/'.$common.'/distribute' => ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302],
            '/teacher/library/'.$student.'/distribute' => ['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302],
            '/teacher/documents/generator/'.$own => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/d/'.$own.'/documents/'.$piece.'/prepare' => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/d/'.$own.'/documents/'.$piece.'/prepare?slip=line.0.account' => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/teacher/documents/generator/'.$common => ['student' => 404, 'teacher' => 404, 'admin' => 200, 'refused' => 302],
            '/teacher/documents/generator/'.$student => ['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302],
        ];
        foreach ($table as $url => $expected) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($expected, $actual, $url);
        }
    }

    /**
     * A FEC import's progress page (step 1.10b): prof.durand's, whose template is still being
     * written - his alone; prof.martin, an administrator, gets a 404, and nobody opens the template.
     */
    public function testTheProgressPageOfAFecImport(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, self::ACCOUNTS['teacher']);
        $durand = $this->account($client, self::ACCOUNTS['teacher']);
        \assert($durand instanceof User);
        $template = $this->savedDossier(DossierKind::Template, $durand);
        $template->markImporting();
        $job = new FecImportJob($durand, $template, 'FEC.txt', "JournalCode\n", new \DateTimeImmutable('2026-01-01'), 120, 5, 12, 3, 0, 0, new \DateTimeImmutable('2026-11-28'));
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($job);
        $entityManager->flush();

        $table = [
            '/teacher/library/imports/'.$job->getId() => ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302],
            '/d/'.$template->getId() => ['student' => 404, 'teacher' => 404, 'admin' => 404, 'refused' => 302],
        ];
        foreach ($table as $url => $expected) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($expected, $actual, $url);
        }
    }

    /**
     * A distribution (step 1.8c): prof.durand's, of the common library's template to BTS CG 1 -
     * its steps and its pages are his alone; prof.martin, an administrator, gets a 404.
     */
    public function testTheScreensOfADistribution(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->submitLogin($client, self::ACCOUNTS['admin']);
        $martin = $this->account($client, self::ACCOUNTS['admin']);
        \assert($martin instanceof User);
        [, $cg1] = $this->buildYear($client, $martin, 2026, true);
        $this->signOut($client);
        $this->submitLogin($client, self::ACCOUNTS['teacher']);
        $durand = $this->account($client, self::ACCOUNTS['teacher']);
        \assert($durand instanceof User);
        $common = $this->savedDossier(DossierKind::Template, null);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $cg1 = $entityManager->find(SchoolClass::class, $cg1->getId()) ?? throw new \LogicException('Built.');
        $distribution = new Distribution($common, $durand, $cg1, DistributionMode::Individual, new \DateTimeImmutable('2026-03-15'), new \DateTimeImmutable('2026-11-28'));
        $entityManager->persist($distribution);
        $entityManager->flush();
        $id = (int) $distribution->getId();
        $template = (int) $common->getId();

        $author = ['student' => 404, 'teacher' => 200, 'admin' => 404, 'refused' => 302];
        $readers = ['student' => 404, 'teacher' => 200, 'admin' => 200, 'refused' => 302];
        $table = [
            '/teacher/library/'.$template.'/distribute?class='.$cg1->getId() => $readers,
            '/teacher/library/'.$template.'/distribute/how?class='.$cg1->getId().'&step=1' => $readers,
            '/teacher/library/'.$template.'/distribute/summary?class='.$cg1->getId().'&step=1' => $readers,
            '/teacher/library/distributions/'.$id => $author,
            '/teacher/library/distributions/'.$id.'/withdraw' => $author,
            // No ready dossier yet: the reset sends back to the distribution's page.
            '/teacher/library/distributions/'.$id.'/reset' => ['student' => 404, 'teacher' => 303, 'admin' => 404, 'refused' => 302],
        ];
        foreach ($table as $url => $expected) {
            $actual = [];
            foreach (self::ACCOUNTS as $role => $login) {
                self::ensureKernelShutdown();
                $client = static::createClient();
                $this->submitLogin($client, $login);
                $client->request('GET', $url);
                $actual[$role] = $client->getResponse()->getStatusCode();
            }
            self::assertSame($expected, $actual, $url);
        }
    }

    /** @param array<string, int> $expected */
    #[DataProvider('screens')]
    public function testEachRoleGetsWhatTheTableSays(string $url, array $expected): void
    {
        $actual = [];
        foreach (self::ACCOUNTS as $role => $login) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $this->submitLogin($client, $login);
            $client->request('GET', $url);
            $actual[$role] = $client->getResponse()->getStatusCode();
        }

        self::assertSame($expected, $actual);
    }

    public function testTheRefusedAccountIsSentToTheSignInPage(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, self::ACCOUNTS['refused']);
        $client->request('GET', '/teacher');

        self::assertResponseRedirects('/login');
    }
}
