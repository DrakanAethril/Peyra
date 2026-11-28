<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plan de paie : une rubrique de brut marquée avantage en nature, retenue sur le net (étape 4.3c)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payroll_item ADD benefit_in_kind TINYINT NOT NULL');
        // The standard plan's two benefits in kind, installed before the mark existed.
        $this->addSql("UPDATE payroll_item SET benefit_in_kind = 1 WHERE kind = 'gross' AND subtracts = 0 AND code IN ('1400', '1410')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payroll_item DROP benefit_in_kind');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
