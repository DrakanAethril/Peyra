<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.3: the accounting entries and their lines - journal, exercise, period, date, document,
 * label, state and definitive number (unique per journal and exercise), origin, reversal; per
 * line the account, the third party, the amounts in cents, the due date and the lettering letter.
 */
final class Version20261127210414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Écritures et lignes d’écriture';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE entry (id INT AUTO_INCREMENT NOT NULL, entry_date DATE NOT NULL, document VARCHAR(40) NOT NULL, label VARCHAR(200) NOT NULL, state VARCHAR(12) NOT NULL, number INT DEFAULT NULL, validated_on DATE DEFAULT NULL, origin VARCHAR(12) NOT NULL, source VARCHAR(24) DEFAULT NULL, journal_id INT NOT NULL, exercise_id INT NOT NULL, period_id INT NOT NULL, reversal_of_id INT DEFAULT NULL, dossier_id INT NOT NULL, INDEX idx_entry_dossier_date (dossier_id, entry_date), UNIQUE INDEX uniq_entry_number (journal_id, exercise_id, number), UNIQUE INDEX uniq_entry_reversal_of (reversal_of_id), INDEX IDX_2B219D70478E8802 (journal_id), INDEX IDX_2B219D70E934951A (exercise_id), INDEX IDX_2B219D70EC8B7ADE (period_id), INDEX IDX_2B219D70611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE entry_line (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, label VARCHAR(200) NOT NULL, debit BIGINT NOT NULL, credit BIGINT NOT NULL, due_date DATE DEFAULT NULL, letter VARCHAR(4) DEFAULT NULL, entry_id INT NOT NULL, account_id INT NOT NULL, third_party_id INT DEFAULT NULL, dossier_id INT NOT NULL, INDEX idx_entry_line_account (account_id), INDEX idx_entry_line_third_party (third_party_id), INDEX IDX_7536CCE5BA364942 (entry_id), INDEX IDX_7536CCE5611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D70478E8802 FOREIGN KEY (journal_id) REFERENCES journal (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D70E934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D70EC8B7ADE FOREIGN KEY (period_id) REFERENCES period (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D7029A0BB4E FOREIGN KEY (reversal_of_id) REFERENCES entry (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D70611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entry_line ADD CONSTRAINT FK_7536CCE5BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entry_line ADD CONSTRAINT FK_7536CCE59B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE entry_line ADD CONSTRAINT FK_7536CCE554C4149C FOREIGN KEY (third_party_id) REFERENCES third_party (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE entry_line ADD CONSTRAINT FK_7536CCE5611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry DROP FOREIGN KEY FK_2B219D70478E8802');
        $this->addSql('ALTER TABLE entry DROP FOREIGN KEY FK_2B219D70E934951A');
        $this->addSql('ALTER TABLE entry DROP FOREIGN KEY FK_2B219D70EC8B7ADE');
        $this->addSql('ALTER TABLE entry DROP FOREIGN KEY FK_2B219D7029A0BB4E');
        $this->addSql('ALTER TABLE entry DROP FOREIGN KEY FK_2B219D70611C0C56');
        $this->addSql('ALTER TABLE entry_line DROP FOREIGN KEY FK_7536CCE5BA364942');
        $this->addSql('ALTER TABLE entry_line DROP FOREIGN KEY FK_7536CCE59B6B5FBA');
        $this->addSql('ALTER TABLE entry_line DROP FOREIGN KEY FK_7536CCE554C4149C');
        $this->addSql('ALTER TABLE entry_line DROP FOREIGN KEY FK_7536CCE5611C0C56');
        $this->addSql('DROP TABLE entry');
        $this->addSql('DROP TABLE entry_line');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
