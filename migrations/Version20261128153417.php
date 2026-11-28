<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128153417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Missions (2.9a) : la mission de la bibliothèque et les distributions à qui elle est donnée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mission (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, statement LONGTEXT NOT NULL, tasks JSON NOT NULL, activities JSON NOT NULL, modules JSON NOT NULL, assistance_level VARCHAR(16) NOT NULL, behaviour VARCHAR(16) NOT NULL, guided_entry_open TINYINT NOT NULL, created_at DATETIME NOT NULL, template_id INT DEFAULT NULL, author_id INT NOT NULL, INDEX IDX_9067F23C5DA0FB8 (template_id), INDEX IDX_9067F23CF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mission_assignment (id INT AUTO_INCREMENT NOT NULL, given_at DATETIME NOT NULL, due_at DATETIME NOT NULL, mission_id INT NOT NULL, distribution_id INT NOT NULL, given_by_id INT NOT NULL, UNIQUE INDEX uniq_mission_assignment (mission_id, distribution_id), INDEX IDX_72C56921BE6CAE90 (mission_id), INDEX IDX_72C569216EB6DDB5 (distribution_id), INDEX IDX_72C569216EBC53EE (given_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23C5DA0FB8 FOREIGN KEY (template_id) REFERENCES dossier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mission ADD CONSTRAINT FK_9067F23CF675F31B FOREIGN KEY (author_id) REFERENCES user_account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE mission_assignment ADD CONSTRAINT FK_72C56921BE6CAE90 FOREIGN KEY (mission_id) REFERENCES mission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mission_assignment ADD CONSTRAINT FK_72C569216EB6DDB5 FOREIGN KEY (distribution_id) REFERENCES distribution (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mission_assignment ADD CONSTRAINT FK_72C569216EBC53EE FOREIGN KEY (given_by_id) REFERENCES user_account (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23C5DA0FB8');
        $this->addSql('ALTER TABLE mission DROP FOREIGN KEY FK_9067F23CF675F31B');
        $this->addSql('ALTER TABLE mission_assignment DROP FOREIGN KEY FK_72C56921BE6CAE90');
        $this->addSql('ALTER TABLE mission_assignment DROP FOREIGN KEY FK_72C569216EB6DDB5');
        $this->addSql('ALTER TABLE mission_assignment DROP FOREIGN KEY FK_72C569216EBC53EE');
        $this->addSql('DROP TABLE mission');
        $this->addSql('DROP TABLE mission_assignment');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
