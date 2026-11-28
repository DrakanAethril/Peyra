<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 2.8: a student's own assistance level (an aménagement), which overrides the missions'.
 */
final class Version20261128050221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Niveau d’assistance propre d’un étudiant (étape 2.8)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account ADD assistance_level VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_account DROP assistance_level');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
