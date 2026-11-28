<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128063258 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paie (étape 4.2c) : sortie du salarié (motif, notification, dernier jour du contrat)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE employee ADD exit_reason VARCHAR(30) DEFAULT NULL, ADD exit_notified_on DATE DEFAULT NULL, ADD exit_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE employee DROP exit_reason, DROP exit_notified_on, DROP exit_on');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
