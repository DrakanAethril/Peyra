<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Exit;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\ExitReason;
use App\Service\Payroll\Exit\ExitDraft;
use App\Service\Payroll\Exit\ExitRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What makes an exit (step 4.2c): Lucie Perrin's resignation (CDI since 01/09/2021, raise on
 * 01/01/2026), Yanis Chabert's CDD (20/03/2026 to 30/09/2026) at its term or broken early.
 */
final class ExitRulesTest extends TestCase
{
    public function testAResignationNotifiedThenLeft(): void
    {
        $fields = ExitRules::check($this->draft('resignation', '2026-04-15', '2026-05-31'), ContractType::Permanent, self::day('2021-09-01'), null, self::day('2026-01-01'));

        self::assertNotNull($fields);
        self::assertSame([ExitReason::Resignation, '2026-04-15', '2026-05-31'], [$fields->reason, $fields->notifiedOn?->format('Y-m-d'), $fields->exitOn->format('Y-m-d')]);
    }

    public function testAFixedTermContractEndsOnItsTermWithoutANotification(): void
    {
        $draft = $this->draft('fixed_term_end', '2026-09-01', '2026-09-30');
        $fields = ExitRules::check($draft, ContractType::FixedTerm, self::day('2026-03-20'), self::day('2026-09-30'), self::day('2026-03-20'));

        self::assertNotNull($fields);
        self::assertNull($fields->notifiedOn, 'A term is not notified: the day typed is dropped.');
        self::assertSame('', $draft->notifiedOn);
    }

    /** @return iterable<string, array{string, string, string, ContractType, string, ?string, string, array<string, string>}> */
    public static function refusals(): iterable
    {
        yield 'no reason, no day' => ['', '', '', ContractType::Permanent, '2021-09-01', null, '2021-09-01', [
            'reason' => 'Choisissez le motif de la sortie.',
            'exitOn' => 'Indiquez le dernier jour du contrat.',
        ]];
        yield 'a CDI does not reach a term' => ['fixed_term_end', '', '2026-05-31', ContractType::Permanent, '2021-09-01', null, '2021-09-01', [
            'reason' => '« Fin du contrat à durée déterminée » ne met pas fin à un CDI.',
        ]];
        yield 'a CDD is not broken by a conventional termination' => ['conventional_termination', '2026-04-01', '2026-05-15', ContractType::FixedTerm, '2026-03-20', '2026-09-30', '2026-03-20', [
            'reason' => '« Rupture conventionnelle » ne met pas fin à un CDD.',
        ]];
        yield 'a resignation is notified' => ['resignation', '', '2026-05-31', ContractType::Permanent, '2021-09-01', null, '2021-09-01', [
            'notifiedOn' => 'Indiquez le jour où la sortie a été notifiée.',
        ]];
        yield 'the notification after the last day' => ['resignation', '2026-06-02', '2026-05-31', ContractType::Permanent, '2021-09-01', null, '2021-09-01', [
            'notifiedOn' => 'La notification précède le dernier jour du contrat.',
        ]];
        yield 'the notification before the hire' => ['trial_period', '2026-03-10', '2026-03-25', ContractType::FixedTerm, '2026-03-20', '2026-09-30', '2026-03-20', [
            'notifiedOn' => 'La notification suit l’entrée du 20/03/2026.',
        ]];
        yield 'the last day before the hire' => ['trial_period', '2026-03-10', '2026-03-15', ContractType::Permanent, '2026-03-20', null, '2026-03-20', [
            'exitOn' => 'Le dernier jour du contrat suit l’entrée du 20/03/2026.',
            'notifiedOn' => 'La notification suit l’entrée du 20/03/2026.',
        ]];
        yield 'terms starting after the last day' => ['resignation', '2026-04-15', '2026-05-31', ContractType::Permanent, '2021-09-01', null, '2026-06-01', [
            'exitOn' => 'Les conditions du contrat changent le 01/06/2026, après ce dernier jour.',
        ]];
        yield 'a term on another day' => ['fixed_term_end', '', '2026-08-31', ContractType::FixedTerm, '2026-03-20', '2026-09-30', '2026-03-20', [
            'exitOn' => 'Le contrat prend fin à son terme, le 30/09/2026 ; une sortie à une autre date est une rupture anticipée.',
        ]];
        yield 'an early end on the term' => ['early_termination', '2026-08-01', '2026-09-30', ContractType::FixedTerm, '2026-03-20', '2026-09-30', '2026-03-20', [
            'exitOn' => 'Une rupture anticipée précède le terme du contrat, le 30/09/2026.',
        ]];
        yield 'a day that does not exist' => ['resignation', '2026-02-30', '2026-05-31', ContractType::Permanent, '2021-09-01', null, '2021-09-01', [
            'notifiedOn' => 'Cette date n’existe pas.',
        ]];
    }

    /** @param array<string, string> $errors */
    #[DataProvider('refusals')]
    public function testRefusals(string $reason, string $notifiedOn, string $exitOn, ContractType $contract, string $hiredOn, ?string $endsOn, string $latestTerms, array $errors): void
    {
        $draft = $this->draft($reason, $notifiedOn, $exitOn);

        self::assertNull(ExitRules::check($draft, $contract, self::day($hiredOn), null === $endsOn ? null : self::day($endsOn), self::day($latestTerms)));
        self::assertEqualsCanonicalizing($errors, $draft->errors);
    }

    public function testEveryContractMayBeBrokenDuringItsTrialPeriodAndOnlyACdiByAResignation(): void
    {
        foreach (ContractType::cases() as $contract) {
            self::assertTrue(ExitReason::TrialPeriod->allows($contract));
        }
        self::assertSame(
            [ExitReason::Resignation, ExitReason::PersonalDismissal, ExitReason::EconomicDismissal, ExitReason::ConventionalTermination, ExitReason::Retirement, ExitReason::TrialPeriod],
            array_values(array_filter(ExitReason::cases(), static fn (ExitReason $r): bool => $r->allows(ContractType::Permanent))),
        );
        self::assertSame(
            [ExitReason::ApprenticeshipEnd, ExitReason::EarlyTermination, ExitReason::TrialPeriod],
            array_values(array_filter(ExitReason::cases(), static fn (ExitReason $r): bool => $r->allows(ContractType::Apprenticeship))),
        );
    }

    private function draft(string $reason, string $notifiedOn, string $exitOn): ExitDraft
    {
        $draft = new ExitDraft();
        [$draft->reason, $draft->notifiedOn, $draft->exitOn] = [$reason, $notifiedOn, $exitOn];

        return $draft;
    }

    private static function day(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }
}
