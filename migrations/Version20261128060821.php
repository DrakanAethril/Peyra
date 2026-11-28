<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128060821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paie (étape 4.2b) : formalités d’embauche (heure d’embauche, période d’essai, visite d’information, DPAE, affiliations)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE employee_affiliation (id INT AUTO_INCREMENT NOT NULL, affiliated_on DATE NOT NULL, employee_id INT NOT NULL, body_id INT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_employee_affiliation (employee_id, body_id), INDEX IDX_6B54D44D8C03F15C (employee_id), INDEX IDX_6B54D44D9B621D84 (body_id), INDEX IDX_6B54D44D611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hiring_declaration (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(20) NOT NULL, declared_on DATE NOT NULL, late TINYINT NOT NULL, content JSON NOT NULL, employee_id INT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_hiring_declaration_employee (employee_id), UNIQUE INDEX uniq_hiring_declaration_number (dossier_id, number), INDEX IDX_825516C8611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE employee_affiliation ADD CONSTRAINT FK_6B54D44D8C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE employee_affiliation ADD CONSTRAINT FK_6B54D44D9B621D84 FOREIGN KEY (body_id) REFERENCES payroll_body (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE employee_affiliation ADD CONSTRAINT FK_6B54D44D611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hiring_declaration ADD CONSTRAINT FK_825516C88C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hiring_declaration ADD CONSTRAINT FK_825516C8611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE employee ADD hire_time VARCHAR(5) DEFAULT NULL, ADD trial_period VARCHAR(40) DEFAULT NULL, ADD information_visit_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE employee_affiliation DROP FOREIGN KEY FK_6B54D44D8C03F15C');
        $this->addSql('ALTER TABLE employee_affiliation DROP FOREIGN KEY FK_6B54D44D9B621D84');
        $this->addSql('ALTER TABLE employee_affiliation DROP FOREIGN KEY FK_6B54D44D611C0C56');
        $this->addSql('ALTER TABLE hiring_declaration DROP FOREIGN KEY FK_825516C88C03F15C');
        $this->addSql('ALTER TABLE hiring_declaration DROP FOREIGN KEY FK_825516C8611C0C56');
        $this->addSql('DROP TABLE employee_affiliation');
        $this->addSql('DROP TABLE hiring_declaration');
        $this->addSql('ALTER TABLE employee DROP hire_time, DROP trial_period, DROP information_visit_on');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
