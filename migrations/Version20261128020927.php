<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.7: the dossier's trail (its dossier, its date, what its author was there) and the
 * profile of a group's member. The lines written before name their dossier as their subject: they
 * are placed in it, without the dossier's date nobody kept.
 */
final class Version20261128020927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Piste d’audit du dossier et profils des membres d’un groupe (étape 1.7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_event ADD dossier_id INT DEFAULT NULL, ADD dossier_date DATE DEFAULT NULL, ADD actor_role VARCHAR(20) DEFAULT NULL, ADD actor_profile VARCHAR(30) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_audit_event_dossier ON audit_event (dossier_id, id)');
        $this->addSql("UPDATE audit_event SET dossier_id = CAST(subject_id AS UNSIGNED) WHERE subject_type = 'dossier'");
        $this->addSql('ALTER TABLE dossier_member ADD profile VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_event_dossier ON audit_event');
        $this->addSql('ALTER TABLE audit_event DROP dossier_id, DROP dossier_date, DROP actor_role, DROP actor_profile');
        $this->addSql('ALTER TABLE dossier_member DROP profile');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
