<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Document\DocumentType;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\Storage\DossierFiles;
use App\Service\Shared\Storage\UploadPolicy;
use App\Service\Shared\Storage\UploadRefusal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The one door of a dossier's pièces (lot 2, step 2.1): a deposit (a PDF or a picture, stored by
 * DossierFiles, arriving « à traiter » on the dossier's date) and the read data typed by hand,
 * checked by DocumentReadRules. Every gesture goes to the dossier's trail; the caller flushes.
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
}
