<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.1: the dossier (its company embedded, its date, its origin), its exercises and monthly
 * periods, the history of its date, and who enters it besides its owner (group members, shares of
 * a template).
 */
final class Version20261127192919 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dossiers, exercices, périodes, date du dossier, membres et partages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dossier (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL, dossier_date DATE NOT NULL, date_moved_at DATETIME DEFAULT NULL, date_move_unseen TINYINT NOT NULL, created_at DATETIME NOT NULL, last_visited_at DATETIME DEFAULT NULL, company_denomination VARCHAR(120) NOT NULL, company_legal_form VARCHAR(16) NOT NULL, company_capital BIGINT NOT NULL, company_siret VARCHAR(14) NOT NULL, company_vat_number VARCHAR(16) DEFAULT NULL, company_naf_code VARCHAR(6) DEFAULT NULL, company_activity LONGTEXT NOT NULL, company_street VARCHAR(120) NOT NULL, company_street2 VARCHAR(120) DEFAULT NULL, company_postal_code VARCHAR(5) NOT NULL, company_city VARCHAR(80) NOT NULL, company_vat_regime VARCHAR(32) NOT NULL, company_profit_tax_regime VARCHAR(32) NOT NULL, owner_id INT DEFAULT NULL, school_year_id INT DEFAULT NULL, source_template_id INT DEFAULT NULL, distributed_by_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX idx_dossier_kind (kind), INDEX IDX_3D48E0377E3C61F9 (owner_id), INDEX IDX_3D48E037D2EECC3F (school_year_id), INDEX IDX_3D48E03739A55F18 (source_template_id), INDEX IDX_3D48E03765F14916 (distributed_by_id), INDEX IDX_3D48E037B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dossier_date_change (id INT AUTO_INCREMENT NOT NULL, from_date DATE NOT NULL, to_date DATE NOT NULL, changed_at DATETIME NOT NULL, actor_name VARCHAR(190) NOT NULL, changed_by_id INT DEFAULT NULL, dossier_id INT NOT NULL, INDEX idx_dossier_date_change_at (dossier_id, changed_at), INDEX IDX_EFC96FC0828AD0A0 (changed_by_id), INDEX IDX_EFC96FC0611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dossier_member (id INT AUTO_INCREMENT NOT NULL, added_at DATETIME NOT NULL, dossier_id INT NOT NULL, student_id INT NOT NULL, UNIQUE INDEX uniq_dossier_member (dossier_id, student_id), INDEX IDX_9895AE51611C0C56 (dossier_id), INDEX IDX_9895AE51CB944F1A (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dossier_share (id INT AUTO_INCREMENT NOT NULL, can_edit TINYINT NOT NULL, shared_at DATETIME NOT NULL, dossier_id INT NOT NULL, teacher_id INT NOT NULL, UNIQUE INDEX uniq_dossier_share (dossier_id, teacher_id), INDEX IDX_2686871D611C0C56 (dossier_id), INDEX IDX_2686871D41807E1D (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE exercise (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(16) NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, closed TINYINT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_exercise_dossier_code (dossier_id, code), INDEX IDX_AEDAD51C611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE period (id INT AUTO_INCREMENT NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, state VARCHAR(8) NOT NULL, exercise_id INT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_period_exercise_start (exercise_id, starts_on), INDEX IDX_C5B81ECEE934951A (exercise_id), INDEX IDX_C5B81ECE611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dossier ADD CONSTRAINT FK_3D48E0377E3C61F9 FOREIGN KEY (owner_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE dossier ADD CONSTRAINT FK_3D48E037D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id)');
        $this->addSql('ALTER TABLE dossier ADD CONSTRAINT FK_3D48E03739A55F18 FOREIGN KEY (source_template_id) REFERENCES dossier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dossier ADD CONSTRAINT FK_3D48E03765F14916 FOREIGN KEY (distributed_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dossier ADD CONSTRAINT FK_3D48E037B03A8386 FOREIGN KEY (created_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dossier_date_change ADD CONSTRAINT FK_EFC96FC0828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dossier_date_change ADD CONSTRAINT FK_EFC96FC0611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dossier_member ADD CONSTRAINT FK_9895AE51611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dossier_member ADD CONSTRAINT FK_9895AE51CB944F1A FOREIGN KEY (student_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE dossier_share ADD CONSTRAINT FK_2686871D611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dossier_share ADD CONSTRAINT FK_2686871D41807E1D FOREIGN KEY (teacher_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE exercise ADD CONSTRAINT FK_AEDAD51C611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE period ADD CONSTRAINT FK_C5B81ECEE934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE period ADD CONSTRAINT FK_C5B81ECE611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier DROP FOREIGN KEY FK_3D48E0377E3C61F9');
        $this->addSql('ALTER TABLE dossier DROP FOREIGN KEY FK_3D48E037D2EECC3F');
        $this->addSql('ALTER TABLE dossier DROP FOREIGN KEY FK_3D48E03739A55F18');
        $this->addSql('ALTER TABLE dossier DROP FOREIGN KEY FK_3D48E03765F14916');
        $this->addSql('ALTER TABLE dossier DROP FOREIGN KEY FK_3D48E037B03A8386');
        $this->addSql('ALTER TABLE dossier_date_change DROP FOREIGN KEY FK_EFC96FC0828AD0A0');
        $this->addSql('ALTER TABLE dossier_date_change DROP FOREIGN KEY FK_EFC96FC0611C0C56');
        $this->addSql('ALTER TABLE dossier_member DROP FOREIGN KEY FK_9895AE51611C0C56');
        $this->addSql('ALTER TABLE dossier_member DROP FOREIGN KEY FK_9895AE51CB944F1A');
        $this->addSql('ALTER TABLE dossier_share DROP FOREIGN KEY FK_2686871D611C0C56');
        $this->addSql('ALTER TABLE dossier_share DROP FOREIGN KEY FK_2686871D41807E1D');
        $this->addSql('ALTER TABLE exercise DROP FOREIGN KEY FK_AEDAD51C611C0C56');
        $this->addSql('ALTER TABLE period DROP FOREIGN KEY FK_C5B81ECEE934951A');
        $this->addSql('ALTER TABLE period DROP FOREIGN KEY FK_C5B81ECE611C0C56');
        $this->addSql('DROP TABLE dossier');
        $this->addSql('DROP TABLE dossier_date_change');
        $this->addSql('DROP TABLE dossier_member');
        $this->addSql('DROP TABLE dossier_share');
        $this->addSql('DROP TABLE exercise');
        $this->addSql('DROP TABLE period');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
