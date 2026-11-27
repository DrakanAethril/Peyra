<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 0.4: accounts, teachers authorised by name, the sign-in and administration journals.
 */
final class Version20261127145842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes, enseignants autorisés nommément, journaux des connexions et d’administration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE administration_event (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, action VARCHAR(40) NOT NULL, subject_login VARCHAR(190) NOT NULL, subject_name VARCHAR(190) NOT NULL, actor_id INT NOT NULL, INDEX idx_administration_event_occurred (occurred_at), INDEX IDX_84F23CF510DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE authorized_teacher (id INT AUTO_INCREMENT NOT NULL, directory_id VARCHAR(190) NOT NULL, login VARCHAR(190) NOT NULL, display_name VARCHAR(190) NOT NULL, added_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, added_by_id INT NOT NULL, revoked_by_id INT DEFAULT NULL, INDEX idx_authorized_teacher_directory (directory_id), INDEX IDX_4732CC5A55B127A4 (added_by_id), INDEX IDX_4732CC5AFB8FE773 (revoked_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE login_event (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, login VARCHAR(190) NOT NULL, display_name VARCHAR(190) DEFAULT NULL, outcome VARCHAR(40) NOT NULL, ip VARCHAR(45) DEFAULT NULL, user_id INT DEFAULT NULL, INDEX idx_login_event_occurred (occurred_at), INDEX IDX_3DDECD33A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_account (id INT AUTO_INCREMENT NOT NULL, directory_id VARCHAR(190) NOT NULL, login VARCHAR(190) NOT NULL, display_name VARCHAR(190) NOT NULL, email VARCHAR(190) DEFAULT NULL, kind VARCHAR(16) NOT NULL, entry_basis VARCHAR(32) NOT NULL, directory_groups JSON NOT NULL, administrator TINYINT NOT NULL, administrator_since DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, administrator_designated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_253B48AE2C94069F (directory_id), INDEX idx_user_account_login (login), INDEX IDX_253B48AE63D2700C (administrator_designated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE administration_event ADD CONSTRAINT FK_84F23CF510DAF24A FOREIGN KEY (actor_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE authorized_teacher ADD CONSTRAINT FK_4732CC5A55B127A4 FOREIGN KEY (added_by_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE authorized_teacher ADD CONSTRAINT FK_4732CC5AFB8FE773 FOREIGN KEY (revoked_by_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE login_event ADD CONSTRAINT FK_3DDECD33A76ED395 FOREIGN KEY (user_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_account ADD CONSTRAINT FK_253B48AE63D2700C FOREIGN KEY (administrator_designated_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE administration_event DROP FOREIGN KEY FK_84F23CF510DAF24A');
        $this->addSql('ALTER TABLE authorized_teacher DROP FOREIGN KEY FK_4732CC5A55B127A4');
        $this->addSql('ALTER TABLE authorized_teacher DROP FOREIGN KEY FK_4732CC5AFB8FE773');
        $this->addSql('ALTER TABLE login_event DROP FOREIGN KEY FK_3DDECD33A76ED395');
        $this->addSql('ALTER TABLE user_account DROP FOREIGN KEY FK_253B48AE63D2700C');
        $this->addSql('DROP TABLE administration_event');
        $this->addSql('DROP TABLE authorized_teacher');
        $this->addSql('DROP TABLE login_event');
        $this->addSql('DROP TABLE user_account');
    }
}
