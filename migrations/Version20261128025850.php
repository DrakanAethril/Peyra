<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.8a: a template's card in the library - its title, description, the year it is written
 * for and the processes it covers. The processes' column is added empty, then made mandatory, so
 * that the rows already there hold a valid JSON list.
 */
final class Version20261128025850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiche d’un dossier modèle dans la bibliothèque (étape 1.8a)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier ADD library_title VARCHAR(120) DEFAULT NULL, ADD library_description LONGTEXT DEFAULT NULL, ADD library_level VARCHAR(8) DEFAULT NULL, ADD library_processes JSON DEFAULT NULL');
        $this->addSql("UPDATE dossier SET library_processes = '[]'");
        $this->addSql('ALTER TABLE dossier MODIFY library_processes JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier DROP library_title, DROP library_description, DROP library_level, DROP library_processes');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
