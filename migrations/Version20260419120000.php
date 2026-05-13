<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout table webauthn_credential (authentification biométrique FIDO2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE webauthn_credential (
            id              INT AUTO_INCREMENT NOT NULL,
            utilisateur_id  INT NOT NULL,
            credential_id   VARCHAR(512) NOT NULL,
            public_key_data LONGTEXT NOT NULL,
            sign_count      BIGINT UNSIGNED DEFAULT 0 NOT NULL,
            transports      JSON DEFAULT NULL,
            device_name     VARCHAR(100) DEFAULT NULL,
            created_at      DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_WEBAUTHN_CRED_ID (credential_id),
            INDEX IDX_WEBAUTHN_USER (utilisateur_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->addSql('ALTER TABLE webauthn_credential
            ADD CONSTRAINT FK_WEBAUTHN_UTILISATEUR
            FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webauthn_credential DROP FOREIGN KEY FK_WEBAUTHN_UTILISATEUR');
        $this->addSql('DROP TABLE webauthn_credential');
    }
}
