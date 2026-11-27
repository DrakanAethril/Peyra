<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 0.5: school years, classes, enrolments, teachers of a class, mapping of the directory groups;
 * on the account, the sort name, the groups read at creation, the placement at creation and the
 * departure from the field; a detail on the administration journal.
 */
final class Version20261127153203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Années scolaires, classes, inscriptions, enseignants des classes, correspondance avec l’annuaire';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE directory_group_mapping (id INT AUTO_INCREMENT NOT NULL, directory_group VARCHAR(64) NOT NULL, school_year_id INT NOT NULL, school_class_id INT NOT NULL, UNIQUE INDEX uniq_directory_group_mapping (school_year_id, directory_group), INDEX IDX_777619D7D2EECC3F (school_year_id), INDEX IDX_777619D714463F54 (school_class_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE enrolment (id INT AUTO_INCREMENT NOT NULL, origin VARCHAR(16) NOT NULL, enrolled_at DATETIME NOT NULL, student_id INT NOT NULL, school_year_id INT NOT NULL, school_class_id INT NOT NULL, enrolled_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_enrolment_student_year (student_id, school_year_id), INDEX IDX_C04D5114CB944F1A (student_id), INDEX IDX_C04D5114D2EECC3F (school_year_id), INDEX IDX_C04D511414463F54 (school_class_id), INDEX IDX_C04D511421EE0EA4 (enrolled_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE school_class (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, level SMALLINT NOT NULL, created_at DATETIME NOT NULL, school_year_id INT NOT NULL, next_class_id INT DEFAULT NULL, UNIQUE INDEX uniq_school_class_year_name (school_year_id, name), INDEX IDX_33B1AF85D2EECC3F (school_year_id), INDEX IDX_33B1AF85D21A47FE (next_class_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE school_year (id INT AUTO_INCREMENT NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, is_current TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_school_year_starts_on (starts_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher_class (id INT AUTO_INCREMENT NOT NULL, added_at DATETIME NOT NULL, teacher_id INT NOT NULL, school_class_id INT NOT NULL, added_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_teacher_class (teacher_id, school_class_id), INDEX IDX_BCB4A5C241807E1D (teacher_id), INDEX IDX_BCB4A5C214463F54 (school_class_id), INDEX IDX_BCB4A5C255B127A4 (added_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE directory_group_mapping ADD CONSTRAINT FK_777619D7D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id)');
        $this->addSql('ALTER TABLE directory_group_mapping ADD CONSTRAINT FK_777619D714463F54 FOREIGN KEY (school_class_id) REFERENCES school_class (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE enrolment ADD CONSTRAINT FK_C04D5114CB944F1A FOREIGN KEY (student_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE enrolment ADD CONSTRAINT FK_C04D5114D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id)');
        $this->addSql('ALTER TABLE enrolment ADD CONSTRAINT FK_C04D511414463F54 FOREIGN KEY (school_class_id) REFERENCES school_class (id)');
        $this->addSql('ALTER TABLE enrolment ADD CONSTRAINT FK_C04D511421EE0EA4 FOREIGN KEY (enrolled_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE school_class ADD CONSTRAINT FK_33B1AF85D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id)');
        $this->addSql('ALTER TABLE school_class ADD CONSTRAINT FK_33B1AF85D21A47FE FOREIGN KEY (next_class_id) REFERENCES school_class (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE teacher_class ADD CONSTRAINT FK_BCB4A5C241807E1D FOREIGN KEY (teacher_id) REFERENCES user_account (id)');
        $this->addSql('ALTER TABLE teacher_class ADD CONSTRAINT FK_BCB4A5C214463F54 FOREIGN KEY (school_class_id) REFERENCES school_class (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_class ADD CONSTRAINT FK_BCB4A5C255B127A4 FOREIGN KEY (added_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE administration_event ADD detail VARCHAR(255) DEFAULT NULL');
        // Existing accounts: the sort name starts as the display name (rewritten at the next sign-in),
        // the groups at creation as the groups of the last sign-in; then the columns become NOT NULL.
        $this->addSql('ALTER TABLE user_account ADD sort_name VARCHAR(190) DEFAULT NULL, ADD groups_at_creation JSON DEFAULT NULL, ADD initial_placement VARCHAR(32) DEFAULT NULL, ADD departed_at DATETIME DEFAULT NULL, ADD departed_by_id INT DEFAULT NULL');
        $this->addSql('UPDATE user_account SET sort_name = display_name, groups_at_creation = directory_groups');
        $this->addSql('ALTER TABLE user_account MODIFY sort_name VARCHAR(190) NOT NULL, MODIFY groups_at_creation JSON NOT NULL');
        $this->addSql('ALTER TABLE user_account ADD CONSTRAINT FK_253B48AECB1AB4DA FOREIGN KEY (departed_by_id) REFERENCES user_account (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_253B48AECB1AB4DA ON user_account (departed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE directory_group_mapping DROP FOREIGN KEY FK_777619D7D2EECC3F');
        $this->addSql('ALTER TABLE directory_group_mapping DROP FOREIGN KEY FK_777619D714463F54');
        $this->addSql('ALTER TABLE enrolment DROP FOREIGN KEY FK_C04D5114CB944F1A');
        $this->addSql('ALTER TABLE enrolment DROP FOREIGN KEY FK_C04D5114D2EECC3F');
        $this->addSql('ALTER TABLE enrolment DROP FOREIGN KEY FK_C04D511414463F54');
        $this->addSql('ALTER TABLE enrolment DROP FOREIGN KEY FK_C04D511421EE0EA4');
        $this->addSql('ALTER TABLE school_class DROP FOREIGN KEY FK_33B1AF85D2EECC3F');
        $this->addSql('ALTER TABLE school_class DROP FOREIGN KEY FK_33B1AF85D21A47FE');
        $this->addSql('ALTER TABLE teacher_class DROP FOREIGN KEY FK_BCB4A5C241807E1D');
        $this->addSql('ALTER TABLE teacher_class DROP FOREIGN KEY FK_BCB4A5C214463F54');
        $this->addSql('ALTER TABLE teacher_class DROP FOREIGN KEY FK_BCB4A5C255B127A4');
        $this->addSql('DROP TABLE directory_group_mapping');
        $this->addSql('DROP TABLE enrolment');
        $this->addSql('DROP TABLE school_class');
        $this->addSql('DROP TABLE school_year');
        $this->addSql('DROP TABLE teacher_class');
        $this->addSql('ALTER TABLE administration_event DROP detail');
        $this->addSql('ALTER TABLE user_account DROP FOREIGN KEY FK_253B48AECB1AB4DA');
        $this->addSql('DROP INDEX IDX_253B48AECB1AB4DA ON user_account');
        $this->addSql('ALTER TABLE user_account DROP sort_name, DROP groups_at_creation, DROP initial_placement, DROP departed_at, DROP departed_by_id');
    }
}
