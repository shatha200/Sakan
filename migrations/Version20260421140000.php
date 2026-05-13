<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds slug column to annonce table for StofDoctrineExtensionsBundle (Sluggable).
 * Column is nullable so existing rows (which have no slug yet) don't break;
 * new rows get their slug computed by the controller (or by Gedmo listener
 * when the row is persisted via the ORM).
 *
 * Idempotent: checks INFORMATION_SCHEMA before touching the table.
 */
final class Version20260421140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slug column (unique, nullable) to annonce table for Sluggable.';
    }

    public function up(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'annonce'
               AND COLUMN_NAME = 'slug'"
        );
        if ($exists === 0) {
            $this->addSql("ALTER TABLE annonce ADD COLUMN slug VARCHAR(255) NULL");
            $this->addSql("CREATE UNIQUE INDEX UNIQ_annonce_slug ON annonce (slug)");
        }
    }

    public function down(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'annonce'
               AND COLUMN_NAME = 'slug'"
        );
        if ($exists === 1) {
            $this->addSql("DROP INDEX UNIQ_annonce_slug ON annonce");
            $this->addSql("ALTER TABLE annonce DROP COLUMN slug");
        }
    }
}
