<?php

declare(strict_types=1);

namespace App\Tests\Command\Dossier;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Enum\Accounting\AccountOrigin;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierRepository;
use App\Service\Dossier\DossierContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** app:demo:install puts Menuiserie Vasseur SARL in the common library once, from fixtures/demo/: exercises, plan, journals. */
final class DemoInstallCommandTest extends KernelTestCase
{
    public function testInstallsTheTemplateOnceWithItsExercises(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:demo:install'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Menuiserie Vasseur SARL installé dans la bibliothèque commune', $tester->getDisplay());
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('déjà installé', $tester->getDisplay());

        $templates = self::getContainer()->get(DossierRepository::class)->findCommonTemplates();
        self::assertCount(1, $templates);
        $vasseur = $templates[0];
        self::assertSame(DossierKind::Template, $vasseur->getKind());
        self::assertNull($vasseur->getOwner());
        self::assertSame('2026-01-01', $vasseur->getDossierDate()->format('Y-m-d'));
        self::assertSame('99941273600018', $vasseur->getCompany()->getSiret());
        self::assertSame(8_000_000, $vasseur->getCompany()->getCapital()->cents);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$exercises, $closedPeriods] = self::getContainer()->get(DossierContext::class)->within($vasseur, static fn (): array => [
            $entityManager->getRepository(Exercise::class)->findBy([], ['startsOn' => 'ASC']),
            $entityManager->getRepository(Period::class)->count(['state' => 'closed']),
        ]);
        self::assertSame(['2025', '2026'], array_map(static fn (Exercise $exercise): string => $exercise->getCode(), $exercises));
        self::assertSame([true, false], array_map(static fn (Exercise $exercise): bool => $exercise->isClosed(), $exercises));
        self::assertSame(12, $closedPeriods);

        // Its plan: the PCG 2026 (the exercise it starts in opens in 2026) and its own accounts, its six journals.
        self::assertSame('pcg-2026', $vasseur->getChartCode());
        [$accounts, $journals] = self::getContainer()->get(DossierContext::class)->within($vasseur, static fn (): array => [
            $entityManager->getRepository(Account::class)->findBy([], ['number' => 'ASC']),
            $entityManager->getRepository(Journal::class)->findBy([], ['code' => 'ASC']),
        ]);
        $account = static function (string $number) use ($accounts): ?Account {
            foreach ($accounts as $account) {
                if ($account->getNumber() === $number) {
                    return $account;
                }
            }

            return null;
        };
        $bank = $account('512100');
        self::assertNotNull($bank);
        self::assertSame('Crédit Coopératif', $bank->getLabel());
        self::assertSame(AccountOrigin::Reference, $bank->getOrigin(), 'Le compte du plan garde son origine sous son nouvel intitulé.');
        self::assertSame(AccountOrigin::Created, $account('606110')?->getOrigin());
        self::assertSame('Emprunt Banque Populaire 2023 (fourgon, plaqueuse)', $account('164100')?->getLabel());
        self::assertTrue($account('401000')?->isCollective());
        self::assertSame(
            ['ACH Achats', 'AN À-nouveaux', 'BQ1 Crédit Coopératif 512100', 'BQ2 Banque Populaire 512200', 'OD Opérations diverses', 'VTE Ventes'],
            array_map(static fn (Journal $j): string => trim($j->getCode().' '.$j->getLabel().' '.$j->getCounterpartAccount()?->getNumber()), $journals),
        );
    }
}
