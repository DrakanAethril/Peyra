<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128070343 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pièces : le document, son fichier et ses données lues (étape 2.1a)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(24) NOT NULL, state VARCHAR(16) NOT NULL, file_key VARCHAR(255) NOT NULL, file_name VARCHAR(255) NOT NULL, media_type VARCHAR(40) NOT NULL, received_on DATE NOT NULL, number VARCHAR(40) DEFAULT NULL, document_date DATE DEFAULT NULL, due_date DATE DEFAULT NULL, order_reference VARCHAR(40) DEFAULT NULL, vat_lines JSON NOT NULL, total_excluding_tax BIGINT DEFAULT NULL, total_vat BIGINT DEFAULT NULL, total_including_tax BIGINT DEFAULT NULL, deposited_by_id INT DEFAULT NULL, third_party_id INT DEFAULT NULL, dossier_id INT NOT NULL, INDEX idx_document_dossier_state (dossier_id, state), INDEX IDX_D8698A76664F6F5D (deposited_by_id), INDEX IDX_D8698A7654C4149C (third_party_id), INDEX IDX_D8698A76611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76664F6F5D FOREIGN KEY (deposited_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7654C4149C FOREIGN KEY (third_party_id) REFERENCES third_party (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76664F6F5D');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7654C4149C');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76611C0C56');
        $this->addSql('DROP TABLE document');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
