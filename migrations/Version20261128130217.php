<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128130217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stocks : mouvements de stock, méthode de valorisation et stock minimum des articles (2.5a)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock_movement (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, kind VARCHAR(12) NOT NULL, quantity NUMERIC(15, 3) NOT NULL, value BIGINT DEFAULT NULL, location VARCHAR(40) NOT NULL, to_location VARCHAR(40) DEFAULT NULL, label VARCHAR(160) NOT NULL, article_id INT NOT NULL, sales_document_id INT DEFAULT NULL, dossier_id INT NOT NULL, INDEX idx_stock_movement_article (dossier_id, article_id, date), INDEX IDX_BB1BC1B57294869C (article_id), INDEX IDX_BB1BC1B5873469F3 (sales_document_id), INDEX IDX_BB1BC1B5611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B57294869C FOREIGN KEY (article_id) REFERENCES article (id)');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5873469F3 FOREIGN KEY (sales_document_id) REFERENCES sales_document (id)');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        // The articles already there are valued at the CMUP after each entry until their fiche says otherwise.
        $this->addSql('ALTER TABLE article ADD valuation_method VARCHAR(20) DEFAULT NULL, ADD minimum_stock NUMERIC(15, 3) DEFAULT NULL');
        $this->addSql("UPDATE article SET valuation_method = 'weighted_average'");
        $this->addSql('ALTER TABLE article MODIFY valuation_method VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP FOREIGN KEY FK_BB1BC1B57294869C');
        $this->addSql('ALTER TABLE stock_movement DROP FOREIGN KEY FK_BB1BC1B5873469F3');
        $this->addSql('ALTER TABLE stock_movement DROP FOREIGN KEY FK_BB1BC1B5611C0C56');
        $this->addSql('DROP TABLE stock_movement');
        $this->addSql('ALTER TABLE article DROP valuation_method, DROP minimum_stock');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
