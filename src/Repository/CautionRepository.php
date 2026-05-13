<?php

namespace App\Repository;

use App\Entity\Caution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Caution>
 */
class CautionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Caution::class);
    }

    /** @return list<array<string, mixed>> */
    public function findCautionsDetenues(int $proprietaireId): array
    {
        $sql = "
            SELECT u.nom AS nom_locataire, u.telephone, a.titre AS nom_bien,
                   ca.id AS caution_id, ca.montant_initial, ca.montant_retention,
                   ca.montant_rembourse, c.date_debut, c.date_fin,
                   COALESCE((
                       SELECT SUM(CAST(cm.montant AS DECIMAL(10,3)))
                       FROM charges_mensuelles cm
                       WHERE cm.contrat_id = c.id
                         AND cm.statut_paiement IN (:statutNonPaye, :statutPartiel)
                   ), 0) AS total_charges_impayees
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            WHERE a.proprietaireId = :proprietaireId
              AND ca.statut = :statutDetenu
              AND c.date_fin >= CURRENT_DATE
            ORDER BY a.titre
        ";

        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, [
                'proprietaireId' => $proprietaireId,
                'statutNonPaye'  => 'NON_PAYE',
                'statutPartiel'  => 'PARTIEL',
                'statutDetenu'   => 'DETENU',
            ])
            ->fetchAllAssociative();
    }

    /** @return list<array<string, mixed>> */
    public function findCautionsARembourser(int $proprietaireId): array
    {
        $sql = "
            SELECT u.nom AS nom_locataire, u.telephone, a.titre AS nom_bien,
                   ca.id AS caution_id, ca.montant_initial, ca.montant_retention,
                   COALESCE(ca.montant_rembourse, 0) AS montant_rembourse,
                   COALESCE((
                       SELECT SUM(CAST(cm.montant AS DECIMAL(10,3)))
                       FROM charges_mensuelles cm
                       WHERE cm.contrat_id = c.id
                         AND cm.statut_paiement IN (:statutNonPaye, :statutPartiel)
                   ), 0) AS total_charges_impayees,
                   -- SMART LIQUIDATION: Caution - Retention - Rembourse - ChargesImpayees
                   ca.montant_initial
                       - COALESCE(ca.montant_retention, 0)
                       - COALESCE(ca.montant_rembourse, 0)
                       - COALESCE((
                           SELECT SUM(CAST(cm.montant AS DECIMAL(10,3)))
                           FROM charges_mensuelles cm
                           WHERE cm.contrat_id = c.id
                             AND cm.statut_paiement IN (:statutNonPaye, :statutPartiel)
                       ), 0) AS a_rembourser,
                   c.date_fin,
                   DATEDIFF(DATE_ADD(c.date_fin, INTERVAL 60 DAY), CURRENT_DATE) AS jours_restants
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            WHERE a.proprietaireId = :proprietaireId
              AND ca.statut = :statutDetenu
              AND c.date_fin <= CURRENT_DATE
            ORDER BY jours_restants ASC
        ";

        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, [
                'proprietaireId' => $proprietaireId,
                'statutNonPaye'  => 'NON_PAYE',
                'statutPartiel'  => 'PARTIEL',
                'statutDetenu'   => 'DETENU',
            ])
            ->fetchAllAssociative();
    }

    /** @return list<array<string, mixed>> */
    public function findCautionsHistorique(int $proprietaireId): array
    {
        $sql = "
            SELECT u.nom AS nom_locataire, a.titre AS nom_bien,
                   ca.montant_initial, ca.montant_retention, ca.montant_rembourse,
                   ca.statut AS statut_caution, c.date_fin
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur u ON c.locataireId = u.id
            WHERE a.proprietaireId = :proprietaireId
              AND ca.statut <> :statutDetenu
            ORDER BY c.date_fin DESC
        ";

        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, [
                'proprietaireId' => $proprietaireId,
                'statutDetenu'   => 'DETENU',
            ])
            ->fetchAllAssociative();
    }

    /** @return list<array<string, mixed>> */
    public function findCautionDetails(int $cautionId): array
    {
        $sql = "
            SELECT ca.*, c.id AS contrat_id, a.titre AS titre_annonce,
                   c.locataireId, c.date_debut, c.date_fin,
                   u.nom AS nom_locataire, u.email AS email_locataire,
                   u2.nom AS nom_proprietaire,
                   COALESCE((
                       SELECT SUM(CAST(cm.montant AS DECIMAL(10,3)))
                       FROM charges_mensuelles cm
                       WHERE cm.contrat_id = c.id
                         AND cm.statut_paiement IN (:statutNonPaye, :statutPartiel)
                   ), 0) AS total_charges_impayees
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            INNER JOIN utilisateur u2 ON a.proprietaireId = u2.id
            WHERE ca.id = :cautionId
        ";

        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, [
                'cautionId'     => $cautionId,
                'statutNonPaye' => 'NON_PAYE',
                'statutPartiel' => 'PARTIEL',
            ])
            ->fetchAllAssociative();
    }

    public function getTotalDetenu(int $proprietaireId): string
    {
        $sql = "
            SELECT SUM(ca.montant_initial) as total
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            WHERE a.proprietaireId = :proprietaireId
              AND ca.statut = :statutDetenu
        ";

        $result = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, [
                'proprietaireId' => $proprietaireId,
                'statutDetenu'   => 'DETENU',
            ])
            ->fetchOne();
            
        return $result ?: '0.00';
    }

    public function getTotalARembourser(int $proprietaireId): string
    {
        $sql = "
            SELECT SUM(ca.montant_initial - ca.montant_retention - ca.montant_rembourse) as total
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            WHERE a.proprietaireId = :proprietaireId
              AND ca.statut = :statutDetenu
              AND c.date_fin < CURRENT_DATE
        ";

        $result = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, [
                'proprietaireId' => $proprietaireId,
                'statutDetenu'   => 'DETENU',
            ])
            ->fetchOne();

        return $result ?: '0.00';
    }
}
