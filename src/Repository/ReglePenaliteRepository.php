<?php

namespace App\Repository;

use App\Entity\ReglePenalite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReglePenalite>
 */
class ReglePenaliteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReglePenalite::class);
    }

    //    /**
    //     * @return ReglePenalite[] Returns an array of ReglePenalite objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?ReglePenalite
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    /**
     * Règle globale active
     */
    public function findRegleGlobaleActive(): ?ReglePenalite
    {
        return $this->createQueryBuilder('r')
            ->where('r.contrat IS NULL')
            ->andWhere('r.actif = true')
            ->andWhere('r.typeRegle = :type')
            ->setParameter('type', 'RETARD_LOYER')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Règles par bien avec fallback sur règle globale
     * @return list<array<string, mixed>>
     */
    public function findReglesParBien(int $proprietaireId): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT 
                a.id AS annonce_id,
                a.titre AS propriete,
                u.nom AS locataire,
                c.id AS contrat_id,
                c.statut AS statut_contrat,
                COALESCE(rp_spec.delai_grace_jours, rp_glob.delai_grace_jours) AS delai_grace_jours,
                COALESCE(rp_spec.penalite_fixe, rp_glob.penalite_fixe) AS penalite_fixe,
                COALESCE(rp_spec.penalite_pourcentage, rp_glob.penalite_pourcentage) AS penalite_pourcentage,
                COALESCE(rp_spec.plafond_pourcentage, rp_glob.plafond_pourcentage) AS plafond_pourcentage,
                COALESCE(rp_spec.description, rp_glob.description) AS description,
                CASE WHEN rp_spec.contrat_id IS NOT NULL THEN \'personnalisee\' ELSE \'globale\' END AS type_regle,
                COALESCE(SUM(CAST(COALESCE(pl.penalite, \'0\') AS DECIMAL(10,3))), 0) AS total_penalites,
                COUNT(CASE WHEN pl.penalite IS NOT NULL AND CAST(pl.penalite AS DECIMAL(10,3)) > 0 THEN 1 END) AS nb_penalites,
                COALESCE(AVG(CAST(COALESCE(pl.penalite, \'0\') AS DECIMAL(10,3))), 0) AS moyenne_penalite
            FROM annonce a
            LEFT JOIN contrat c ON a.id = c.annonceId AND c.statut = \'ACTIF\'
            LEFT JOIN utilisateur u ON c.locataireId = u.id
            LEFT JOIN regle_penalite rp_spec ON rp_spec.contrat_id = c.id AND rp_spec.actif = TRUE
            LEFT JOIN regle_penalite rp_glob ON rp_glob.contrat_id IS NULL 
                AND rp_glob.actif = TRUE 
                AND rp_glob.type_regle = \'RETARD_LOYER\'
            LEFT JOIN paiement_loyer pl ON pl.contrat_id = c.id AND pl.statut = \'PAYE\'
            WHERE a.proprietaireId = ?
            GROUP BY a.id, a.titre, u.nom, c.id, c.statut,
                     rp_spec.delai_grace_jours, rp_glob.delai_grace_jours,
                     rp_spec.penalite_fixe, rp_glob.penalite_fixe,
                     rp_spec.penalite_pourcentage, rp_glob.penalite_pourcentage,
                     rp_spec.plafond_pourcentage, rp_glob.plafond_pourcentage,
                     rp_spec.description, rp_glob.description,
                     rp_spec.contrat_id
            ORDER BY a.titre
        ';

        $stmt = $conn->executeQuery($sql, [$proprietaireId]);

        return $stmt->fetchAllAssociative();
    }

    /**
     * Toutes les règles actives (globales et personnalisées)
     * @return list<array<string, mixed>>
     */
    public function findReglesActives(int $proprietaireId): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = '
            SELECT 
                rp.id AS regle_id,
                COALESCE(rp.description, \'Règle Standard\') AS nom,
                rp.delai_grace_jours AS delai_jours,
                rp.penalite_fixe AS montant_fixe,
                rp.penalite_pourcentage AS pourcentage,
                rp.plafond_pourcentage AS plafond,
                rp.actif,
                CASE WHEN rp.contrat_id IS NULL THEN \'Globale\' ELSE u.nom END AS portee,
                CASE WHEN rp.contrat_id IS NULL THEN \'\' ELSE a.titre END AS bien_associe
            FROM regle_penalite rp
            LEFT JOIN contrat c ON rp.contrat_id = c.id
            LEFT JOIN utilisateur u ON c.locataireId = u.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            WHERE rp.actif = TRUE 
              AND (rp.contrat_id IS NULL OR a.proprietaireId = ?)
            ORDER BY rp.id DESC
        ';

        $stmt = $conn->executeQuery($sql, [$proprietaireId]);

        return $stmt->fetchAllAssociative();
    }

    /**
     * Désactiver toutes les règles globales existantes
     */
    public function desactiverReglesGlobales(): void
    {
        $this->createQueryBuilder('r')
            ->update()
            ->set('r.actif', false)
            ->where('r.contrat IS NULL')
            ->getQuery()
            ->execute();
    }
}
