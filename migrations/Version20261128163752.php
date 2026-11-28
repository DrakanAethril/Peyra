<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Missions (2.9b) : le corrigé d'une mission et l'avancement de chaque dossier (tâches cochées, rendu). */
final class Version20261128163752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Missions (2.9b) : corrigé, tâches cochées et rendu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mission_answer_key (id INT AUTO_INCREMENT NOT NULL, baseline JSON NOT NULL, entries JSON NOT NULL, pieces JSON NOT NULL, recorded_at DATETIME DEFAULT NULL, equivalent_accounts JSON NOT NULL, date_window_days SMALLINT NOT NULL, created_at DATETIME NOT NULL, mission_id INT NOT NULL, dossier_id INT DEFAULT NULL, recorded_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_3D8A484ABE6CAE90 (mission_id), UNIQUE INDEX UNIQ_3D8A484A611C0C56 (dossier_id), INDEX IDX_3D8A484AD05A957B (recorded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mission_progress (id INT AUTO_INCREMENT NOT NULL, declared_tasks JSON NOT NULL, submitted_at DATETIME DEFAULT NULL, assignment_id INT NOT NULL, dossier_id INT NOT NULL, submitted_by_id INT DEFAULT NULL, restore_point_id INT DEFAULT NULL, UNIQUE INDEX uniq_mission_progress (assignment_id, dossier_id), INDEX IDX_2AFDF3FFD19302F8 (assignment_id), INDEX IDX_2AFDF3FF611C0C56 (dossier_id), INDEX IDX_2AFDF3FF79F7D87D (submitted_by_id), INDEX IDX_2AFDF3FFEA20EED8 (restore_point_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mission_answer_key ADD CONSTRAINT FK_3D8A484ABE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mission_answer_key ADD CONSTRAINT FK_3D8A484A611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission_answer_key ADD CONSTRAINT FK_3D8A484AD05A957B FOREIGN KEY (recorded_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission_progress ADD CONSTRAINT FK_2AFDF3FFD19302F8 FOREIGN KEY (assignment_id) REFERENCES mission_assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mission_progress ADD CONSTRAINT FK_2AFDF3FF611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mission_progress ADD CONSTRAINT FK_2AFDF3FF79F7D87D FOREIGN KEY (submitted_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission_progress ADD CONSTRAINT FK_2AFDF3FFEA20EED8 FOREIGN KEY (restore_point_id) REFERENCES restore_point (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission_answer_key DROP FOREIGN KEY FK_3D8A484ABE6CAE90');
        $this->addSql('ALTER TABLE mission_answer_key DROP FOREIGN KEY FK_3D8A484A611C0C56');
        $this->addSql('ALTER TABLE mission_answer_key DROP FOREIGN KEY FK_3D8A484AD05A957B');
        $this->addSql('ALTER TABLE mission_progress DROP FOREIGN KEY FK_2AFDF3FFD19302F8');
        $this->addSql('ALTER TABLE mission_progress DROP FOREIGN KEY FK_2AFDF3FF611C0C56');
        $this->addSql('ALTER TABLE mission_progress DROP FOREIGN KEY FK_2AFDF3FF79F7D87D');
        $this->addSql('ALTER TABLE mission_progress DROP FOREIGN KEY FK_2AFDF3FFEA20EED8');
        $this->addSql('DROP TABLE mission_answer_key');
        $this->addSql('DROP TABLE mission_progress');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
