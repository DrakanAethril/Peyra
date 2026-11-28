<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128055115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paie (étape 4.2a) : salariés et conditions datées de leur contrat';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE employee (id INT AUTO_INCREMENT NOT NULL, registration_number VARCHAR(10) NOT NULL, last_name VARCHAR(80) NOT NULL, first_name VARCHAR(80) NOT NULL, sex VARCHAR(1) NOT NULL, birth_date DATE NOT NULL, birth_place VARCHAR(80) DEFAULT NULL, nationality VARCHAR(40) NOT NULL, nir VARCHAR(15) DEFAULT NULL, street VARCHAR(120) DEFAULT NULL, postal_code VARCHAR(10) DEFAULT NULL, city VARCHAR(80) DEFAULT NULL, withholding_rate VARCHAR(8) DEFAULT NULL, contract_type VARCHAR(20) NOT NULL, hired_on DATE NOT NULL, contract_ends_on DATE DEFAULT NULL, fixed_term_reason VARCHAR(160) DEFAULT NULL, diploma VARCHAR(120) DEFAULT NULL, training_centre VARCHAR(120) DEFAULT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_employee_number (dossier_id, registration_number), INDEX IDX_5D9F75A1611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE employment_terms (id INT AUTO_INCREMENT NOT NULL, effective_from DATE NOT NULL, job_title VARCHAR(80) NOT NULL, classification VARCHAR(120) NOT NULL, executive TINYINT NOT NULL, working_time VARCHAR(16) NOT NULL, monthly_hours VARCHAR(12) NOT NULL, base_salary BIGINT NOT NULL, employee_id INT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_employment_terms_day (employee_id, effective_from), INDEX IDX_ECEF1C108C03F15C (employee_id), INDEX IDX_ECEF1C10611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE employee ADD CONSTRAINT FK_5D9F75A1611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE employment_terms ADD CONSTRAINT FK_ECEF1C108C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE employment_terms ADD CONSTRAINT FK_ECEF1C10611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE employee DROP FOREIGN KEY FK_5D9F75A1611C0C56');
        $this->addSql('ALTER TABLE employment_terms DROP FOREIGN KEY FK_ECEF1C108C03F15C');
        $this->addSql('ALTER TABLE employment_terms DROP FOREIGN KEY FK_ECEF1C10611C0C56');
        $this->addSql('DROP TABLE employee');
        $this->addSql('DROP TABLE employment_terms');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
