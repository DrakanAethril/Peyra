<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.8c: the distributions of a template to a class, and the dossiers each one makes.
 */
final class Version20261128043203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Distributions d’un modèle à une classe et leurs dossiers (étape 1.8c)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE distribution (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, mode VARCHAR(16) NOT NULL, start_date DATE NOT NULL, created_at DATETIME NOT NULL, template_id INT DEFAULT NULL, author_id INT DEFAULT NULL, school_class_id INT NOT NULL, INDEX IDX_A44837815DA0FB8 (template_id), INDEX IDX_A4483781F675F31B (author_id), INDEX IDX_A448378114463F54 (school_class_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE distribution_copy (id INT AUTO_INCREMENT NOT NULL, group_name VARCHAR(40) DEFAULT NULL, members JSON NOT NULL, state VARCHAR(16) NOT NULL, failure VARCHAR(255) DEFAULT NULL, finished_at DATETIME DEFAULT NULL, distribution_id INT NOT NULL, student_id INT DEFAULT NULL, dossier_id INT DEFAULT NULL, INDEX idx_distribution_copy_state (state), INDEX IDX_450737366EB6DDB5 (distribution_id), INDEX IDX_45073736CB944F1A (student_id), INDEX IDX_45073736611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE distribution ADD CONSTRAINT FK_A44837815DA0FB8 FOREIGN KEY (template_id) REFERENCES dossier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE distribution ADD CONSTRAINT FK_A4483781F675F31B FOREIGN KEY (author_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE distribution ADD CONSTRAINT FK_A448378114463F54 FOREIGN KEY (school_class_id) REFERENCES school_class (id)');
        $this->addSql('ALTER TABLE distribution_copy ADD CONSTRAINT FK_450737366EB6DDB5 FOREIGN KEY (distribution_id) REFERENCES distribution (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE distribution_copy ADD CONSTRAINT FK_45073736CB944F1A FOREIGN KEY (student_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE distribution_copy ADD CONSTRAINT FK_45073736611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE distribution DROP FOREIGN KEY FK_A44837815DA0FB8');
        $this->addSql('ALTER TABLE distribution DROP FOREIGN KEY FK_A4483781F675F31B');
        $this->addSql('ALTER TABLE distribution DROP FOREIGN KEY FK_A448378114463F54');
        $this->addSql('ALTER TABLE distribution_copy DROP FOREIGN KEY FK_450737366EB6DDB5');
        $this->addSql('ALTER TABLE distribution_copy DROP FOREIGN KEY FK_45073736CB944F1A');
        $this->addSql('ALTER TABLE distribution_copy DROP FOREIGN KEY FK_45073736611C0C56');
        $this->addSql('DROP TABLE distribution');
        $this->addSql('DROP TABLE distribution_copy');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
