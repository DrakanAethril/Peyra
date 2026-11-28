<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ventes : articles, documents commerciaux et leur numérotation (étape 2.3a)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE article (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(12) NOT NULL, designation VARCHAR(160) NOT NULL, unit VARCHAR(20) NOT NULL, sale_price BIGINT DEFAULT NULL, vat_rate VARCHAR(8) DEFAULT NULL, revenue_account VARCHAR(8) DEFAULT NULL, stock_tracked TINYINT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_article_dossier_code (dossier_id, code), INDEX IDX_23A0E66611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sales_document (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(16) NOT NULL, state VARCHAR(12) NOT NULL, number VARCHAR(20) DEFAULT NULL, customer_print JSON DEFAULT NULL, date DATE NOT NULL, due_date DATE DEFAULT NULL, reference VARCHAR(80) DEFAULT NULL, document_lines JSON NOT NULL, sent_on DATE DEFAULT NULL, history JSON NOT NULL, customer_id INT NOT NULL, source_id INT DEFAULT NULL, dossier_id INT NOT NULL, INDEX idx_sales_document_type (dossier_id, type, state), UNIQUE INDEX uniq_sales_document_number (dossier_id, type, number), INDEX IDX_95D512529395C3F3 (customer_id), INDEX IDX_95D51252953C1C61 (source_id), INDEX IDX_95D51252611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sales_sequence (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(16) NOT NULL, year SMALLINT NOT NULL, last_number INT NOT NULL, last_date DATE DEFAULT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_sales_sequence (dossier_id, type, year), INDEX IDX_1F3A4F0F611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E66611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sales_document ADD CONSTRAINT FK_95D512529395C3F3 FOREIGN KEY (customer_id) REFERENCES third_party (id)');
        $this->addSql('ALTER TABLE sales_document ADD CONSTRAINT FK_95D51252953C1C61 FOREIGN KEY (source_id) REFERENCES sales_document (id)');
        $this->addSql('ALTER TABLE sales_document ADD CONSTRAINT FK_95D51252611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sales_sequence ADD CONSTRAINT FK_1F3A4F0F611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E66611C0C56');
        $this->addSql('ALTER TABLE sales_document DROP FOREIGN KEY FK_95D512529395C3F3');
        $this->addSql('ALTER TABLE sales_document DROP FOREIGN KEY FK_95D51252953C1C61');
        $this->addSql('ALTER TABLE sales_document DROP FOREIGN KEY FK_95D51252611C0C56');
        $this->addSql('ALTER TABLE sales_sequence DROP FOREIGN KEY FK_1F3A4F0F611C0C56');
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE sales_document');
        $this->addSql('DROP TABLE sales_sequence');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
