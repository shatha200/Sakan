<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute vues/vues_palier a annonce et cree la table notification';
    }

    public function up(Schema $schema): void
    {
        $cols = $this->connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'annonce'"
        );
        $cols = array_map('strtolower', array_map('strval', $cols));
        if (!in_array('vues', $cols, true)) {
            $this->addSql('ALTER TABLE annonce ADD COLUMN vues INT NOT NULL DEFAULT 0');
        }
        if (!in_array('vues_palier', $cols, true)) {
            $this->addSql('ALTER TABLE annonce ADD COLUMN vues_palier INT NOT NULL DEFAULT 0');
        }

        $tables = $this->connection->fetchFirstColumn(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification'"
        );
        if (empty($tables)) {
            $this->addSql('
                CREATE TABLE notification (
                    id INT AUTO_INCREMENT NOT NULL,
                    destinataire_id INT NOT NULL,
                    annonce_id INT DEFAULT NULL,
                    type VARCHAR(50) NOT NULL,
                    message VARCHAR(500) NOT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    INDEX idx_notif_dest (destinataire_id, is_read),
                    INDEX idx_notif_annonce (annonce_id),
                    PRIMARY KEY (id),
                    CONSTRAINT fk_notif_dest FOREIGN KEY (destinataire_id) REFERENCES utilisateur(id) ON DELETE CASCADE,
                    CONSTRAINT fk_notif_annonce FOREIGN KEY (annonce_id) REFERENCES annonce(id) ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB
            ');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS notification');
        $this->addSql('ALTER TABLE annonce DROP COLUMN IF EXISTS vues');
        $this->addSql('ALTER TABLE annonce DROP COLUMN IF EXISTS vues_palier');
    }
}
