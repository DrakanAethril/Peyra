<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128131447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stocks : inventaires physiques et ajustements qu’ils font (2.5b)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock_count (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, location VARCHAR(40) NOT NULL, state VARCHAR(12) NOT NULL, count_lines JSON NOT NULL, dossier_id INT NOT NULL, INDEX IDX_19C95893611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE stock_count ADD CONSTRAINT FK_19C95893611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_movement ADD stock_count_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B59CE6EC8 FOREIGN KEY (stock_count_id) REFERENCES stock_count (id)');
        $this->addSql('CREATE INDEX IDX_BB1BC1B59CE6EC8 ON stock_movement (stock_count_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP FOREIGN KEY FK_BB1BC1B59CE6EC8');
        $this->addSql('DROP INDEX IDX_BB1BC1B59CE6EC8 ON stock_movement');
        $this->addSql('ALTER TABLE stock_movement DROP stock_count_id');
        $this->addSql('ALTER TABLE stock_count DROP FOREIGN KEY FK_19C95893611C0C56');
        $this->addSql('DROP TABLE stock_count');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
