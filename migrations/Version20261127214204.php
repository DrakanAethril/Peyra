<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.4a: the pending work of the entry grid (« saisie en attente ») - per journal, month and
 * author, the groups typed and not yet saved to the brouillard, with a revision against stale tabs.
 */
final class Version20261127214204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Saisies en attente de la grille de saisie';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pending_entry (id INT AUTO_INCREMENT NOT NULL, revision INT NOT NULL, typed_groups LONGTEXT NOT NULL, saved_at DATETIME NOT NULL, journal_id INT NOT NULL, period_id INT NOT NULL, author_id INT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_pending_entry (journal_id, period_id, author_id), INDEX IDX_B4B41F78478E8802 (journal_id), INDEX IDX_B4B41F78EC8B7ADE (period_id), INDEX IDX_B4B41F78F675F31B (author_id), INDEX IDX_B4B41F78611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE pending_entry ADD CONSTRAINT FK_B4B41F78478E8802 FOREIGN KEY (journal_id) REFERENCES journal (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pending_entry ADD CONSTRAINT FK_B4B41F78EC8B7ADE FOREIGN KEY (period_id) REFERENCES period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pending_entry ADD CONSTRAINT FK_B4B41F78F675F31B FOREIGN KEY (author_id) REFERENCES user_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pending_entry ADD CONSTRAINT FK_B4B41F78611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pending_entry DROP FOREIGN KEY FK_B4B41F78478E8802');
        $this->addSql('ALTER TABLE pending_entry DROP FOREIGN KEY FK_B4B41F78EC8B7ADE');
        $this->addSql('ALTER TABLE pending_entry DROP FOREIGN KEY FK_B4B41F78F675F31B');
        $this->addSql('ALTER TABLE pending_entry DROP FOREIGN KEY FK_B4B41F78611C0C56');
        $this->addSql('DROP TABLE pending_entry');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
