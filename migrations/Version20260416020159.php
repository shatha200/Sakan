<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416020159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image_paths + adresse_text columns on annonce and create bienimmobilier table for the ported annonce module.';
    }

    public function up(Schema $schema): void
    {
        $db = (string) $this->connection->fetchOne('SELECT DATABASE()');

        $hasImagePaths = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['db' => $db, 't' => 'annonce', 'c' => 'image_paths']
        );
        if ($hasImagePaths === 0) {
            $this->addSql('ALTER TABLE annonce ADD COLUMN image_paths TEXT NULL');
        }

        $hasAdresseText = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['db' => $db, 't' => 'annonce', 'c' => 'adresse_text']
        );
        if ($hasAdresseText === 0) {
            $this->addSql('ALTER TABLE annonce ADD COLUMN adresse_text VARCHAR(255) NULL');
        }

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS bienimmobilier (
                id INT AUTO_INCREMENT PRIMARY KEY,
                annonceId INT NOT NULL,
                type VARCHAR(50) NULL,
                superficie FLOAT NULL,
                nombreChambres INT NULL,
                adresse TEXT NULL,
                equipements TEXT NULL,
                INDEX idx_bienimmobilier_annonce (annonceId),
                CONSTRAINT fk_bienimmobilier_annonce FOREIGN KEY (annonceId) REFERENCES annonce(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS bienimmobilier');
        $this->addSql('ALTER TABLE annonce DROP COLUMN IF EXISTS adresse_text');
        $this->addSql('ALTER TABLE annonce DROP COLUMN IF EXISTS image_paths');
    }
}
