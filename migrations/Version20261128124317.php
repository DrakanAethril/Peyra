<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128124317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paie : bulletin modifié à la main, validé (figé), envoyé ; clôture du mois de paie (4.4b)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payroll_month_closure (id INT AUTO_INCREMENT NOT NULL, month DATE NOT NULL, closed_on DATE NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_payroll_month_closure_month (dossier_id, month), INDEX IDX_44045F31611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE payroll_month_closure ADD CONSTRAINT FK_44045F31611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
        // A payslip of step 4.4a has nothing changed by hand: its adjustments are the empty list.
        $this->addSql('ALTER TABLE monthly_payslip ADD adjustments JSON DEFAULT NULL, ADD frozen JSON DEFAULT NULL, ADD validated_on DATE DEFAULT NULL, ADD sent_on DATE DEFAULT NULL');
        $this->addSql("UPDATE monthly_payslip SET adjustments = '[]'");
        $this->addSql('ALTER TABLE monthly_payslip MODIFY adjustments JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payroll_month_closure DROP FOREIGN KEY FK_44045F31611C0C56');
        $this->addSql('DROP TABLE payroll_month_closure');
        $this->addSql('ALTER TABLE monthly_payslip DROP adjustments, DROP frozen, DROP validated_on, DROP sent_on');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
