<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 0.6b: the dated regulatory values (append-only, one row per value of a date and per
 * correction) and the audit trail (append-only).
 */
final class Version20261127181029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paramètres réglementaires datés et piste d’audit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_event (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, actor_name VARCHAR(190) NOT NULL, action VARCHAR(60) NOT NULL, subject_type VARCHAR(60) NOT NULL, subject_id VARCHAR(190) NOT NULL, summary VARCHAR(255) NOT NULL, details JSON NOT NULL, actor_id INT DEFAULT NULL, INDEX idx_audit_event_subject (subject_type, subject_id), INDEX idx_audit_event_occurred (occurred_at), INDEX IDX_3CE56E5010DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE regulatory_value (id INT AUTO_INCREMENT NOT NULL, value_key VARCHAR(120) NOT NULL, label VARCHAR(255) NOT NULL, value_text LONGTEXT NOT NULL, unit VARCHAR(120) NOT NULL, effective_from DATE NOT NULL, source_url VARCHAR(500) DEFAULT NULL, consulted_on DATE DEFAULT NULL, verified TINYINT NOT NULL, note LONGTEXT DEFAULT NULL, origin VARCHAR(20) NOT NULL, millesime SMALLINT NOT NULL, recorded_at DATETIME NOT NULL, recorded_by_id INT DEFAULT NULL, INDEX idx_regulatory_value_key_date (value_key, effective_from), INDEX idx_regulatory_value_millesime (millesime), INDEX IDX_FA7DD9C1D05A957B (recorded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_event ADD CONSTRAINT FK_3CE56E5010DAF24A FOREIGN KEY (actor_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE regulatory_value ADD CONSTRAINT FK_FA7DD9C1D05A957B FOREIGN KEY (recorded_by_id) REFERENCES user_account (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_event DROP FOREIGN KEY FK_3CE56E5010DAF24A');
        $this->addSql('ALTER TABLE regulatory_value DROP FOREIGN KEY FK_FA7DD9C1D05A957B');
        $this->addSql('DROP TABLE audit_event');
        $this->addSql('DROP TABLE regulatory_value');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
