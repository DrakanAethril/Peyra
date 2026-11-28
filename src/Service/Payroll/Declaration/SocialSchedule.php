<?php

declare(strict_types=1);

namespace App\Service\Payroll\Declaration;

use App\Entity\Dossier\Dossier;
use App\Enum\Payroll\SocialDueState;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Statement\PayrollMonthStatements;
use App\Service\Payroll\Statement\PayrollPosting;
use App\Service\Payroll\Statement\PayrollStatementsReader;
use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Regulatory\RegulatoryReader;
use Brick\Math\BigDecimal;

/**
 * The échéancier social (step 4.6; design-ux.md § 3.2 « Déclarations »): what each month of pay with a
 * validated payslip leaves to pay to the bodies and to the tax office - the payments of Écritures de paie
 * but the net salaries (PayrollPosting::payments()) -, each at the DSN's due date (SocialDueDate, read at
 * the month's pay day, by the establishment's headcount) and where it stands at the dossier's date: paid
 * once its pièce stands in the books, whoever wrote it. Read inside the dossier's context.
 */
final readonly class SocialSchedule
{
    public function __construct(
        private PayrollStatementsReader $reader,
        private PayrollPosting $posting,
        private RegulatoryReader $regulatory,
        private PayrollEstablishmentRepository $establishments,
    ) {
    }

    /**
     * Every month's payments, by due date then in the month's order.
     *
     * @return list<SocialDue>
     *
     * @throws MissingRegulatoryValue
     */
    public function of(Dossier $dossier): array
    {
        $dues = [];
        foreach (array_reverse($this->reader->months($dossier)) as $month) {
            array_push($dues, ...$this->ofMonth($dossier, $this->reader->of($dossier, $month)));
        }

        return $dues;
    }

    /**
     * One month's payments to the bodies and to the tax office.
     *
     * @return list<SocialDue>
     *
     * @throws MissingRegulatoryValue
     */
    public function ofMonth(Dossier $dossier, PayrollMonthStatements $statements): array
    {
        $dueOn = $this->dueOn($dossier, $statements->month);
        $dues = [];
        foreach ($this->posting->payments($statements) as $payment) {
            if ('salaries' === $payment['key']) {
                continue;
            }
            $paid = [] !== $this->posting->standing($dossier, $payment['document']);
            $dues[] = new SocialDue($statements->month, $dueOn, $payment['key'], $payment['label'], $payment['account'], $payment['amount'], $payment['document'], SocialDueState::at($paid, $dueOn, $dossier->getDossierDate()));
        }

        return $dues;
    }

    /**
     * The due date of a month's DSN, for the establishment's headcount (none entered: below the threshold).
     *
     * @throws MissingRegulatoryValue
     */
    public function dueOn(Dossier $dossier, \DateTimeImmutable $month): \DateTimeImmutable
    {
        $headcount = $this->establishments->findOfDossier($dossier)?->getHeadcount();

        return SocialDueDate::from($this->regulatory->at(PayslipBook::payDay($month)))->of($month, null === $headcount ? BigDecimal::zero() : BigDecimal::of($headcount));
    }
}
