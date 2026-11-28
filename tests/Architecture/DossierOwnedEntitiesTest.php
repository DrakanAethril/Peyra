<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Entity\Audit\AuditEvent;
use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\DistributionCopy;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierMember;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierRestoration;
use App\Entity\Dossier\DossierShare;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\AdministrationEvent;
use App\Entity\Identity\AuthorizedTeacher;
use App\Entity\Identity\DirectoryGroupMapping;
use App\Entity\Identity\Enrolment;
use App\Entity\Identity\LoginEvent;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use App\Entity\Regulatory\RegulatoryValue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * « Toute ligne métier porte son dossier » (README of the plan, § 8): every entity is DossierOwned
 * - and then carries a mandatory `dossier_id` the filter reads - or is one of the global entities
 * named here. A new entity fails this test until it takes a side: a business row implements
 * DossierOwned, anything else joins the list with the reason it is not a dossier's content.
 */
final class DossierOwnedEntitiesTest extends KernelTestCase
{
    /** The entities that are not a dossier's content, and why. */
    private const array GLOBAL = [
        // Accounts, years, classes, placement and their journals: the institution's, not a dossier's.
        User::class, SchoolYear::class, SchoolClass::class, Enrolment::class, TeacherClass::class,
        DirectoryGroupMapping::class, AuthorizedTeacher::class, LoginEvent::class, AdministrationEvent::class,
        // Dated regulatory values: common to the institution, read at each dossier's date.
        RegulatoryValue::class,
        // The generic audit trail: a line names its subject (a dossier among others).
        AuditEvent::class,
        // The dossier itself, and who enters it besides its owner: read by DossierVoter before any context.
        Dossier::class, DossierMember::class, DossierShare::class,
        // What a dossier can go back to, and its returns: a point keeps its copy in a dossier of its
        // own; a copy of the dossier never carries them (step 1.8b).
        RestorePoint::class, DossierRestoration::class,
        // Where dossiers came from: a template handed out to a class, and each dossier it makes
        // (step 1.8c). Never a dossier's content.
        Distribution::class, DistributionCopy::class,
    ];

    public function testEveryEntityIsDossierOwnedOrNamedGlobal(): void
    {
        $undecided = [];
        $both = [];
        foreach ($this->allMetadata() as $metadata) {
            $owned = $metadata->getReflectionClass()->implementsInterface(DossierOwned::class);
            $global = \in_array($metadata->getName(), self::GLOBAL, true);
            if (!$owned && !$global) {
                $undecided[] = $metadata->getName();
            }
            if ($owned && $global) {
                $both[] = $metadata->getName();
            }
        }

        self::assertSame([], $undecided, 'Entités ni DossierOwned ni nommées globales : une ligne métier implémente DossierOwned.');
        self::assertSame([], $both, 'Entités à la fois DossierOwned et globales.');
    }

    public function testTheGlobalListNamesOnlyEntitiesThatExist(): void
    {
        $names = array_map(static fn (ClassMetadata $metadata): string => $metadata->getName(), $this->allMetadata());

        self::assertSame([], array_values(array_diff(self::GLOBAL, $names)));
    }

    public function testEveryDossierOwnedEntityCarriesAMandatoryDossierId(): void
    {
        $faults = [];
        foreach ($this->allMetadata() as $metadata) {
            if (!$metadata->getReflectionClass()->implementsInterface(DossierOwned::class)) {
                continue;
            }
            if (!$metadata->hasAssociation('dossier') || Dossier::class !== $metadata->getAssociationTargetClass('dossier') || !$metadata->isSingleValuedAssociation('dossier')) {
                $faults[] = $metadata->getName().' : pas d’association `dossier` vers Dossier';
                continue;
            }
            $joinColumn = $metadata->getAssociationMapping('dossier')->joinColumns[0] ?? null;
            if (null === $joinColumn || 'dossier_id' !== $joinColumn->name || true === $joinColumn->nullable) {
                $faults[] = $metadata->getName().' : la colonne doit être `dossier_id`, obligatoire';
            }
        }

        self::assertSame([], $faults);
        self::assertNotEmpty(array_filter($this->allMetadata(), static fn (ClassMetadata $metadata): bool => $metadata->getReflectionClass()->implementsInterface(DossierOwned::class)));
    }

    /** @return list<ClassMetadata<object>> */
    private function allMetadata(): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getMetadataFactory()->getAllMetadata();
    }
}
