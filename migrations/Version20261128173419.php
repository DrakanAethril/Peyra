<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128173419 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Immobilisations : dépréciations, cession ou mise au rebut, pièces des écritures demandées (3.4b)';
    }

    public function up(Schema $schema): void
    {
        // The JSON columns arrive empty on the fiches already there, then become required.
        $this->addSql('ALTER TABLE fixed_asset ADD impairments JSON DEFAULT NULL, ADD disposed_on DATE DEFAULT NULL, ADD disposal_kind VARCHAR(12) DEFAULT NULL, ADD disposal_price BIGINT NOT NULL, ADD disposal_vat BIGINT NOT NULL, ADD postings JSON DEFAULT NULL, ADD buyer_id INT DEFAULT NULL');
        $this->addSql("UPDATE fixed_asset SET impairments = '{}', postings = '[]'");
        $this->addSql('ALTER TABLE fixed_asset MODIFY impairments JSON NOT NULL, MODIFY postings JSON NOT NULL');
        $this->addSql('ALTER TABLE fixed_asset ADD CONSTRAINT FK_6BC872AA6C755722 FOREIGN KEY (buyer_id) REFERENCES third_party (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6BC872AA6C755722 ON fixed_asset (buyer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixed_asset DROP FOREIGN KEY FK_6BC872AA6C755722');
        $this->addSql('DROP INDEX IDX_6BC872AA6C755722 ON fixed_asset');
        $this->addSql('ALTER TABLE fixed_asset DROP impairments, DROP disposed_on, DROP disposal_kind, DROP disposal_price, DROP disposal_vat, DROP postings, DROP buyer_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
