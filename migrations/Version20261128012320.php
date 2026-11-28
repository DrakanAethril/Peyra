<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step 1.6: the dossier's date a line was lettered on (the FEC's DateLet), beside its letter.
 */
final class Version20261128012320 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Date de lettrage d’une ligne (étape 1.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry_line ADD lettered_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry_line DROP lettered_on');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
