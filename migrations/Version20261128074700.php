<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128074700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Saisie par pièce : écriture proposée, écriture passée et anomalies d’une pièce (étape 2.2a)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document ADD proposal JSON DEFAULT NULL, ADD anomalies JSON DEFAULT NULL, ADD entry_id INT DEFAULT NULL');
        $this->addSql("UPDATE document SET anomalies = '[]'");
        $this->addSql('ALTER TABLE document MODIFY anomalies JSON NOT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D8698A76BA364942 ON document (entry_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76BA364942');
        $this->addSql('DROP INDEX IDX_D8698A76BA364942 ON document');
        $this->addSql('ALTER TABLE document DROP proposal, DROP anomalies, DROP entry_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
