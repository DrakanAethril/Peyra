<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erreurs glissées par l’enseignant : corrigé d’une pièce et seconde arrivée d’un doublon (étape 2.2b)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document ADD answer_key JSON DEFAULT NULL, ADD duplicate_of_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A762CC33300 FOREIGN KEY (duplicate_of_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D8698A762CC33300 ON document (duplicate_of_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A762CC33300');
        $this->addSql('DROP INDEX IDX_D8698A762CC33300 ON document');
        $this->addSql('ALTER TABLE document DROP answer_key, DROP duplicate_of_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
