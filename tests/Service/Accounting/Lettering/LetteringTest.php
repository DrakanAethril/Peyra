<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Lettering;

use App\Doctrine\LedgerRefused;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Entity\Audit\AuditEvent;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Accounting\LetteringMode;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryLineRepository;
use App\Service\Accounting\Chart\AccountDraft;
use App\Service\Accounting\Chart\AccountSheet;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Lettering\Lettering;
use App\Service\Accounting\Lettering\LetteringBoard;
use App\Service\Accounting\Lettering\LetteringKey;
use App\Service\Accounting\Lettering\LetteringRefused;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Rights\DossierRights;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Lettering, the one door of the letters (step 1.6), on the Scierie du Haut-Forez (401SCIE) of a
 * dossier dated 15/03/2026: its opening invoice 2 400,00, January's invoice 4 158,12, two transfers
 * (2 400,00 and 2 000,00). Each gesture under the account's lock, dated with the dossier's date,
 * recorded in the audit trail; LedgerGuard and the account's fiche hold what goes around it.
 */
final class LetteringTest extends KernelTestCase
{
    use BuildsLedger;

    public function testASelectionThatBalancesIsLetteredOnTheDossiersDate(): void
    {
        [$dossier, $lines] = $this->scierie();
        $result = $this->lettering()->letter($dossier, [$lines['opening']->getId(), $lines['transfer']->getId()], 'test');

        self::assertSame('A', $result->letter);
        self::assertFalse($result->isPartial());
        self::assertSame("Lettrage A : 2 lignes soldées, 2\u{202F}400,00.", $result->message());
        self::assertSame('A', $lines['opening']->getLetter());
        self::assertSame('2026-03-15', $lines['transfer']->getLetteredOn()?->format('Y-m-d'));
        self::assertNull($lines['invoice']->getLetter());

        $audit = $this->audit(AuditAction::LinesLettered);
        self::assertCount(1, $audit);
        self::assertSame("Lettrage A sur 401SCIE : 2 lignes, 2\u{202F}400,00 au débit", $audit[0]->getSummary());
    }

    public function testAPartialLetteringIsCompletedLater(): void
    {
        [$dossier, $lines] = $this->scierie();
        $partial = $this->lettering()->letter($dossier, [$lines['invoice']->getId(), $lines['downPayment']->getId()], 'test');

        // 4 158,12 owed, 2 000,00 paid: 2 158,12 left.
        self::assertSame('a', $partial->letter);
        self::assertSame("Lettrage partiel a : 2 lignes, reste 2\u{202F}158,12 au crédit.", $partial->message());

        $this->entry($dossier, 'BQ', '2026-02-10', 'VIR-SCIE-2', ['401000/F0001 D 2158.12', '512000 C 2158.12'], true);
        $balance = $this->lineOf($dossier, 'VIR-SCIE-2');
        $done = $this->lettering()->letter($dossier, [$balance->getId(), $lines['invoice']->getId()], 'test');

        self::assertSame('A', $done->letter);
        self::assertSame(['A', 'A', 'A'], [$lines['invoice']->getLetter(), $lines['downPayment']->getLetter(), $balance->getLetter()]);
        self::assertStringContainsString('(complète a)', $this->audit(AuditAction::LinesLettered)[1]->getSummary());
    }

    public function testLevelsGAndBRefuseAPartialLettering(): void
    {
        [$dossier, $lines] = $this->scierie();
        $container = self::getContainer();
        $blocking = new class implements AssistanceLevels {
            public function for(Dossier $dossier): AssistanceLevel
            {
                return AssistanceLevel::Blocking;
            }
        };
        $lettering = new Lettering($container->get(EntityManagerInterface::class), $container->get(EntryLineRepository::class), $blocking, $container->get(AuditTrail::class), $container->get(DossierRights::class));

        $this->expectExceptionMessage("La sélection n’est pas soldée : écart de 2\u{202F}158,12 au crédit. Ajoutez les lignes qui la soldent.");
        $lettering->letter($dossier, [$lines['invoice']->getId(), $lines['downPayment']->getId()], 'test');
    }

    public function testWhatTheDoorRefuses(): void
    {
        [$dossier, $lines] = $this->scierie();
        $draft = $this->entry($dossier, 'BQ', '2026-02-12', 'VIR-DRAFT', ['401000/F0001 D 100.00', '512000 C 100.00']);
        $this->entry($dossier, 'VTE', '2026-02-01', 'FV-1', ['411000/C0019 D 2400.00', '701000 C 2400.00'], true);
        $girard = $this->lineOf($dossier, 'FV-1');
        $bank = $this->lineOf($dossier, 'VIR-SCIE-1', '512000');
        $opening = $this->lineOf($dossier, 'AN-2026-001', '512000');

        $refusals = [];
        foreach ([
            [$lines['opening']->getId(), $draft->getLines()[0]->getId()],
            [$lines['opening']->getId(), $girard->getId()],
            [$bank->getId(), $opening->getId()],
        ] as $ids) {
            try {
                $this->lettering()->letter($dossier, array_map('intval', $ids), 'test');
                $refusals[] = 'lettered';
            } catch (LetteringRefused $refused) {
                $refusals[] = $refused->getMessage();
            }
        }

        self::assertSame([
            'La ligne de la pièce VIR-DRAFT est au brouillard : validez l’écriture avant de la lettrer.',
            'Les lignes choisies sont sur des comptes ou des tiers différents : un lettrage tient sur un seul compte.',
            'Le compte 512000 n’est pas lettrable : cochez « Lettrable » sur sa fiche pour lettrer ses lignes.',
        ], $refusals);
        self::assertSame([], $this->audit(AuditAction::LinesLettered));
    }

    public function testAGroupIsUnletteredUnlessItsPeriodIsClosed(): void
    {
        [$dossier, $lines] = $this->scierie();
        $key = LetteringKey::ofLine($lines['opening']);
        $this->lettering()->letter($dossier, [$lines['opening']->getId(), $lines['transfer']->getId()], 'test');

        self::assertSame(2, $this->lettering()->unletter($dossier, $key, 'a', 'test'));
        self::assertNull($lines['opening']->getLetter());
        self::assertNull($lines['transfer']->getLetteredOn());
        self::assertSame('Lettrage A défait sur 401SCIE : 2 lignes', $this->audit(AuditAction::LinesUnlettered)[0]->getSummary());

        $this->lettering()->letter($dossier, [$lines['opening']->getId(), $lines['transfer']->getId()], 'test');
        self::assertSame('A', $lines['opening']->getLetter(), 'The letter freed is taken again.');
        $lines['opening']->getEntry()->getPeriod()->close();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->expectExceptionMessage('La période janvier 2026 est close : le lettrage A ne se défait plus.');
        $this->lettering()->unletter($dossier, $key, 'A', 'test');
    }

    public function testTheAutomaticLetteringByAmountAndByDocument(): void
    {
        [$dossier, $lines] = $this->scierie();
        $this->entry($dossier, 'BQ', '2026-01-25', 'FA-2601-0012', ['401000/F0001 D 2158.12', '512000 C 2158.12'], true);
        $key = LetteringKey::ofLine($lines['opening']);

        $proposed = $this->lettering()->proposals($key, LetteringMode::Amount);
        self::assertSame([[(int) $lines['opening']->getId(), (int) $lines['transfer']->getId()]], array_map(static fn (array $group): array => array_map(static fn (EntryLine $line): int => (int) $line->getId(), $group), $proposed));

        self::assertSame(['A'], $this->lettering()->letterProposals($dossier, $key, LetteringMode::Amount, 'test'));
        // The invoice FA-2601-0012 (4 158,12 C) and the payment under its number (2 158,12 D) do not balance.
        self::assertSame([], $this->lettering()->proposals($key, LetteringMode::Document));

        $this->expectExceptionMessage('Le lettrage automatique par pièce ne trouve rien à lettrer sur 401SCIE.');
        $this->lettering()->letterProposals($dossier, $key, LetteringMode::Document, 'test');
    }

    public function testTheBoardCountsWhatIsLeftToLetter(): void
    {
        [$dossier, $lines] = $this->scierie();
        $this->lettering()->letter($dossier, [$lines['opening']->getId(), $lines['transfer']->getId()], 'test');
        $this->lettering()->letter($dossier, [$lines['invoice']->getId(), $lines['downPayment']->getId()], 'test');
        $board = self::getContainer()->get(LetteringBoard::class);

        $rows = $board->rows();
        self::assertCount(1, $rows);
        self::assertSame('401SCIE', $rows[0]->key->code());
        self::assertSame([2, 1], [$rows[0]->openLines, $rows[0]->partialGroups]);
        self::assertSame(-215_812, $rows[0]->openBalance()->cents);

        $view = $board->view($dossier, $rows[0]->key, false);
        self::assertSame(-215_812, $view->balance->cents, '2 400 + 4 158,12 owed, 2 400 + 2 000 paid.');
        self::assertSame([1, 1, 2], [$view->letteredGroups, $view->partialGroups, \count($view->debits) + \count($view->credits)]);
        self::assertSame(4, \count($board->view($dossier, $rows[0]->key, true)->debits) + \count($board->view($dossier, $rows[0]->key, true)->credits));

        [$key, $error] = $board->key($dossier, '401000');
        self::assertNull($key);
        self::assertSame('Le compte 401000 est collectif : on lettre les lignes d’un tiers. Choisissez-le dans la liste.', $error);
        self::assertSame('Le compte 512000 n’est pas lettrable.', $board->key($dossier, '512000')[1]);
        self::assertSame('467000', $board->key($dossier, '467000')[0]?->code());
    }

    public function testADraftCarriesNoLetterAndALetteredAccountStaysLettrable(): void
    {
        [$dossier, $lines] = $this->scierie();
        $this->lettering()->letter($dossier, [$lines['opening']->getId(), $lines['transfer']->getId()], 'test');

        $account = self::getContainer()->get(AccountRepository::class)->findOneByNumber($dossier, '401000');
        self::assertNotNull($account);
        $draft = AccountDraft::of($account);
        $draft->lettrable = false;
        self::assertFalse(self::getContainer()->get(AccountSheet::class)->check($draft, $dossier, $account));
        self::assertSame(AccountSheet::LETTERED_REFUSAL, $draft->error('lettrable'));

        $entry = $this->entry($dossier, 'BQ', '2026-02-12', 'VIR-DRAFT', ['401000/F0001 D 100.00', '512000 C 100.00']);
        $entry->getLines()[0]->letteringSet('B', new \DateTimeImmutable('2026-03-15'));
        $this->expectException(LedgerRefused::class);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** @return array{Dossier, array{opening: EntryLine, invoice: EntryLine, transfer: EntryLine, downPayment: EntryLine}} */
    private function scierie(): array
    {
        $dossier = $this->ledgerDossier();
        $this->entry($dossier, 'AN', '2026-01-01', 'AN-2026-001', ['512000 D 10000.00', '401000/F0001 C 2400.00', '101300 C 7600.00'], true);
        $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['607000 D 3465.10', '445660 D 693.02', '401000/F0001 C 4158.12'], true);
        $this->entry($dossier, 'BQ', '2026-01-20', 'VIR-SCIE-1', ['401000/F0001 D 2400.00', '512000 C 2400.00'], true);
        $this->entry($dossier, 'BQ', '2026-01-22', 'ACOMPTE-SCIE', ['401000/F0001 D 2000.00', '512000 C 2000.00'], true);

        return [$dossier, [
            'opening' => $this->lineOf($dossier, 'AN-2026-001'),
            'invoice' => $this->lineOf($dossier, 'FA-2601-0012'),
            'transfer' => $this->lineOf($dossier, 'VIR-SCIE-1'),
            'downPayment' => $this->lineOf($dossier, 'ACOMPTE-SCIE'),
        ]];
    }

    /** The line of an entry, found by its document, on 401000 (or the account named). */
    private function lineOf(Dossier $dossier, string $document, string $account = ''): EntryLine
    {
        $entry = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Entry::class)->findOneBy(['document' => $document]);
        self::assertNotNull($entry);
        foreach ($entry->getLines() as $line) {
            if ('' === $account ? $line->getAccount()->isCollective() : $line->getAccount()->getNumber() === $account) {
                return $line;
            }
        }
        self::fail('No such line on '.$document);
    }

    private function lettering(): Lettering
    {
        return self::getContainer()->get(Lettering::class);
    }

    /** @return list<AuditEvent> */
    private function audit(AuditAction $action): array
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditEvent::class)->findBy(['action' => $action], ['id' => 'ASC']);
    }
}
