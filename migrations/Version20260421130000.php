<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds caution_mois (1, 2 or 3) column to annonce table so owners
 * can declare the deposit required for a rental.
 *
 * Idempotent: checks INFORMATION_SCHEMA before adding the column.
 * Safe to re-run even if the controller auto-heal already added it.
 */
final class Version20260421130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add caution_mois column (1/2/3 months deposit) to annonce table.';
    }

    public function up(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'annonce'
               AND COLUMN_NAME = 'caution_mois'"
        );
        if ($exists === 0) {
            $this->addSql("ALTER TABLE annonce ADD COLUMN caution_mois SMALLINT NULL");
        }
    }

    public function down(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'annonce'
               AND COLUMN_NAME = 'caution_mois'"
        );
        if ($exists === 1) {
            $this->addSql("ALTER TABLE annonce DROP COLUMN caution_mois");
        }
    }
}
