<?php

namespace App\Repository;

use App\Entity\ChargesMensuelles;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ChargesMensuelles> */
class ChargesMensuellesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChargesMensuelles::class);
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    // ─────────────────────────────────────────────────────────────────
    // FRAIS DE GESTION — charges actives (non payées + mois en cours)
    // SQL exact identique à ProprietaireViewService.getChargesMensuelles()
    // ─────────────────────────────────────────────────────────────────
    /** @return list<array<string, mixed>> */
    public function findChargesMensuelles(int $proprietaireId): array
    {
        $sql = "
            SELECT
                cm.id           AS charge_id,
                cm.type_charge,
                cm.montant,
                cm.periode,
                cm.fichier_facture,
                cm.statut_paiement,
                cm.description,
                cm.partage_coloc,
                cm.nombre_colocataires,
                cm.part_locataire,
                u.nom           AS nom_locataire,
                u.telephone     AS tel_locataire,
                a.titre         AS nom_bien,
                a.id            AS bien_id,
                COALESCE(
                    (SELECT SUM(pc.montant_paye)
                     FROM paiement_charges pc
                     WHERE pc.charge_id = cm.id), 0
                )               AS montant_paye,
                cm.montant - COALESCE(
                    (SELECT SUM(pc.montant_paye)
                     FROM paiement_charges pc
                     WHERE pc.charge_id = cm.id), 0
                )               AS reste_a_payer
            FROM charges_mensuelles cm
            INNER JOIN contrat c  ON cm.contrat_id = c.id
            INNER JOIN annonce a  ON c.annonceId   = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            WHERE a.proprietaireId = :proprietaireId
              AND cm.type_charge IN (
                  'ELECTRICITE','EAU','INTERNET','GAZ','CHAUFFAGE',
                  'ORDURES','CHARGES_COPRO','ENTRETIEN','AUTRE'
              )
              AND (
                  cm.statut_paiement IN ('NON_PAYE','PARTIEL')
                  OR (
                      MONTH(STR_TO_DATE(CONCAT(cm.periode, '-01'), '%Y-%m-%d'))  = MONTH(CURRENT_DATE())
                      AND YEAR(STR_TO_DATE(CONCAT(cm.periode, '-01'), '%Y-%m-%d')) = YEAR(CURRENT_DATE())
                  )
              )
            ORDER BY cm.statut_paiement, a.titre, cm.type_charge
        ";

        return $this->conn()->fetchAllAssociative($sql, ['proprietaireId' => $proprietaireId]);
    }

    // ─────────────────────────────────────────────────────────────────
    // HISTORIQUE — cycle annuel filtré par année
    // SQL exact identique à ProprietaireViewService.getChargesHistorique()
    // ─────────────────────────────────────────────────────────────────
    /** @return list<array<string, mixed>> */
    public function findChargesHistorique(int $proprietaireId, int $year): array
    {
        $sql = "
            SELECT
                a.id            AS bien_id,
                a.titre         AS nom_bien,
                u.nom           AS nom_locataire,
                DATE_FORMAT(STR_TO_DATE(CONCAT(cm.periode, '-01'), '%Y-%m-%d'), '%b %Y') AS periode_label,
                cm.id           AS charge_id,
                cm.type_charge,
                cm.montant,
                cm.part_locataire,
                cm.fichier_facture,
                cm.statut_paiement,
                cm.description,
                cm.periode
            FROM charges_mensuelles cm
            INNER JOIN contrat c  ON cm.contrat_id = c.id
            INNER JOIN annonce a  ON c.annonceId   = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            WHERE a.proprietaireId = :proprietaireId
              AND YEAR(STR_TO_DATE(CONCAT(cm.periode, '-01'), '%Y-%m-%d')) = :year
            ORDER BY a.titre, cm.periode DESC, cm.type_charge
        ";

        return $this->conn()->fetchAllAssociative($sql, [
            'proprietaireId' => $proprietaireId,
            'year'           => $year,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // KPIs — statistiques annuelles
    // ─────────────────────────────────────────────────────────────────
    /** @return array<string, mixed> */
    public function getKpis(int $proprietaireId, int $year): array
    {
        $sql = "
            SELECT
                COUNT(*)  AS total,
                SUM(CASE WHEN cm.statut_paiement = 'PAYE'     THEN 1 ELSE 0 END) AS payees,
                SUM(CASE WHEN cm.statut_paiement = 'NON_PAYE' THEN 1 ELSE 0 END) AS impayees,
                SUM(CASE WHEN cm.statut_paiement = 'PARTIEL'  THEN 1 ELSE 0 END) AS partielles,
                SUM(cm.montant) AS montant_total,
                SUM(CASE WHEN cm.statut_paiement = 'PAYE' THEN cm.montant ELSE 0 END) AS montant_encaisse
            FROM charges_mensuelles cm
            INNER JOIN contrat c  ON cm.contrat_id = c.id
            INNER JOIN annonce a  ON c.annonceId   = a.id
            WHERE a.proprietaireId = :proprietaireId
              AND YEAR(STR_TO_DATE(CONCAT(cm.periode, '-01'), '%Y-%m-%d')) = :year
        ";

        return $this->conn()->fetchAssociative($sql, [
            'proprietaireId' => $proprietaireId,
            'year'           => $year,
        ]) ?: [];
    }

    // ─────────────────────────────────────────────────────────────────
    // MARQUER PAYÉ — transaction : insert paiement + update statut
    // Identique à ChargeService.markChargeAsPaid() Java
    // ─────────────────────────────────────────────────────────────────
    public function marquerPaye(int $chargeId, string $montant, string $methode, string $reference = ''): bool
    {
        $conn = $this->conn();

        // Vérifier que la charge existe
        $charge = $conn->fetchAssociative(
            'SELECT id, montant, statut_paiement FROM charges_mensuelles WHERE id = ?',
            [$chargeId]
        );
        if (!$charge) return false;

        try {
            $conn->beginTransaction();

            // 1. Insérer le paiement
            $conn->executeStatement(
                "INSERT INTO paiement_charges
                 (charge_id, montant_paye, date_paiement, methode_paiement,
                  reference_transaction, notes, date_creation)
                 VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, CURRENT_TIMESTAMP)",
                [
                    $chargeId,
                    $montant,
                    $methode ?: 'MANUAL',
                    $reference ?: null,
                    'Paiement validé par le propriétaire',
                ]
            );

            // 2. Recalculer et mettre à jour le statut
            $conn->executeStatement(
                "UPDATE charges_mensuelles
                 SET statut_paiement = CASE
                     WHEN COALESCE(
                         (SELECT SUM(pc.montant_paye) FROM paiement_charges pc WHERE pc.charge_id = ?),0
                     ) >= montant THEN 'PAYE'
                     WHEN COALESCE(
                         (SELECT SUM(pc.montant_paye) FROM paiement_charges pc WHERE pc.charge_id = ?),0
                     ) > 0 THEN 'PARTIEL'
                     ELSE 'NON_PAYE'
                 END,
                 date_modification = CURRENT_TIMESTAMP
                 WHERE id = ?",
                [$chargeId, $chargeId, $chargeId]
            );

            $conn->commit();
            return true;
        } catch (\Exception $e) {
            $conn->rollBack();
            error_log('[ChargeRepository] marquerPaye error: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // AJOUTER une charge
    // ─────────────────────────────────────────────────────────────────
    /** @param array<string, mixed> $data */
    public function ajouterCharge(array $data): int|false
    {
        $conn = $this->conn();

        $montant          = (float)($data['montant'] ?? 0);
        $partageColoc     = !empty($data['partage_coloc']) ? 1 : 0;
        $nbColoc          = max(1, (int)($data['nombre_colocataires'] ?? 1));
        $partLocataire    = $partageColoc ? round($montant / $nbColoc, 2) : $montant;

        try {
            $conn->executeStatement(
                "INSERT INTO charges_mensuelles
                 (contrat_id, type_charge, periode, montant, partage_coloc,
                  nombre_colocataires, part_locataire, statut_paiement,
                  description, fichier_facture, date_ajout)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'NON_PAYE', ?, ?, CURRENT_TIMESTAMP)",
                [
                    $data['contrat_id'],
                    $data['type_charge'],
                    $data['periode'],           // format: 'YYYY-MM-01'
                    number_format($montant, 2, '.', ''),
                    $partageColoc,
                    $nbColoc,
                    number_format($partLocataire, 2, '.', ''),
                    $data['description'] ?? null,
                    $data['fichier_facture'] ?? null,
                ]
            );

            return (int)$conn->lastInsertId();
        } catch (\Exception $e) {
            error_log('[ChargeRepository] ajouterCharge error: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Mettre à jour le chemin de la facture
    // ─────────────────────────────────────────────────────────────────
    public function updateFichierFacture(int $chargeId, string $filename): bool
    {
        $rows = $this->conn()->executeStatement(
            'UPDATE charges_mensuelles SET fichier_facture = ?, date_modification = CURRENT_TIMESTAMP WHERE id = ?',
            [$filename, $chargeId]
        );
        return $rows > 0;
    }

    // ─────────────────────────────────────────────────────────────────
    // Génération automatique charges INTERNET (identique Java)
    // ─────────────────────────────────────────────────────────────────
    public function genererInternetMoisProchain(): int
    {
        $sql = "
            INSERT INTO charges_mensuelles
            (contrat_id, type_charge, periode, montant, partage_coloc,
             nombre_colocataires, part_locataire, statut_paiement, description, date_ajout)
            SELECT
                c.id,
                'INTERNET',
                DATE_FORMAT(DATE_ADD(CURRENT_DATE(), INTERVAL 1 MONTH), '%Y-%m-01'),
                45.00,
                1,
                3,
                15.00,
                'NON_PAYE',
                'Forfait internet mensuel (auto-généré)',
                CURRENT_TIMESTAMP
            FROM contrat c
            WHERE LOWER(c.statut) = 'actif'
              AND NOT EXISTS (
                  SELECT 1 FROM charges_mensuelles cm
                  WHERE cm.contrat_id = c.id
                    AND cm.type_charge = 'INTERNET'
                    AND cm.periode = DATE_FORMAT(DATE_ADD(CURRENT_DATE(), INTERVAL 1 MONTH), '%Y-%m-01')
              )
        ";

        return (int)$this->conn()->executeStatement($sql);
    }

    // ─────────────────────────────────────────────────────────────────
    // Listes de contrats actifs (pour formulaire ajout)
    // ─────────────────────────────────────────────────────────────────
    /** @return list<array<string, mixed>> */
    public function findContratsActifs(int $proprietaireId): array
    {
        return $this->conn()->fetchAllAssociative(
            "SELECT c.id AS contrat_id, a.titre AS nom_bien, u.nom AS nom_locataire
             FROM contrat c
             INNER JOIN annonce a ON c.annonceId = a.id
             INNER JOIN utilisateur u ON c.locataireId = u.id
             WHERE a.proprietaireId = ?
               AND LOWER(c.statut) IN ('actif','en cours', 'active')
             ORDER BY a.titre",
            [$proprietaireId]
        );
    }
}
