<?php

declare(strict_types=1);

namespace App\Tests\Service\Document\Preparation;

use App\Entity\Audit\AuditEvent;
use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Audit\AuditAction;
use App\Enum\Document\DocumentState;
use App\Enum\Document\DocumentType;
use App\Enum\Document\SlipPlace;
use App\Enum\Dossier\CopyKind;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Document\DocumentRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Document\Documents;
use App\Service\Document\Preparation\PiecePreparation;
use App\Service\Document\Processing\PieceProcessing;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Document\VatLine;
use App\Service\Dossier\Copy\DossierCopier;
use App\Service\Dossier\DossierContext;
use App\Service\Shared\Money;
use App\Service\Shared\Storage\DossierFiles;
use App\Tests\Support\BuildsLedger;
use App\Tests\Support\DossierFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PiecePreparation (step 2.2b) on a template's pièce, the Scierie's FA-2603-0187 (2 155,00 HT,
 * 431,00 VAT at 20 %, 2 586,00 TTC; proposed 601000 / 445660 / 401SCIE): the errors written where
 * the student reads and the right values kept apart, taken back; the right account of a line; a
 * doublon arriving twice and withdrawn, its file with it; a copy of the template carrying the answer
 * key and the doublon's link; nothing on a student's dossier.
 */
final class PiecePreparationTest extends KernelTestCase
{
    use BuildsLedger;

    public function testAnErrorIsWrittenWhereTheStudentReadsAndTakenBack(): void
    {
        [$dossier, $document] = $this->template();
        $preparation = static::getContainer()->get(PiecePreparation::class);

        self::assertNull($preparation->slip($document, 'vat.0.rate', SlipPlace::Both, '10', $this->teacher()));
        self::assertNull($preparation->slip($document, 'thirdParty', SlipPlace::Reading, 'F0009', $this->teacher()));
        self::assertSame('Une erreur est déjà glissée sur cette valeur : retirez-la d’abord.', $preparation->slip($document, 'thirdParty', SlipPlace::Reading, 'F0001', $this->teacher()));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        // What the student reads: the rate 10, the Faure, the VAT at 10 % in the entry (215,50) and the supplier's line moved (2 370,50).
        self::assertSame('10', $document->getVatLines()[0]->rate);
        self::assertSame('F0009', $document->getThirdParty()?->getCode());
        $proposal = PieceProposal::fromArray($document->getProposal() ?? []);
        self::assertSame([[215_500, 0], [21_550, 0], [0, 237_050]], array_map(static fn (GridLine $line): array => [$line->debit, $line->credit], $proposal->lines));
        self::assertSame(PieceProcessing::basis($document), $proposal->basis, 'No banner tells the student the read data changed.');
        // The answer key keeps the right values and the two errors.
        $key = $preparation->answerKey($document);
        self::assertSame('20', $key->reading->vatLines[0]->rate);
        self::assertSame('F0001', $key->reading->thirdParty);
        self::assertSame(['vat.0.rate', 'thirdParty'], array_map(static fn ($error): string => $error->target, $key->errors));
        self::assertSame(2, $preparation->view($document)->count());

        // An account of the right proposal does not change while an error shows in the entry.
        self::assertSame('Retirez d’abord les erreurs glissées dans l’écriture : elles s’appuient sur ses lignes.', $preparation->fixAccount($document, 0, '607000', $this->teacher()));

        self::assertNull($preparation->remove($document, 'vat.0.rate', $this->teacher()));
        self::assertNull($preparation->remove($document, 'thirdParty', $this->teacher()));
        self::assertSame('20', $document->getVatLines()[0]->rate);
        self::assertSame('F0001', $document->getThirdParty()->getCode());
        self::assertSame([[215_500, 0], [43_100, 0], [0, 258_600]], array_map(static fn (GridLine $line): array => [$line->debit, $line->credit], PieceProposal::fromArray($document->getProposal() ?? [])->lines));
        self::assertNull($document->getAnswerKey(), 'No error, no answer key.');

        // Now the right account may change.
        self::assertNull($preparation->fixAccount($document, 0, '607000', $this->teacher()));
        self::assertSame('607000', PieceProposal::fromArray($document->getProposal() ?? [])->lines[0]->account);
        self::assertSame('Choisissez un compte du plan du dossier.', $preparation->fixAccount($document, 0, '609999', $this->teacher()));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame(1, $this->trailCount($dossier, AuditAction::DocumentProposalFixed));
        self::assertSame(2, $this->trailCount($dossier, AuditAction::DocumentErrorSlipped));
        self::assertSame(2, $this->trailCount($dossier, AuditAction::DocumentErrorRemoved));
    }

    public function testADoublonArrivesTwiceAsThePieceReallyIsThenIsWithdrawn(): void
    {
        [$dossier, $document] = $this->template();
        $container = static::getContainer();
        $preparation = $container->get(PiecePreparation::class);
        $preparation->slip($document, 'totalIncludingTax', SlipPlace::Both, '268500', $this->teacher());
        self::assertNull($preparation->duplicate($document, $this->teacher()));
        $container->get(EntityManagerInterface::class)->flush();

        [$second] = $preparation->duplicatesOf($document);
        self::assertSame($document, $second->getDuplicateOf());
        self::assertSame(DocumentState::ToProcess, $second->getState());
        self::assertSame(258_600, $second->getTotalIncludingTax()?->cents, 'The second arrival carries none of the first one’s errors.');
        self::assertNull($second->getAnswerKey());
        self::assertNotSame($document->getFileKey(), $second->getFileKey());
        self::assertSame('%PDF-second', $container->get(DossierFiles::class)->read((int) $dossier->getId(), $second->getFileKey()));
        self::assertSame('La pièce arrive déjà deux fois.', $preparation->duplicate($document, $this->teacher()));
        self::assertSame('Cette pièce est déjà la seconde arrivée d’une autre.', $preparation->duplicate($second, $this->teacher()));
        self::assertSame(2, $preparation->view($document)->count(), 'The TTC and the doublon.');

        // A copy of the template - a student's copy - carries the answer key and the doublon's link, rewritten.
        $copy = new Dossier(DossierKind::Template, DossierFixtures::company('Autre société'), new \DateTimeImmutable('2026-03-15'), null, null, new \DateTimeImmutable('2026-11-28 09:30'), null);
        $container->get(EntityManagerInterface::class)->persist($copy);
        $container->get(EntityManagerInterface::class)->flush();
        $container->get(DossierCopier::class)->copy($dossier, $copy, CopyKind::Template);
        $copied = $container->get(DossierContext::class)->within($copy, static fn (): array => $container->get(DocumentRepository::class)->findByDossier($copy));
        self::assertCount(2, $copied);
        $byDuplicate = [];
        foreach ($copied as $one) {
            $byDuplicate[null === $one->getDuplicateOf() ? 'first' : 'second'] = $one;
        }
        self::assertSame($byDuplicate['first'], $byDuplicate['second']->getDuplicateOf());
        self::assertSame($document->getAnswerKey(), $byDuplicate['first']->getAnswerKey());
        $container->get(DossierContext::class)->enter($dossier);

        $key = $second->getFileKey();
        self::assertNull($preparation->withdrawDuplicate($document, $this->teacher()));
        self::assertSame([], $preparation->duplicatesOf($document));
        self::assertFalse($container->get(DossierFiles::class)->exists((int) $dossier->getId(), $key), 'Its file goes with it.');
        self::assertSame('La pièce n’arrive qu’une fois.', $preparation->withdrawDuplicate($document, $this->teacher()));
    }

    public function testOnlyAnOpenPieceOfATemplateIsPrepared(): void
    {
        [, $document] = $this->template();
        self::assertTrue(PiecePreparation::isPreparable($document));
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('uidNumber:9003', 'eleve.test', 'Élève Test', AccountKind::Student, EntryBasis::StudentGroup, ['student', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($owner);
        $entityManager->flush();
        $student = $this->savedDossier(DossierKind::Student, $owner);
        $theirs = new Document($student, DocumentType::PurchaseInvoice, 'dossiers/'.$student->getId().'/pieces/x.pdf', 'x.pdf', 'application/pdf', new \DateTimeImmutable('2026-03-15'), null);
        self::assertFalse(PiecePreparation::isPreparable($theirs));
        $this->expectException(\LogicException::class);
        static::getContainer()->get(PiecePreparation::class)->slip($theirs, 'thirdParty', SlipPlace::Reading, 'F0009', $this->teacher());
    }

    /** @return array{Dossier, Document} the ledger's template, the Faure (F0009) beside the Scierie, the pièce arrived with its proposal on 601000 */
    private function template(): array
    {
        $dossier = $this->ledgerDossier();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $scierie = $container->get(ThirdPartyRepository::class)->findOneByCode($dossier, 'F0001');
        \assert(null !== $scierie && null !== $scierie->getAccount());
        $faure = new ThirdParty($dossier, 'F0009', ThirdPartyKind::Supplier, 'Quincaillerie Faure');
        $faure->keepOn($scierie->getAccount(), '401FAUR');
        $entityManager->persist($faure);
        $entityManager->flush();
        $this->entry($dossier, 'ACH', '2026-01-08', 'FA-2601-0012', ['601000 D 1000.00', '445660 D 200.00', '401000/F0001 C 1200.00'], true, 'Facture Scierie');

        $files = $container->get(DossierFiles::class);
        $key = $files->storeContents((int) $dossier->getId(), Documents::CATEGORY, 'pdf', '%PDF-second');
        $document = new Document($dossier, DocumentType::PurchaseInvoice, $key, 'FA-2603-0187.pdf', 'application/pdf', new \DateTimeImmutable('2026-03-15'), null);
        $document->read(DocumentType::PurchaseInvoice, $scierie, 'FA-2603-0187', new \DateTimeImmutable('2026-03-24'), new \DateTimeImmutable('2026-04-23'), 'CF-0102', [new VatLine('20', Money::cents(215_500), Money::cents(43_100))], Money::cents(215_500), Money::cents(43_100), Money::cents(258_600));
        $entityManager->persist($document);
        $container->get(PieceProcessing::class)->arrived($document, 'test');
        $entityManager->flush();
        self::assertSame(['601000', '445660', '401SCIE'], array_map(static fn (GridLine $line): string => $line->account, PieceProposal::fromArray($document->getProposal() ?? [])->lines));

        return [$dossier, $document];
    }

    private ?User $teacher = null;

    private function teacher(): User
    {
        if (null !== $this->teacher) {
            return $this->teacher;
        }
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('uidNumber:9002', 'prof.test', 'Prof Test', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->teacher = $user;
    }

    private function trailCount(Dossier $dossier, AuditAction $action): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditEvent::class)->count(['action' => $action, 'dossierId' => $dossier->getId()]);
    }
}
