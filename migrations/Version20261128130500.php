<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128130500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paie : comptes de l’écriture de paie sur les rubriques et les organismes (étape 4.5)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payroll_body ADD account VARCHAR(10) DEFAULT NULL, ADD charge_account VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE payroll_item ADD account VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payroll_body DROP account, DROP charge_account');
        $this->addSql('ALTER TABLE payroll_item DROP account');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
