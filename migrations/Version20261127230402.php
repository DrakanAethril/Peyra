<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.4c: subscriptions (« abonnements »), an entry template called every month.
 */
final class Version20261127230402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Abonnements (étape 1.4c)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE entry_subscription (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(80) NOT NULL, amount BIGINT DEFAULT NULL, vat_rate VARCHAR(8) DEFAULT NULL, third_party VARCHAR(17) NOT NULL, day_of_month INT NOT NULL, first_month DATE NOT NULL, occurrences INT NOT NULL, document_pattern VARCHAR(40) NOT NULL, target VARCHAR(7) NOT NULL, generated_count INT NOT NULL, template_id INT NOT NULL, dossier_id INT NOT NULL, INDEX IDX_41B6513C5DA0FB8 (template_id), INDEX IDX_41B6513C611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entry_subscription ADD CONSTRAINT FK_41B6513C5DA0FB8 FOREIGN KEY (template_id) REFERENCES entry_template (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE entry_subscription ADD CONSTRAINT FK_41B6513C611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry_subscription DROP FOREIGN KEY FK_41B6513C5DA0FB8');
        $this->addSql('ALTER TABLE entry_subscription DROP FOREIGN KEY FK_41B6513C611C0C56');
        $this->addSql('DROP TABLE entry_subscription');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
