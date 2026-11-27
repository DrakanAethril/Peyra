<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use App\Entity\Dossier\DossierOwned;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * « Toute ligne métier porte son dossier » (conception § 4, README of the plan § 8): every query
 * that reads a DossierOwned row is narrowed to the dossiers of the context - the one a route's
 * `{dossier}` opened, or those a command or a listing names (App\Service\Dossier\DossierContext,
 * the only writer of the parameter). Without a context, reading such a row throws: a forgotten
 * context is an error, never every dossier at once. Other entities pass through.
 *
 * Enabled for every entity manager (config/packages/doctrine.yaml); only ORM queries go through it.
 */
final class DossierFilter extends SQLFilter
{
    public const string NAME = 'dossier';
    public const string PARAMETER = 'dossiers';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->implementsInterface(DossierOwned::class)) {
            return '';
        }
        if (!$this->hasParameter(self::PARAMETER)) {
            throw new DossierContextMissing(\sprintf('%s is read outside any dossier context: enter one through DossierContext.', $targetEntity->getName()));
        }

        return \sprintf('%s.%s IN (%s)', $targetTableAlias, $targetEntity->getSingleAssociationJoinColumnName('dossier'), $this->getParameterList(self::PARAMETER));
    }
}
