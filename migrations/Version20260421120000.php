<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds is_masked column to avis table for API Ninjas profanity moderation.
 * When an avis is flagged as containing profanity, is_masked is set to 1
 * so it is hidden from public views until an admin reviews it.
 *
 * Idempotent: safe to re-run. Uses INFORMATION_SCHEMA to check the column
 * before adding, so running it after controller auto-heal is a no-op.
 */
final class Version20260421120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_masked boolean column to avis table (profanity moderation).';
    }

    public function up(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'avis'
               AND COLUMN_NAME = 'is_masked'"
        );
        if ($exists === 0) {
            $this->addSql("ALTER TABLE avis ADD COLUMN is_masked TINYINT(1) NOT NULL DEFAULT 0");
        }
    }

    public function down(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'avis'
               AND COLUMN_NAME = 'is_masked'"
        );
        if ($exists === 1) {
            $this->addSql("ALTER TABLE avis DROP COLUMN is_masked");
        }
    }
}
