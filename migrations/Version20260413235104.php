<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds reservation_id FK to the contrat table.
 */
final class Version20260413235104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reservation_id column to contrat table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contrat ADD reservation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contrat ADD CONSTRAINT FK_contrat_reservation FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_contrat_reservation ON contrat (reservation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contrat DROP FOREIGN KEY FK_contrat_reservation');
        $this->addSql('DROP INDEX IDX_contrat_reservation ON contrat');
        $this->addSql('ALTER TABLE contrat DROP reservation_id');
    }
}
