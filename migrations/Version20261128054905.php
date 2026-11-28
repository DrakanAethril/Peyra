<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The FEC imported in the background (step 1.10b): the import and its passes, the template hidden while it fills.
 */
final class Version20261128054905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import d’un FEC en tâche de fond (étape 1.10b)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fec_import_job (id INT AUTO_INCREMENT NOT NULL, file_name VARCHAR(255) NOT NULL, payload MEDIUMTEXT DEFAULT NULL, exercise_start DATE NOT NULL, state VARCHAR(16) NOT NULL, stage VARCHAR(16) NOT NULL, stage_position INT NOT NULL, entries INT NOT NULL, journals INT NOT NULL, third_parties INT NOT NULL, letterings INT NOT NULL, zero_lines INT NOT NULL, letters_left INT NOT NULL, accounts_created INT NOT NULL, failure VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, author_id INT NOT NULL, template_id INT DEFAULT NULL, INDEX IDX_8ECD6B01F675F31B (author_id), INDEX IDX_8ECD6B015DA0FB8 (template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fec_import_job ADD CONSTRAINT FK_8ECD6B01F675F31B FOREIGN KEY (author_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE fec_import_job ADD CONSTRAINT FK_8ECD6B015DA0FB8 FOREIGN KEY (template_id) REFERENCES dossier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dossier ADD importing TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fec_import_job DROP FOREIGN KEY FK_8ECD6B01F675F31B');
        $this->addSql('ALTER TABLE fec_import_job DROP FOREIGN KEY FK_8ECD6B015DA0FB8');
        $this->addSql('DROP TABLE fec_import_job');
        $this->addSql('ALTER TABLE dossier DROP importing');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
