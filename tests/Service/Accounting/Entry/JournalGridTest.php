<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Entry;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\EntryState;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Accounting\PendingEntryRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Accounting\Entry\GridContext;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Accounting\Entry\JournalGrid;
use App\Service\Accounting\Entry\PendingStale;
use App\Tests\Support\BuildsLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The grid on the server: the pending work kept as typed (never an Entry while it does not
 * balance), F10 writing to the brouillard what balances, Ctrl Entrée validating the month. The
 * purchase is FA-2601-0012: 3 465,10 + 693,02 = 4 158,12 (by hand).
 */
final class JournalGridTest extends KernelTestCase
{
    use BuildsLedger;

    private const string NOW = '2026-11-27 10:00';

    public function testAnUnbalancedEntryIsKeptPendingAndNeverWritten(): void
    {
        [$dossier, $context, $me] = $this->grid('ACH');
        $answer = $this->service()->keep($context, $me, [$this->purchase('k1', 300_000)], 0, new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $answer['revision']);
        self::assertSame('L’écriture n’est pas équilibrée : écart de 1 158,12 au crédit.', str_replace("\u{202F}", ' ', $answer['checks']['k1']['errors']['lines']));
        self::assertSame([], $this->entries()->findAll(), 'Une saisie qui ne s’équilibre pas n’est pas une écriture.');
        $pending = static::getContainer()->get(PendingEntryRepository::class)->findAll();
        self::assertCount(1, $pending);
        self::assertSame('FA-2601-0012', $pending[0]->getGroups()[0]->document);
        self::assertSame(self::NOW, $pending[0]->getSavedAt()->format('Y-m-d H:i'));
    }

    public function testASaveFromAnOlderRevisionIsRefused(): void
    {
        [, $context, $me] = $this->grid('ACH');
        $this->service()->keep($context, $me, [$this->purchase('k1', 300_000)], 0, new \DateTimeImmutable(self::NOW));

        $this->expectException(PendingStale::class);
        $this->service()->keep($context, $me, [$this->purchase('k1', 415_812)], 0, new \DateTimeImmutable(self::NOW));
    }

    public function testF10WritesWhatBalancesAndLeavesTheRestPending(): void
    {
        [$dossier, $context, $me] = $this->grid('ACH');
        $outcome = $this->service()->save($context, $me, [$this->purchase('k1', 415_812), $this->purchase('k2', 400_000, 'FA-2601-0099')], 0, new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $outcome->saved);
        self::assertSame(1, $outcome->left);
        self::assertStringStartsWith('1 écriture enregistrée au brouillard. 1 écriture reste en attente : l’écriture n’est pas équilibrée', $outcome->message());
        $entries = $this->entries()->findAll();
        self::assertCount(1, $entries);
        self::assertSame(EntryState::Draft, $entries[0]->getState());
        self::assertSame('Scierie FA 0012', $entries[0]->getLabel());
        self::assertSame('2026-02-07', $entries[0]->getLines()[2]->getDueDate()?->format('Y-m-d'), 'L’échéance vient des conditions de la Scierie : 30 jours.');
        $left = static::getContainer()->get(PendingEntryRepository::class)->findAll()[0]->getGroups();
        self::assertSame(['k2'], array_map(static fn (GridGroup $group): string => $group->key, $left));
    }

    public function testAGroupNamingADraftRewritesItAndAGroupWithoutLinesDeletesIt(): void
    {
        [$dossier, $context, $me] = $this->grid('ACH');
        $first = $this->entry($dossier, 'ACH', '2026-01-08', 'FA-1', ['607000 D 100.00', '401000/F0001 C 100.00']);
        $second = $this->entry($dossier, 'ACH', '2026-01-09', 'FA-2', ['607000 D 50.00', '401000/F0001 C 50.00']);

        $outcome = $this->service()->save($context, $me, [
            new GridGroup('e'.$first->getId(), $first->getId(), '2026-01-10', 'FA-1', [new GridLine('607000', '', 'Corrigée', 12_000), new GridLine('401SCIE', '', 'Corrigée', 0, 12_000)]),
            new GridGroup('e'.$second->getId(), $second->getId(), '2026-01-09', 'FA-2', []),
        ], 0, new \DateTimeImmutable(self::NOW));

        self::assertSame([1, 1, 0], [$outcome->saved, $outcome->discarded, $outcome->left]);
        $entries = $this->entries()->findAll();
        self::assertCount(1, $entries);
        self::assertSame($first->getId(), $entries[0]->getId());
        self::assertSame('2026-01-10', $entries[0]->getEntryDate()->format('Y-m-d'));
        self::assertSame(12_000, $entries[0]->totalDebit()->cents);
    }

    public function testABankEntryGetsItsCounterpartAndTheGridShowsItWithout(): void
    {
        [$dossier, $context, $me] = $this->grid('BQ');
        $this->service()->save($context, $me, [new GridGroup('k1', null, '2026-01-20', 'VIR-SCIE', [new GridLine('401SCIE', '', 'Virement Scierie', 40_000)])], 0, new \DateTimeImmutable(self::NOW));

        $entry = $this->entries()->findAll()[0];
        self::assertSame(['401000', '512000'], array_map(static fn ($line): string => $line->getAccount()->getNumber(), $entry->getLines()));
        self::assertSame(40_000, $entry->getLines()[1]->getCredit()->cents);
        $groups = $this->service()->groups($this->context($dossier, 'BQ'), $me);
        self::assertCount(1, $groups[0]['lines']);
        self::assertSame('401SCIE', self::dig($groups, 0, 'lines', 0, 'account'));
    }

    public function testValidationNumbersTheMonthButNotADraftBeingChanged(): void
    {
        [$dossier, $context, $me] = $this->grid('ACH');
        $kept = $this->entry($dossier, 'ACH', '2026-01-05', 'FA-0', ['607000 D 10.00', '401000/F0001 C 10.00']);
        $changing = $this->entry($dossier, 'ACH', '2026-01-06', 'FA-00', ['607000 D 20.00', '401000/F0001 C 20.00']);

        $outcome = $this->service()->validate($context, $me, [
            $this->purchase('k1', 415_812),
            new GridGroup('e'.$changing->getId(), $changing->getId(), '2026-01-06', 'FA-00', [new GridLine('607000', '', 'x', 2_000)]),
        ], 0, new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $outcome->held);
        self::assertCount(2, $outcome->validated ?? []);
        self::assertStringStartsWith('2 écritures validées : ACH-2026-00001 à ACH-2026-00002.', $outcome->message());
        static::getContainer()->get(EntityManagerInterface::class)->refresh($kept);
        self::assertSame('ACH-2026-00001', $kept->getNumberLabel(), 'Numérotées par date : le 05/01 avant le 08/01.');
        self::assertFalse($this->entries()->find($changing->getId())?->isValidated());
    }

    public function testTheGridListsTheMonthsEntriesThenThePendingWork(): void
    {
        [$dossier, $context, $me] = $this->grid('ACH');
        $validated = $this->entry($dossier, 'ACH', '2026-01-05', 'FA-0', ['607000 D 10.00', '401000/F0001 C 10.00'], true);
        $this->entry($dossier, 'ACH', '2026-02-05', 'FA-F', ['607000 D 10.00', '401000/F0001 C 10.00']);
        $this->service()->keep($context, $me, [$this->purchase('k1', 300_000)], 0, new \DateTimeImmutable(self::NOW));

        $groups = $this->service()->groups($this->context($dossier, 'ACH'), $me);

        self::assertSame(['validated', 'new'], array_column($groups, 'state'), 'Février n’est pas dans la grille de janvier.');
        self::assertSame('ACH-2026-00001', $groups[0]['number']);
        self::assertSame($validated->getId(), $groups[0]['entry']);
        self::assertSame('401SCIE', self::dig($groups, 1, 'lines', 2, 'account'));
        self::assertCount(1, $this->service()->groups($this->context($dossier, 'ACH'), null), 'Celui qui lit ne voit pas la saisie en attente des autres dans la grille.');
    }

    private function purchase(string $key, int $credit, string $document = 'FA-2601-0012'): GridGroup
    {
        return new GridGroup($key, null, '2026-01-08', $document, [
            new GridLine('607000', '', 'Scierie FA 0012', 346_510),
            new GridLine('445660', '', 'Scierie FA 0012', 69_302),
            new GridLine('401SCIE', '', 'Scierie FA 0012', 0, $credit),
        ]);
    }

    /** @return array{Dossier, GridContext, User} */
    private function grid(string $journal): array
    {
        $dossier = $this->ledgerDossier();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $me = new User('uidNumber:9001', 'lucas.bernard', 'Lucas Bernard', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($me);
        $entityManager->flush();

        return [$dossier, $this->context($dossier, $journal), $me];
    }

    private function context(Dossier $dossier, string $journal): GridContext
    {
        $journalEntity = static::getContainer()->get(JournalRepository::class)->findOneByCode($dossier, $journal) ?? throw new \LogicException();
        $period = static::getContainer()->get(PeriodRepository::class)->findAt($dossier, new \DateTimeImmutable('2026-01-15')) ?? throw new \LogicException();

        return $this->service()->context($dossier, $journalEntity, $period);
    }

    /** A value deep in a decoded answer, or null where the path stops. */
    private static function dig(mixed $data, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    private function service(): JournalGrid
    {
        return static::getContainer()->get(JournalGrid::class);
    }

    private function entries(): EntryRepository
    {
        return static::getContainer()->get(EntryRepository::class);
    }
}
