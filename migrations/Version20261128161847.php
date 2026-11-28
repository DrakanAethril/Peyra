<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128161847 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Immobilisations : fiches, mode et durée d’amortissement, composants, unités d’œuvre (3.4a)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fixed_asset (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(12) NOT NULL, label VARCHAR(120) NOT NULL, origin VARCHAR(12) NOT NULL, acquired_on DATE NOT NULL, in_service_on DATE NOT NULL, price BIGINT NOT NULL, incidental_costs BIGINT NOT NULL, vat BIGINT NOT NULL, document VARCHAR(40) NOT NULL, residual_value BIGINT NOT NULL, method VARCHAR(12) NOT NULL, years INT DEFAULT NULL, total_units INT DEFAULT NULL, units_used JSON NOT NULL, fiscal_declining TINYINT NOT NULL, components JSON NOT NULL, account_id INT NOT NULL, depreciation_account_id INT DEFAULT NULL, supplier_id INT DEFAULT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_fixed_asset_dossier_code (dossier_id, code), INDEX IDX_6BC872AA9B6B5FBA (account_id), INDEX IDX_6BC872AA59972D4E (depreciation_account_id), INDEX IDX_6BC872AA2ADD6D8C (supplier_id), INDEX IDX_6BC872AA611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fixed_asset ADD CONSTRAINT FK_6BC872AA9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE fixed_asset ADD CONSTRAINT FK_6BC872AA59972D4E FOREIGN KEY (depreciation_account_id) REFERENCES account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE fixed_asset ADD CONSTRAINT FK_6BC872AA2ADD6D8C FOREIGN KEY (supplier_id) REFERENCES third_party (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fixed_asset ADD CONSTRAINT FK_6BC872AA611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixed_asset DROP FOREIGN KEY FK_6BC872AA9B6B5FBA');
        $this->addSql('ALTER TABLE fixed_asset DROP FOREIGN KEY FK_6BC872AA59972D4E');
        $this->addSql('ALTER TABLE fixed_asset DROP FOREIGN KEY FK_6BC872AA2ADD6D8C');
        $this->addSql('ALTER TABLE fixed_asset DROP FOREIGN KEY FK_6BC872AA611C0C56');
        $this->addSql('DROP TABLE fixed_asset');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
