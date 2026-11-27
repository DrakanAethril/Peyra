<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.2a: the dossier's plan of accounts, its journals, and the reference chart its plan was
 * copied from.
 */
final class Version20261127195912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plan de comptes du dossier, journaux, plan de référence du dossier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(8) NOT NULL, label VARCHAR(200) NOT NULL, lettrable TINYINT NOT NULL, collective TINYINT NOT NULL, origin VARCHAR(16) NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_account_dossier_number (dossier_id, number), INDEX IDX_7D3656A4611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE journal (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(6) NOT NULL, label VARCHAR(80) NOT NULL, type VARCHAR(16) NOT NULL, counterpart_account_id INT DEFAULT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_journal_dossier_code (dossier_id, code), INDEX IDX_C1A7E74D8385FA01 (counterpart_account_id), INDEX IDX_C1A7E74D611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account ADD CONSTRAINT FK_7D3656A4611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE journal ADD CONSTRAINT FK_C1A7E74D8385FA01 FOREIGN KEY (counterpart_account_id) REFERENCES account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE journal ADD CONSTRAINT FK_C1A7E74D611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dossier ADD chart_code VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP FOREIGN KEY FK_7D3656A4611C0C56');
        $this->addSql('ALTER TABLE journal DROP FOREIGN KEY FK_C1A7E74D8385FA01');
        $this->addSql('ALTER TABLE journal DROP FOREIGN KEY FK_C1A7E74D611C0C56');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE journal');
        $this->addSql('ALTER TABLE dossier DROP chart_code');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
