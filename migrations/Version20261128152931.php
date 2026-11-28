<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128152931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Échéances (2.7) : bon à payer des factures fournisseurs, marqueur « créance douteuse » du tiers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_approval (id INT AUTO_INCREMENT NOT NULL, approved_on DATE NOT NULL, invoice_id INT NOT NULL, approved_by_id INT DEFAULT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_payment_approval_invoice (invoice_id), INDEX IDX_5626D7432D234F6A (approved_by_id), INDEX IDX_5626D743611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE payment_approval ADD CONSTRAINT FK_5626D7432989F1FD FOREIGN KEY (invoice_id) REFERENCES entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payment_approval ADD CONSTRAINT FK_5626D7432D234F6A FOREIGN KEY (approved_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE payment_approval ADD CONSTRAINT FK_5626D743611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE third_party ADD doubtful TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_approval DROP FOREIGN KEY FK_5626D7432989F1FD');
        $this->addSql('ALTER TABLE payment_approval DROP FOREIGN KEY FK_5626D7432D234F6A');
        $this->addSql('ALTER TABLE payment_approval DROP FOREIGN KEY FK_5626D743611C0C56');
        $this->addSql('DROP TABLE payment_approval');
        $this->addSql('ALTER TABLE third_party DROP doubtful');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
