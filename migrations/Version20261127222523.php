<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.4b: entry templates (« modèles d'écriture ») and their lines.
 */
final class Version20261127222523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modèles d’écriture et leurs lignes (étape 1.4b)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE entry_template (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(12) NOT NULL, label VARCHAR(80) NOT NULL, base VARCHAR(3) DEFAULT NULL, vat_rate VARCHAR(8) DEFAULT NULL, journal_id INT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_entry_template_code (dossier_id, code), INDEX IDX_F927FFCB478E8802 (journal_id), INDEX IDX_F927FFCB611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE entry_template_line (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, account VARCHAR(17) NOT NULL, third_party VARCHAR(17) NOT NULL, label VARCHAR(250) NOT NULL, side VARCHAR(6) NOT NULL, amount VARCHAR(8) NOT NULL, fixed BIGINT DEFAULT NULL, template_id INT NOT NULL, dossier_id INT NOT NULL, INDEX IDX_348D11475DA0FB8 (template_id), INDEX IDX_348D1147611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entry_template ADD CONSTRAINT FK_F927FFCB478E8802 FOREIGN KEY (journal_id) REFERENCES journal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE entry_template ADD CONSTRAINT FK_F927FFCB611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entry_template_line ADD CONSTRAINT FK_348D11475DA0FB8 FOREIGN KEY (template_id) REFERENCES entry_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entry_template_line ADD CONSTRAINT FK_348D1147611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry_template DROP FOREIGN KEY FK_F927FFCB478E8802');
        $this->addSql('ALTER TABLE entry_template DROP FOREIGN KEY FK_F927FFCB611C0C56');
        $this->addSql('ALTER TABLE entry_template_line DROP FOREIGN KEY FK_348D11475DA0FB8');
        $this->addSql('ALTER TABLE entry_template_line DROP FOREIGN KEY FK_348D1147611C0C56');
        $this->addSql('DROP TABLE entry_template');
        $this->addSql('DROP TABLE entry_template_line');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
