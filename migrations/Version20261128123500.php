<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128123500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ventes : escompte, TVA sur les encaissements, acomptes déduits, lien vers l’écriture (étape 2.3b)';
    }

    public function up(Schema $schema): void
    {
        // The table holds rows: the mandatory flag arrives with a default, which is then dropped.
        $this->addSql('ALTER TABLE sales_document ADD cash_discount VARCHAR(8) DEFAULT NULL, ADD vat_on_receipts TINYINT DEFAULT 0 NOT NULL, ADD deposits JSON DEFAULT NULL, ADD entry_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_document ALTER vat_on_receipts DROP DEFAULT');
        $this->addSql('ALTER TABLE sales_document ADD CONSTRAINT FK_95D51252BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_95D51252BA364942 ON sales_document (entry_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_document DROP FOREIGN KEY FK_95D51252BA364942');
        $this->addSql('DROP INDEX IDX_95D51252BA364942 ON sales_document');
        $this->addSql('ALTER TABLE sales_document DROP cash_discount, DROP vat_on_receipts, DROP deposits, DROP entry_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
