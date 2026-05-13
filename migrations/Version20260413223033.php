<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260413223033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE avis DROP FOREIGN KEY `FK_avis_annonce`');
        $this->addSql('ALTER TABLE avis DROP FOREIGN KEY `FK_avis_auteur`');
        $this->addSql('ALTER TABLE avis DROP FOREIGN KEY `FK_avis_parent`');
        $this->addSql('ALTER TABLE bienimmobilier DROP FOREIGN KEY `FK_bienimmobilier_annonce`');
        $this->addSql('ALTER TABLE reclamation_old_v2 DROP FOREIGN KEY `FK_CE6064041823061F`');
        $this->addSql('ALTER TABLE reclamation_old_v2 DROP FOREIGN KEY `FK_CE606404D8A38199`');
        $this->addSql('ALTER TABLE user_security_state DROP FOREIGN KEY `fk_user_security_state_user`');
        $this->addSql('ALTER TABLE utilisateur_annonce_favoris DROP FOREIGN KEY `FK_favoris_annonce`');
        $this->addSql('ALTER TABLE utilisateur_annonce_favoris DROP FOREIGN KEY `FK_favoris_utilisateur`');
        $this->addSql('DROP TABLE admin_setting');
        $this->addSql('DROP TABLE admin_user_audit');
        $this->addSql('DROP TABLE auth_login_attempt');
        $this->addSql('DROP TABLE avis');
        $this->addSql('DROP TABLE bienimmobilier');
        $this->addSql('DROP TABLE reclamation_old_v2');
        $this->addSql('DROP TABLE user_security_state');
        $this->addSql('DROP TABLE utilisateur_annonce_favoris');
        $this->addSql('ALTER TABLE caution CHANGE statut statut ENUM(\'DETENU\', \'TOTALEMENT_REMBOURSE\', \'PARTIELLEMENT_REMBOURSE\', \'RETENU\') NOT NULL');
        $this->addSql('ALTER TABLE caution_retenue_photo CHANGE type_dommage type_dommage ENUM(\'AUTRE\', \'PEINTURE\', \'MENUISERIE\', \'PLOMBERIE\', \'ELECTRICITE\', \'SOL\', \'NETTOYAGE\'), CHANGE gravite_gemini gravite_gemini ENUM(\'AUCUN\', \'MINEUR\', \'MODERE\', \'IMPORTANT\', \'CRITIQUE\')');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY `FK_reservation_annonce`');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY `FK_reservation_locataire`');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY `FK_reservation_annonce`');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY `FK_reservation_locataire`');
        $this->addSql('ALTER TABLE reservation CHANGE statut statut VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C8495560B270F0 FOREIGN KEY (annonceId) REFERENCES annonce (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955C3E9FA80 FOREIGN KEY (locataireId) REFERENCES utilisateur (id)');
        $this->addSql('DROP INDEX idx_reservation_annonce ON reservation');
        $this->addSql('CREATE INDEX IDX_42C8495560B270F0 ON reservation (annonceId)');
        $this->addSql('DROP INDEX idx_reservation_locataire ON reservation');
        $this->addSql('CREATE INDEX IDX_42C84955C3E9FA80 ON reservation (locataireId)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT `FK_reservation_annonce` FOREIGN KEY (annonceId) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT `FK_reservation_locataire` FOREIGN KEY (locataireId) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1D1C63B3E7927C74 ON utilisateur (email)');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY `FK_visite_annonce`');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY `FK_visite_locataire`');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY `FK_visite_reservation`');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY `FK_visite_annonce`');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY `FK_visite_locataire`');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY `FK_visite_reservation`');
        $this->addSql('ALTER TABLE visite CHANGE statut statut VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT FK_B09C8CBBE271511F FOREIGN KEY (reservationId) REFERENCES reservation (id)');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT FK_B09C8CBB60B270F0 FOREIGN KEY (annonceId) REFERENCES annonce (id)');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT FK_B09C8CBBC3E9FA80 FOREIGN KEY (locataireId) REFERENCES utilisateur (id)');
        $this->addSql('DROP INDEX idx_visite_reservation ON visite');
        $this->addSql('CREATE INDEX IDX_B09C8CBBE271511F ON visite (reservationId)');
        $this->addSql('DROP INDEX idx_visite_annonce ON visite');
        $this->addSql('CREATE INDEX IDX_B09C8CBB60B270F0 ON visite (annonceId)');
        $this->addSql('DROP INDEX idx_visite_locataire ON visite');
        $this->addSql('CREATE INDEX IDX_B09C8CBBC3E9FA80 ON visite (locataireId)');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT `FK_visite_annonce` FOREIGN KEY (annonceId) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT `FK_visite_locataire` FOREIGN KEY (locataireId) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT `FK_visite_reservation` FOREIGN KEY (reservationId) REFERENCES reservation (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_setting (id INT AUTO_INCREMENT NOT NULL, setting_key VARCHAR(120) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, setting_value TEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, UNIQUE INDEX setting_key (setting_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE admin_user_audit (id INT AUTO_INCREMENT NOT NULL, admin_user_id INT DEFAULT NULL, admin_name VARCHAR(190) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, admin_email VARCHAR(190) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, target_user_id INT DEFAULT NULL, target_name VARCHAR(190) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, target_email VARCHAR(190) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, action VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, reason VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, payload_json LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, INDEX idx_admin_user_audit_action (action), INDEX idx_admin_user_audit_target_user (target_user_id), INDEX idx_admin_user_audit_created_at (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE auth_login_attempt (id INT AUTO_INCREMENT NOT NULL, identifier VARCHAR(190) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, user_id INT DEFAULT NULL, success TINYINT DEFAULT 0 NOT NULL, reason VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT \'bad_password\' NOT NULL COLLATE `utf8mb4_unicode_ci`, ip_address VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, lock_triggered TINYINT DEFAULT 0 NOT NULL, INDEX idx_auth_login_attempt_user (user_id), INDEX idx_auth_login_attempt_attempted_at (attempted_at), INDEX idx_auth_login_attempt_identifier (identifier), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE avis (id INT AUTO_INCREMENT NOT NULL, contenu LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, note INT DEFAULT NULL, date_publication DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, auteur_id INT NOT NULL, annonceId INT NOT NULL, parentId INT DEFAULT NULL, INDEX IDX_avis_annonce (annonceId), INDEX IDX_avis_parent (parentId), INDEX IDX_avis_auteur (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE bienimmobilier (id INT AUTO_INCREMENT NOT NULL, annonceId INT DEFAULT NULL, type VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, superficie DOUBLE PRECISION DEFAULT NULL, nombreChambres INT DEFAULT NULL, adresse VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, equipements VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, UNIQUE INDEX UNQ_bienimmobilier_annonceId (annonceId), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE reclamation_old_v2 (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, type_precision VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, description LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, statut VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, priorite VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, photo_path VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, date_creation DATETIME NOT NULL, date_resolution DATETIME DEFAULT NULL, commentaire_admin LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, locataire_id INT NOT NULL, contrat_id INT DEFAULT NULL, INDEX IDX_CE6064041823061F (contrat_id), INDEX IDX_CE606404D8A38199 (locataire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE user_security_state (user_id INT NOT NULL, email_verified TINYINT DEFAULT 1 NOT NULL, email_verified_at DATETIME DEFAULT NULL, last_login_at DATETIME DEFAULT NULL, last_login_ip VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_general_ci`, last_password_change_at DATETIME DEFAULT NULL, failed_warning_sent TINYINT DEFAULT 0 NOT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE utilisateur_annonce_favoris (utilisateur_id INT NOT NULL, annonce_id INT NOT NULL, INDEX IDX_favoris_utilisateur (utilisateur_id), INDEX IDX_favoris_annonce (annonce_id), PRIMARY KEY (utilisateur_id, annonce_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE avis ADD CONSTRAINT `FK_avis_annonce` FOREIGN KEY (annonceId) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE avis ADD CONSTRAINT `FK_avis_auteur` FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE avis ADD CONSTRAINT `FK_avis_parent` FOREIGN KEY (parentId) REFERENCES avis (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bienimmobilier ADD CONSTRAINT `FK_bienimmobilier_annonce` FOREIGN KEY (annonceId) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reclamation_old_v2 ADD CONSTRAINT `FK_CE6064041823061F` FOREIGN KEY (contrat_id) REFERENCES contrat (id)');
        $this->addSql('ALTER TABLE reclamation_old_v2 ADD CONSTRAINT `FK_CE606404D8A38199` FOREIGN KEY (locataire_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE user_security_state ADD CONSTRAINT `fk_user_security_state_user` FOREIGN KEY (user_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur_annonce_favoris ADD CONSTRAINT `FK_favoris_annonce` FOREIGN KEY (annonce_id) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur_annonce_favoris ADD CONSTRAINT `FK_favoris_utilisateur` FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE caution CHANGE statut statut ENUM(\'DETENU\', \'TOTALEMENT_REMBOURSE\', \'PARTIELLEMENT_REMBOURSE\', \'RETENU\') NOT NULL');
        $this->addSql('ALTER TABLE caution_retenue_photo CHANGE type_dommage type_dommage ENUM(\'AUTRE\', \'PEINTURE\', \'MENUISERIE\', \'PLOMBERIE\', \'ELECTRICITE\', \'SOL\', \'NETTOYAGE\') DEFAULT NULL, CHANGE gravite_gemini gravite_gemini ENUM(\'AUCUN\', \'MINEUR\', \'MODERE\', \'IMPORTANT\', \'CRITIQUE\') DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C8495560B270F0');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955C3E9FA80');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C8495560B270F0');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955C3E9FA80');
        $this->addSql('ALTER TABLE reservation CHANGE statut statut VARCHAR(50) DEFAULT \'En attente\' NOT NULL');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT `FK_reservation_annonce` FOREIGN KEY (annonceId) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT `FK_reservation_locataire` FOREIGN KEY (locataireId) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX idx_42c8495560b270f0 ON reservation');
        $this->addSql('CREATE INDEX IDX_reservation_annonce ON reservation (annonceId)');
        $this->addSql('DROP INDEX idx_42c84955c3e9fa80 ON reservation');
        $this->addSql('CREATE INDEX IDX_reservation_locataire ON reservation (locataireId)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C8495560B270F0 FOREIGN KEY (annonceId) REFERENCES annonce (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955C3E9FA80 FOREIGN KEY (locataireId) REFERENCES utilisateur (id)');
        $this->addSql('DROP INDEX UNIQ_1D1C63B3E7927C74 ON utilisateur');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY FK_B09C8CBBE271511F');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY FK_B09C8CBB60B270F0');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY FK_B09C8CBBC3E9FA80');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY FK_B09C8CBBE271511F');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY FK_B09C8CBB60B270F0');
        $this->addSql('ALTER TABLE visite DROP FOREIGN KEY FK_B09C8CBBC3E9FA80');
        $this->addSql('ALTER TABLE visite CHANGE statut statut VARCHAR(50) DEFAULT \'En attente\' NOT NULL');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT `FK_visite_annonce` FOREIGN KEY (annonceId) REFERENCES annonce (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT `FK_visite_locataire` FOREIGN KEY (locataireId) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT `FK_visite_reservation` FOREIGN KEY (reservationId) REFERENCES reservation (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX idx_b09c8cbbc3e9fa80 ON visite');
        $this->addSql('CREATE INDEX IDX_visite_locataire ON visite (locataireId)');
        $this->addSql('DROP INDEX idx_b09c8cbbe271511f ON visite');
        $this->addSql('CREATE INDEX IDX_visite_reservation ON visite (reservationId)');
        $this->addSql('DROP INDEX idx_b09c8cbb60b270f0 ON visite');
        $this->addSql('CREATE INDEX IDX_visite_annonce ON visite (annonceId)');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT FK_B09C8CBBE271511F FOREIGN KEY (reservationId) REFERENCES reservation (id)');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT FK_B09C8CBB60B270F0 FOREIGN KEY (annonceId) REFERENCES annonce (id)');
        $this->addSql('ALTER TABLE visite ADD CONSTRAINT FK_B09C8CBBC3E9FA80 FOREIGN KEY (locataireId) REFERENCES utilisateur (id)');
    }
}
