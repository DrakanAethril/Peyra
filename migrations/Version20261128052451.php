<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128052451 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paie (étape 4.1) : établissement, organismes et plan de paie du dossier';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payroll_body (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, name VARCHAR(80) NOT NULL, reference VARCHAR(40) NOT NULL, dossier_id INT NOT NULL, INDEX IDX_60889C71611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payroll_establishment (id INT AUTO_INCREMENT NOT NULL, headcount VARCHAR(12) NOT NULL, work_accident_rate VARCHAR(12) NOT NULL, mobility_rate VARCHAR(12) DEFAULT NULL, monthly_hours VARCHAR(12) NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_payroll_establishment_dossier (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payroll_item (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(4) NOT NULL, label VARCHAR(80) NOT NULL, kind VARCHAR(16) NOT NULL, sort_order INT NOT NULL, subtracts TINYINT NOT NULL, base VARCHAR(20) NOT NULL, ceiling VARCHAR(16) NOT NULL, status_condition VARCHAR(16) NOT NULL, headcount_condition VARCHAR(16) NOT NULL, salary_condition VARCHAR(16) NOT NULL, payslip_line VARCHAR(32) DEFAULT NULL, employee_source VARCHAR(24) NOT NULL, employee_reference_key VARCHAR(80) DEFAULT NULL, employee_reference_path VARCHAR(80) DEFAULT NULL, employee_percent VARCHAR(12) DEFAULT NULL, employee_amount BIGINT DEFAULT NULL, employer_source VARCHAR(24) NOT NULL, employer_reference_key VARCHAR(80) DEFAULT NULL, employer_reference_path VARCHAR(80) DEFAULT NULL, employer_percent VARCHAR(12) DEFAULT NULL, employer_amount BIGINT DEFAULT NULL, body_id INT DEFAULT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_payroll_item_dossier_code (dossier_id, code), INDEX IDX_A43BB2DD9B621D84 (body_id), INDEX IDX_A43BB2DD611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE payroll_body ADD CONSTRAINT FK_60889C71611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payroll_establishment ADD CONSTRAINT FK_C050E9A1611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE payroll_item ADD CONSTRAINT FK_A43BB2DD9B621D84 FOREIGN KEY (body_id) REFERENCES payroll_body (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE payroll_item ADD CONSTRAINT FK_A43BB2DD611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payroll_body DROP FOREIGN KEY FK_60889C71611C0C56');
        $this->addSql('ALTER TABLE payroll_establishment DROP FOREIGN KEY FK_C050E9A1611C0C56');
        $this->addSql('ALTER TABLE payroll_item DROP FOREIGN KEY FK_A43BB2DD9B621D84');
        $this->addSql('ALTER TABLE payroll_item DROP FOREIGN KEY FK_A43BB2DD611C0C56');
        $this->addSql('DROP TABLE payroll_body');
        $this->addSql('DROP TABLE payroll_establishment');
        $this->addSql('DROP TABLE payroll_item');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
