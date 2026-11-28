<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bulletins du mois : éléments saisis et état, un par salarié et par mois (étape 4.4a)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE monthly_payslip (id INT AUTO_INCREMENT NOT NULL, month DATE NOT NULL, status VARCHAR(16) NOT NULL, elements JSON NOT NULL, employee_id INT NOT NULL, dossier_id INT NOT NULL, UNIQUE INDEX uniq_monthly_payslip_employee_month (employee_id, month), INDEX IDX_9646765B8C03F15C (employee_id), INDEX IDX_9646765B611C0C56 (dossier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE monthly_payslip ADD CONSTRAINT FK_9646765B8C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE monthly_payslip ADD CONSTRAINT FK_9646765B611C0C56 FOREIGN KEY (dossier_id) REFERENCES dossier (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monthly_payslip DROP FOREIGN KEY FK_9646765B8C03F15C');
        $this->addSql('ALTER TABLE monthly_payslip DROP FOREIGN KEY FK_9646765B611C0C56');
        $this->addSql('DROP TABLE monthly_payslip');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
