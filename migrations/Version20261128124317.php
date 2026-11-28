<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128124317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Achats : prix d’achat de l’article (étape 2.4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD purchase_price BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP purchase_price');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
