<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.2b: the dossier's third parties - code, nature, account and auxiliary code, details,
 * structured payment terms and mode.
 */
final class Version20261127203505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tiers du dossier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE third_party (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(12) NOT NULL, kind VARCHAR(16) NOT NULL, name VARCHAR(120) NOT NULL, individual TINYINT NOT NULL, auxiliary_code VARCHAR(17) DEFAULT NULL, siren VARCHAR(9) DEFAULT NULL, vat_number VARCHAR(20) DEFAULT NULL, street VARCHAR(120) DEFAULT NULL, street2 VARCHAR(120) DEFAULT NULL, postal_code VARCHAR(10) DEFAULT NULL, city VARCHAR(80) DEFAULT NULL, country VARCHAR(2) NOT NULL, contact_name VARCHAR(120) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, payment_method VARCHAR(16) NOT NULL, note VARCHAR(500) DEFAULT NULL, payment_terms_kind VARCHAR(20) NOT NULL, payment_terms_days SMALLINT DEFAULT NULL, payment_terms_day_of_month SMALLINT DEFAULT NULL, account_id INT DEFAULT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_third_party_dossier_code (dossier_id, code), UNIQUE INDEX uniq_third_party_dossier_auxiliary (dossier_id, auxiliary_code), INDEX IDX_346F1E229B6B5FBA (account_id), INDEX IDX_346F1E22611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE third_party ADD CONSTRAINT FK_346F1E229B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE third_party ADD CONSTRAINT FK_346F1E22611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE third_party DROP FOREIGN KEY FK_346F1E229B6B5FBA');
        $this->addSql('ALTER TABLE third_party DROP FOREIGN KEY FK_346F1E22611C0C56');
        $this->addSql('DROP TABLE third_party');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
