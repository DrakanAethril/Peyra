<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128141653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Banque : relevés, leurs lignes, pointages et règles d’affectation (2.6a)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_pointing (id INT AUTO_INCREMENT NOT NULL, letter VARCHAR(4) NOT NULL, statement_id INT NOT NULL, entry_id INT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_bank_pointing_entry (statement_id, entry_id), INDEX IDX_7C68A7A849CB65B (statement_id), INDEX IDX_7C68A7ABA364942 (entry_id), INDEX IDX_7C68A7A611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bank_rule (id INT AUTO_INCREMENT NOT NULL, pattern VARCHAR(60) NOT NULL, account VARCHAR(17) NOT NULL, vat_rate VARCHAR(7) DEFAULT NULL, entry_label VARCHAR(60) NOT NULL, dossier_id INT NOT NULL, INDEX IDX_ECB8FE42611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bank_statement (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, opening_balance BIGINT NOT NULL, closing_balance BIGINT NOT NULL, imported_on DATE NOT NULL, source VARCHAR(120) NOT NULL, reconciled_on DATE DEFAULT NULL, journal_id INT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_bank_statement_number (journal_id, number), INDEX IDX_B33607EF478E8802 (journal_id), INDEX IDX_B33607EF611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bank_statement_line (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, date DATE NOT NULL, label VARCHAR(160) NOT NULL, amount BIGINT NOT NULL, letter VARCHAR(4) DEFAULT NULL, statement_id INT NOT NULL, dossier_id INT NOT NULL, INDEX IDX_546CC803849CB65B (statement_id), INDEX IDX_546CC803611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bank_pointing ADD CONSTRAINT FK_7C68A7A849CB65B FOREIGN KEY (statement_id) REFERENCES bank_statement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bank_pointing ADD CONSTRAINT FK_7C68A7ABA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bank_pointing ADD CONSTRAINT FK_7C68A7A611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bank_rule ADD CONSTRAINT FK_ECB8FE42611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bank_statement ADD CONSTRAINT FK_B33607EF478E8802 FOREIGN KEY (journal_id) REFERENCES journal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE bank_statement ADD CONSTRAINT FK_B33607EF611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bank_statement_line ADD CONSTRAINT FK_546CC803849CB65B FOREIGN KEY (statement_id) REFERENCES bank_statement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bank_statement_line ADD CONSTRAINT FK_546CC803611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_pointing DROP FOREIGN KEY FK_7C68A7A849CB65B');
        $this->addSql('ALTER TABLE bank_pointing DROP FOREIGN KEY FK_7C68A7ABA364942');
        $this->addSql('ALTER TABLE bank_pointing DROP FOREIGN KEY FK_7C68A7A611C0C56');
        $this->addSql('ALTER TABLE bank_rule DROP FOREIGN KEY FK_ECB8FE42611C0C56');
        $this->addSql('ALTER TABLE bank_statement DROP FOREIGN KEY FK_B33607EF478E8802');
        $this->addSql('ALTER TABLE bank_statement DROP FOREIGN KEY FK_B33607EF611C0C56');
        $this->addSql('ALTER TABLE bank_statement_line DROP FOREIGN KEY FK_546CC803849CB65B');
        $this->addSql('ALTER TABLE bank_statement_line DROP FOREIGN KEY FK_546CC803611C0C56');
        $this->addSql('DROP TABLE bank_pointing');
        $this->addSql('DROP TABLE bank_rule');
        $this->addSql('DROP TABLE bank_statement');
        $this->addSql('DROP TABLE bank_statement_line');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
