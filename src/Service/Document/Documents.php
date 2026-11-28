<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Audit\AuditAction;
use App\Enum\Document\DocumentType;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Document\Generator\PrintedInvoice;
use App\Service\Shared\Storage\DossierFiles;
use App\Service\Shared\Storage\UploadPolicy;
use App\Service\Shared\Storage\UploadRefusal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The one door of a dossier's pièces (lot 2, step 2.1): a deposit (a PDF or a picture, stored by
 * DossierFiles, arriving « à traiter » on the dossier's date), a pièce the generator made (step
 * 2.1b: its PDF, its read data = what it prints) and the read data typed by hand, checked by
 * DocumentReadRules. Every gesture goes to the dossier's trail; the caller flushes.
 * Read inside the dossier's context.
 */
final readonly class Documents
{
    /** Where a pièce's file lives under its dossier's prefix. */
    public const string CATEGORY = 'pieces';

    public function __construct(
        private DossierFiles $files,
        private ThirdPartyRepository $thirdParties,
        private AuditTrail $trail,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** What a pièce may be: a PDF or a picture. */
    public static function policy(): UploadPolicy
    {
        return UploadPolicy::platform()->restrictTo('pdf', 'jpg', 'jpeg', 'png', 'webp');
    }

    /** @throws UploadRefusal */
    public function deposit(Dossier $dossier, UploadedFile $file, DocumentType $type, User $actor): Document
    {
        $mediaType = (string) $file->getMimeType();
        $key = $this->files->storeUpload((int) $dossier->getId(), self::CATEGORY, $file, self::policy());
        $name = mb_substr(trim($file->getClientOriginalName()), 0, 255);
        $document = new Document($dossier, $type, $key, $name, $mediaType, $dossier->getDossierDate(), $actor);
        $this->entityManager->persist($document);
        $this->trail->inDossier($dossier, AuditAction::DocumentDeposited, $actor, \sprintf('%s déposée : %s.', $type->label(), $name), ['type' => $type->value, 'file' => $name]);

        return $document;
    }

    /**
     * A purchase invoice the generator printed (InvoiceGenerator), arriving « à traiter » on the
     * dossier's date with what it prints as its read data, and the operation it was made from.
     *
     * @param array<string, mixed> $generation
     */
    public function generated(Dossier $dossier, string $pdf, string $fileName, ThirdParty $supplier, PrintedInvoice $printed, array $generation, User $actor): Document
    {
        $key = $this->files->storeContents((int) $dossier->getId(), self::CATEGORY, 'pdf', $pdf);
        $document = new Document($dossier, DocumentType::PurchaseInvoice, $key, mb_substr($fileName, 0, 255), 'application/pdf', $dossier->getDossierDate(), $actor);
        $document->read(DocumentType::PurchaseInvoice, $supplier, $printed->number, $printed->date, $printed->dueDate, $printed->orderReference, $printed->vatLines, $printed->totalExcludingTax, $printed->totalVat, $printed->totalIncludingTax);
        $document->generatedFrom($generation);
        $this->entityManager->persist($document);
        $this->trail->inDossier($dossier, AuditAction::DocumentGenerated, $actor, \sprintf('Facture d’achat générée : %s, %s.', $supplier->getName(), $fileName), ['file' => $fileName, 'supplier' => $supplier->getCode()]);

        return $document;
    }

    /**
     * The same pièce arriving a second time (a « doublon » the teacher slips in, step 2.2b): a copy
     * of its file under a key of its own, its nature and name, the day it arrived, what the
     * generator made it from; its read data are left to the caller. The caller flushes.
     */
    public function secondArrival(Document $first, User $actor): Document
    {
        $dossier = $first->getDossier();
        $extension = pathinfo($first->getFileKey(), \PATHINFO_EXTENSION);
        $key = $this->files->storeContents((int) $dossier->getId(), self::CATEGORY, '' === $extension ? 'pdf' : $extension, $this->files->read((int) $dossier->getId(), $first->getFileKey()));
        $second = new Document($dossier, $first->getType(), $key, $first->getFileName(), $first->getMediaType(), $first->getReceivedOn(), $actor);
        $generation = $first->getGeneration();
        if (null !== $generation) {
            $second->generatedFrom(['copy' => 2] + $generation);
        }
        $second->markDuplicateOf($first);
        $this->entityManager->persist($second);

        return $second;
    }

    /**
     * Checks the read data and writes them; false (the reading's errors set) when refused.
     */
    public function saveReading(Document $document, DocumentReading $reading, User $actor): bool
    {
        if (!$document->getState()->isOpen()) {
            throw new \LogicException('A posted or discarded pièce keeps its read data.');
        }
        $dossier = $document->getDossier();
        $known = [];
        foreach ($this->thirdParties->findByDossier($dossier) as $thirdParty) {
            $known[(int) $thirdParty->getId()] = $thirdParty;
        }
        $facts = DocumentReadRules::check($reading, static fn (int $id): bool => isset($known[$id]));
        if (null === $facts) {
            return false;
        }
        $document->read(
            $facts->type,
            null === $facts->thirdPartyId ? null : $known[$facts->thirdPartyId],
            $facts->number,
            $facts->date,
            $facts->dueDate,
            $facts->orderReference,
            $facts->vatLines,
            $facts->totalExcludingTax,
            $facts->totalVat,
            $facts->totalIncludingTax,
        );
        $this->trail->inDossier($dossier, AuditAction::DocumentReadingChanged, $actor, \sprintf('Données lues de la pièce %s enregistrées.', $facts->number ?? $document->getFileName()), ['document' => $document->getId()]);

        return true;
    }

    /**
     * The dossier's date a template's open pièce arrives on (a mission's « Pièces », step 2.9a): a
     * student's copy shows it once its date has come (DocumentRepository). The caller flushes.
     */
    public function scheduleArrival(Document $document, \DateTimeImmutable $on, User $actor): void
    {
        $dossier = $document->getDossier();
        if (!$dossier->isTemplate() || !$document->getState()->isOpen()) {
            throw new \LogicException('Only an open pièce of a template is given its arrival.');
        }
        $document->arriveOn($on);
        $this->trail->inDossier($dossier, AuditAction::DocumentArrivalScheduled, $actor, \sprintf('La pièce %s arrive le %s.', $document->getNumber() ?? $document->getFileName(), $on->format('d/m/Y')), ['document' => $document->getId(), 'on' => $on->format('Y-m-d')]);
    }
}
