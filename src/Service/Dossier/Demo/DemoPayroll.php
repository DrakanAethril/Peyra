<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollMonthClosure;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\JournalRepository;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Gateway\EntryProposal;
use App\Service\Audit\AuditTrail;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Payroll\Engine\PayslipAdjustments;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Payroll\Statement\PayrollEntries;
use App\Service\Payroll\Statement\PayrollPosting;
use App\Service\Payroll\Statement\PayrollStatementsReader;
use App\Service\Shared\FrenchFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The demonstration's payroll (review 4.7a of lot 4; fixtures/demo/payslips.yaml), written through the
 * payroll's own doors, month after month: every payslip of the month calculated from its elements (and
 * the rubriques removed by hand) and validated on the month's last day (PayslipBook), the month closed - each closing checked as « Clore le
 * mois » checks it -, then the month's entry and its payments (salaries, each body's contributions, the
 * withholding tax) built by the payroll (PayrollPosting, PayrollEntries), handed to the accounting gateway
 * in the bank journal of the salaries - into the brouillard: DemoInstaller validates them with the other
 * entries, by date, so that the numbering stays chronological. Nothing here calculates a payslip or an entry.
 */
final readonly class DemoPayroll
{
    /** The journal the salaries and the contributions go out by (operations-2026.md: BQ2, Banque Populaire). */
    private const string BANK = 'BQ2';

    public function __construct(
        private PayslipBook $book,
        private PayrollStatementsReader $statements,
        private PayrollPosting $posting,
        private AccountingGateway $gateway,
        private JournalRepository $journals,
        private AuditTrail $auditTrail,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Inside the dossier's context; flushes month by month.
     *
     * @return list<int> the entries the payroll wrote, in the brouillard
     */
    public function install(Dossier $dossier, DemoPayslipFile $file, string $actor): array
    {
        $written = [];
        foreach ($file->months as $entry) {
            $month = $entry['month'];
            $payDay = PayslipBook::payDay($month);
            foreach ($this->book->employeesOf($dossier, $month) as $employee) {
                $number = $employee->getRegistrationNumber();
                $payslip = $this->book->record($employee, $month, $entry['elements'][$number] ?? new PayslipElements(), new PayslipAdjustments($entry['removed'][$number] ?? []));
                $this->entityManager->flush();
                $refusal = $this->book->validate($payslip, $actor, null, $payDay);
                if (null !== $refusal) {
                    throw new \InvalidArgumentException(\sprintf('payslips.yaml : %s, %s : %s', $month->format('Y-m'), $employee->getRegistrationNumber(), $refusal));
                }
                $this->entityManager->flush();
            }
            $this->close($dossier, $month, $actor);
            if ($entry['post']) {
                array_push($written, ...$this->post($dossier, $month, $entry['salariesPaidOn'], $entry['contributionsPaidOn'], $actor));
            }
        }

        return $written;
    }

    /** « Clore le mois », for a month before the dossier's date: every payslip of the month validated. */
    private function close(Dossier $dossier, \DateTimeImmutable $month, string $actor): void
    {
        $payslips = $this->book->payslipsOf($dossier, $month);
        foreach ($this->book->employeesOf($dossier, $month) as $employee) {
            if (!($payslips[(int) $employee->getId()] ?? null)?->isValidated()) {
                throw new \LogicException(\sprintf('payslips.yaml : %s : le bulletin de %s n’est pas validé.', $month->format('Y-m'), $employee->getFullName()));
            }
        }
        $this->entityManager->persist(new PayrollMonthClosure($dossier, $month, PayslipBook::payDay($month)));
        $this->auditTrail->inDossier($dossier, AuditAction::PayrollMonthClosed, $actor, \sprintf('Paie %s close', FrenchFormat::ofPeriod($month)), ['month' => $month->format('Y-m')]);
        $this->entityManager->flush();
    }

    /**
     * The month's entry, then the salaries and the contributions on the days given.
     *
     * @return list<int>
     */
    private function post(Dossier $dossier, \DateTimeImmutable $month, ?\DateTimeImmutable $salariesOn, ?\DateTimeImmutable $contributionsOn, string $actor): array
    {
        $statements = $this->statements->of($dossier, $month);
        $written = [];
        $proposal = $this->posting->proposal($dossier, $statements) ?? throw new \LogicException('payslips.yaml : '.$month->format('Y-m').' ne donne aucune écriture.');
        $written[] = $this->record($dossier, $proposal, $actor);
        $bank = $this->journals->findOneByCode($dossier, self::BANK);
        $bankAccount = $bank?->getCounterpartAccount()?->getNumber() ?? throw new \LogicException('The demonstration pays its payroll from '.self::BANK.'.');
        foreach ($this->posting->payments($statements) as $payment) {
            $on = 'salaries' === $payment['key'] ? $salariesOn : $contributionsOn;
            if (null !== $on) {
                $written[] = $this->record($dossier, PayrollEntries::payment($payment['account'], $payment['amount'], self::BANK, $bankAccount, $on, $payment['document'], $payment['label']), $actor);
            }
        }
        $this->entityManager->flush();

        return array_map(static fn (Entry $entry): int => (int) $entry->getId(), $written);
    }

    private function record(Dossier $dossier, PieceProposal $proposal, string $actor): Entry
    {
        $check = $this->posting->check($dossier, $proposal);
        if (!$check->isWritable() || null === $check->grid) {
            throw new \InvalidArgumentException(\sprintf('payslips.yaml : l’écriture %s est refusée : %s', $proposal->document, implode(' ', $check->errors)));
        }

        return $this->gateway->record($dossier, new EntryProposal(AccountingModule::Payroll, $check->grid->draft, $proposal->document), $actor);
    }
}
