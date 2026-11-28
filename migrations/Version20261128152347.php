<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128152347 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Règlements : encaissements et décaissements, leurs factures, remises de chèques, effets (2.6b)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cheque_deposit (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, deposited_on DATE NOT NULL, journal_id INT NOT NULL, entry_id INT DEFAULT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_cheque_deposit_number (dossier_id, number), INDEX IDX_788F6537478E8802 (journal_id), INDEX IDX_788F6537BA364942 (entry_id), INDEX IDX_788F6537611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, direction VARCHAR(16) NOT NULL, method VARCHAR(16) NOT NULL, paid_on DATE NOT NULL, reference VARCHAR(40) NOT NULL, amount BIGINT NOT NULL, due_on DATE DEFAULT NULL, remittance VARCHAR(16) DEFAULT NULL, remitted_on DATE DEFAULT NULL, third_party_id INT NOT NULL, journal_id INT NOT NULL, entry_id INT DEFAULT NULL, deposit_id INT DEFAULT NULL, remittance_journal_id INT DEFAULT NULL, remittance_entry_id INT DEFAULT NULL, dossier_id INT NOT NULL, INDEX IDX_6D28840D54C4149C (third_party_id), INDEX IDX_6D28840D478E8802 (journal_id), INDEX IDX_6D28840DBA364942 (entry_id), INDEX IDX_6D28840D9815E4B1 (deposit_id), INDEX IDX_6D28840D260E7211 (remittance_journal_id), INDEX IDX_6D28840DB1C0A887 (remittance_entry_id), INDEX IDX_6D28840D611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment_allocation (id INT AUTO_INCREMENT NOT NULL, amount BIGINT NOT NULL, vat_transferred BIGINT NOT NULL, payment_id INT NOT NULL, invoice_id INT NOT NULL, dossier_id INT NOT NULL, INDEX IDX_69B2A2FF4C3A3BB (payment_id), INDEX IDX_69B2A2FF2989F1FD (invoice_id), INDEX IDX_69B2A2FF611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cheque_deposit ADD CONSTRAINT FK_788F6537478E8802 FOREIGN KEY (journal_id) REFERENCES journal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE cheque_deposit ADD CONSTRAINT FK_788F6537BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cheque_deposit ADD CONSTRAINT FK_788F6537611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D54C4149C FOREIGN KEY (third_party_id) REFERENCES third_party (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D478E8802 FOREIGN KEY (journal_id) REFERENCES journal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D9815E4B1 FOREIGN KEY (deposit_id) REFERENCES cheque_deposit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D260E7211 FOREIGN KEY (remittance_journal_id) REFERENCES journal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DB1C0A887 FOREIGN KEY (remittance_entry_id) REFERENCES entry (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_69B2A2FF4C3A3BB FOREIGN KEY (payment_id) REFERENCES payment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_69B2A2FF2989F1FD FOREIGN KEY (invoice_id) REFERENCES entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_69B2A2FF611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cheque_deposit DROP FOREIGN KEY FK_788F6537478E8802');
        $this->addSql('ALTER TABLE cheque_deposit DROP FOREIGN KEY FK_788F6537BA364942');
        $this->addSql('ALTER TABLE cheque_deposit DROP FOREIGN KEY FK_788F6537611C0C56');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D54C4149C');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D478E8802');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DBA364942');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D9815E4B1');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D260E7211');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DB1C0A887');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D611C0C56');
        $this->addSql('ALTER TABLE payment_allocation DROP FOREIGN KEY FK_69B2A2FF4C3A3BB');
        $this->addSql('ALTER TABLE payment_allocation DROP FOREIGN KEY FK_69B2A2FF2989F1FD');
        $this->addSql('ALTER TABLE payment_allocation DROP FOREIGN KEY FK_69B2A2FF611C0C56');
        $this->addSql('DROP TABLE cheque_deposit');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE payment_allocation');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
