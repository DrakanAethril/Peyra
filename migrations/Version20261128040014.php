<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.8b: the restore points of a dossier (their frozen copy is a dossier of kind snapshot) and
 * the returns of a dossier to one of them.
 */
final class Version20261128040014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Points de restauration et retours à un point (étape 1.8b)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dossier_restoration (id INT AUTO_INCREMENT NOT NULL, point_name VARCHAR(80) NOT NULL, requested_at DATETIME NOT NULL, state VARCHAR(16) NOT NULL, finished_at DATETIME DEFAULT NULL, failure VARCHAR(255) DEFAULT NULL, dossier_id INT NOT NULL, point_id INT DEFAULT NULL, safety_point_id INT DEFAULT NULL, requested_by_id INT DEFAULT NULL, INDEX idx_dossier_restoration_state (state), INDEX IDX_246B69FB611C0C56 (dossier_id), INDEX IDX_246B69FBC028CEA2 (point_id), INDEX IDX_246B69FB8381D3B9 (safety_point_id), INDEX IDX_246B69FB4DA1E751 (requested_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE restore_point (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, origin VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, dossier_date DATE NOT NULL, state VARCHAR(16) NOT NULL, finished_at DATETIME DEFAULT NULL, failure VARCHAR(255) DEFAULT NULL, dossier_id INT NOT NULL, snapshot_id INT NOT NULL, author_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C53180847B39395E (snapshot_id), INDEX idx_restore_point_state (state), INDEX IDX_C5318084611C0C56 (dossier_id), INDEX IDX_C5318084F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dossier_restoration ADD CONSTRAINT FK_246B69FB611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dossier_restoration ADD CONSTRAINT FK_246B69FBC028CEA2 FOREIGN KEY (point_id) REFERENCES restore_point (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dossier_restoration ADD CONSTRAINT FK_246B69FB8381D3B9 FOREIGN KEY (safety_point_id) REFERENCES restore_point (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dossier_restoration ADD CONSTRAINT FK_246B69FB4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE restore_point ADD CONSTRAINT FK_C5318084611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE restore_point ADD CONSTRAINT FK_C53180847B39395E FOREIGN KEY (snapshot_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE restore_point ADD CONSTRAINT FK_C5318084F675F31B FOREIGN KEY (author_id) REFERENCES user_account (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_restoration DROP FOREIGN KEY FK_246B69FB611C0C56');
        $this->addSql('ALTER TABLE dossier_restoration DROP FOREIGN KEY FK_246B69FBC028CEA2');
        $this->addSql('ALTER TABLE dossier_restoration DROP FOREIGN KEY FK_246B69FB8381D3B9');
        $this->addSql('ALTER TABLE dossier_restoration DROP FOREIGN KEY FK_246B69FB4DA1E751');
        $this->addSql('ALTER TABLE restore_point DROP FOREIGN KEY FK_C5318084611C0C56');
        $this->addSql('ALTER TABLE restore_point DROP FOREIGN KEY FK_C53180847B39395E');
        $this->addSql('ALTER TABLE restore_point DROP FOREIGN KEY FK_C5318084F675F31B');
        $this->addSql('DROP TABLE dossier_restoration');
        $this->addSql('DROP TABLE restore_point');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
