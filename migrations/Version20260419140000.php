<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout table face_descriptor (descripteur facial 128D pour reconnaissance par caméra)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE face_descriptor (
            id              INT AUTO_INCREMENT NOT NULL,
            utilisateur_id  INT NOT NULL,
            descriptor      JSON NOT NULL,
            created_at      DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_FACE_UTILISATEUR (utilisateur_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->addSql('ALTER TABLE face_descriptor
            ADD CONSTRAINT FK_FACE_UTILISATEUR
            FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE face_descriptor DROP FOREIGN KEY FK_FACE_UTILISATEUR');
        $this->addSql('DROP TABLE face_descriptor');
    }
}
