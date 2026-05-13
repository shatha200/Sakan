<?php

namespace App\Service;

use App\Service\NotificationService;
use Doctrine\DBAL\Connection;

/**
 * ChargeLocataireService — Service complet charges pour l'espace locataire
 * Port exact de ChargeService.java + LoyerService.java (partie charges)
 */
class ChargeLocataireService
{
    public const VALID_TYPES = [
        'ELECTRICITE', 'EAU', 'GAZ', 'CHAUFFAGE', 'INTERNET',
        'ENTRETIEN', 'ORDURES', 'CHARGES_COPRO', 'AUTRE'
    ];
    private const VALID_STATUTS = ['NON_PAYE', 'PAYE', 'PARTIEL'];

    private const TYPE_ICONS = [
        'ELECTRICITE'   => ['icon' => 'fa-bolt',               'color' => '#f59e0b', 'label' => 'Électricité'],
        'EAU'           => ['icon' => 'fa-droplet',            'color' => '#3b82f6', 'label' => 'Eau'],
        'INTERNET'      => ['icon' => 'fa-wifi',               'color' => '#8b5cf6', 'label' => 'Internet'],
        'GAZ'           => ['icon' => 'fa-fire-flame-simple',  'color' => '#ef4444', 'label' => 'Gaz'],
        'CHAUFFAGE'     => ['icon' => 'fa-temperature-high',   'color' => '#f97316', 'label' => 'Chauffage'],
        'ORDURES'       => ['icon' => 'fa-trash',              'color' => '#6b7280', 'label' => 'Ordures'],
        'CHARGES_COPRO' => ['icon' => 'fa-building',           'color' => '#0ea5e9', 'label' => 'Charges copro'],
        'ENTRETIEN'     => ['icon' => 'fa-screwdriver-wrench', 'color' => '#10b981', 'label' => 'Entretien'],
        'AUTRE'         => ['icon' => 'fa-circle-question',    'color' => '#9ca3af', 'label' => 'Autre'],
    ];

    public function __construct(
        private readonly Connection           $conn,
        private readonly NotificationService  $notificationService,
    ) {}

    // ═══════════════════════════════════════════════════════════════════
    //  SECTION 1 : LECTURE / LISTING
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Logements du locataire (pour le sélecteur d'onglets)
     * @return list<array<string, mixed>>
     */
    public function getLogementsByLocataire(int $locataireId): array
    {
        // Doctrine Doctor fix: Paramétrage des chaînes pour éviter l'alerte d'injection DQL/SQL
        $sql = "
            SELECT COALESCE(a.id, 0) as log_id, c.id AS contrat_id,
                COALESCE(a.titre, CONCAT(:prefixContrat, c.id)) as titre,
                COALESCE(a.description, :defaultDesc) as description
            FROM contrat c
            LEFT JOIN annonce a ON c.annonceId = a.id
            WHERE c.locataireId = :locataireId
            ORDER BY c.date_debut DESC
        ";

        return $this->conn->executeQuery($sql, [
            'locataireId'   => $locataireId,
            'prefixContrat' => 'Contrat #',
            'defaultDesc'   => 'Adresse non spécifiée',
        ])->fetchAllAssociative();
    }

    /**
     * Charges par liste de contrats (Vue globale ou logement spécifique)
     * Requête 2 du cahier des charges Java
     * @param array<int> $contractIds
     * @return list<array<string, mixed>>
     */
    public function getChargesByContrats(array $contractIds): array
    {
        if (empty($contractIds)) return [];

        $placeholders = implode(',', array_fill(0, count($contractIds), '?'));

        // Doctrine Doctor fix: Paramétrage du littéral 'Contrat #' avec le marqueur positionnel (?)
        $sql = "
            SELECT 
                cm.id,
                cm.contrat_id,
                cm.type_charge,
                cm.periode,
                cm.montant,
                cm.partage_coloc,
                cm.nombre_colocataires,
                COALESCE(cm.part_locataire, cm.montant) AS montant_a_payer,
                cm.statut_paiement,
                cm.fichier_facture,
                cm.description,
                COALESCE(a.titre, CONCAT(?, cm.contrat_id)) AS logement_titre,
                pc_agg.derniere_date_paiement,
                COALESCE(pc_agg.montant_paye_total, 0) AS montant_paye_total
            FROM charges_mensuelles cm
            LEFT JOIN contrat c ON c.id = CAST(cm.contrat_id AS UNSIGNED)
            LEFT JOIN annonce a ON a.id = c.annonceId
            LEFT JOIN (
                SELECT charge_id, 
                       MAX(date_paiement) AS derniere_date_paiement, 
                       SUM(CAST(montant_paye AS DECIMAL(10,3))) AS montant_paye_total
                FROM paiement_charges
                GROUP BY charge_id
            ) pc_agg ON pc_agg.charge_id = cm.id
            WHERE cm.contrat_id IN ($placeholders)
            ORDER BY STR_TO_DATE(cm.periode, '%Y-%m-%d') DESC, cm.type_charge
        ";

        // Le premier ? correspond au 'Contrat #', les suivants aux IDs de contrat
        $params = array_merge(['Contrat #'], $contractIds);
        $rows = $this->conn->executeQuery($sql, $params)->fetchAllAssociative();

        // Enrichissement avec méta-données icon/couleur
        return array_map(fn($row) => $this->enrichRow($row), $rows);
    }

    /**
     * Charge unique avec vérification locataire (sécurité)
     * @return array<string, mixed>|null
     */
    public function getChargeById(int $chargeId, int $locataireId): ?array
    {
        $sql = "
            SELECT cm.*, COALESCE(cm.part_locataire, cm.montant) AS montant_a_payer
            FROM charges_mensuelles cm
            INNER JOIN contrat c ON CAST(cm.contrat_id AS UNSIGNED) = c.id
            WHERE cm.id = :chargeId AND c.locataireId = :locataireId
        ";

        $row = $this->conn->executeQuery($sql, [
            'chargeId'    => $chargeId,
            'locataireId' => $locataireId,
        ])->fetchAssociative();

        return $row ? $this->enrichRow($row) : null;
    }

    /**
     * Historique des paiements d'une charge (Requête 12)
     * @return list<array<string, mixed>>
     */
    public function getPaiementsByCharge(int $chargeId): array
    {
        $sql = "
            SELECT id, charge_id, montant_paye, date_paiement,
                   methode_paiement, reference_transaction, notes
            FROM paiement_charges
            WHERE charge_id = :chargeId
            ORDER BY date_paiement DESC
        ";

        return $this->conn->executeQuery($sql, ['chargeId' => $chargeId])->fetchAllAssociative();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SECTION 2 : STATISTIQUES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Stats globales par locataire (Requête 10)
     * @return array<string, mixed>
     */
    public function getStats(int $locataireId): array
    {
        $sql = "
            SELECT 
                COUNT(*) AS total_charges,
                SUM(CASE WHEN statut_paiement='PAYE' THEN 1 ELSE 0 END) AS charges_payees,
                SUM(CASE WHEN statut_paiement='NON_PAYE' THEN 1 ELSE 0 END) AS charges_impayees,
                SUM(CASE WHEN statut_paiement='PARTIEL' THEN 1 ELSE 0 END) AS charges_partielles,
                COALESCE(SUM(CASE WHEN statut_paiement='PAYE' THEN CAST(montant AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_paye,
                COALESCE(SUM(CASE WHEN statut_paiement!='PAYE' THEN CAST(COALESCE(part_locataire, montant) AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_restant
            FROM charges_mensuelles cm
            INNER JOIN contrat c ON CAST(cm.contrat_id AS UNSIGNED) = c.id
            WHERE c.locataireId = :locataireId
        ";

        $stats = $this->conn->executeQuery($sql, ['locataireId' => $locataireId])->fetchAssociative();

        if (!$stats) {
            return ['total_charges' => 0, 'charges_payees' => 0, 'charges_impayees' => 0,
                    'charges_partielles' => 0, 'montant_paye' => 0, 'montant_restant' => 0];
        }

        return [
            'total_charges'      => (int)$stats['total_charges'],
            'charges_payees'     => (int)$stats['charges_payees'],
            'charges_impayees'   => (int)$stats['charges_impayees'],
            'charges_partielles' => (int)$stats['charges_partielles'],
            'montant_paye'       => round((float)$stats['montant_paye'], 3),
            'montant_restant'    => round((float)$stats['montant_restant'], 3),
        ];
    }

    /**
     * Stats pour un ensemble de contrats (Vue globale / logement)
     * @param array<int> $contractIds
     * @return array<string, mixed>
     */
    public function getStatsForContrats(array $contractIds): array
    {
        if (empty($contractIds)) {
            return ['total_charges' => 0, 'charges_payees' => 0, 'charges_impayees' => 0,
                    'charges_partielles' => 0, 'montant_paye' => 0, 'montant_restant' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($contractIds), '?'));

        $sql = "
            SELECT 
                COUNT(*) AS total_charges,
                SUM(CASE WHEN statut_paiement='PAYE' THEN 1 ELSE 0 END) AS charges_payees,
                SUM(CASE WHEN statut_paiement='NON_PAYE' THEN 1 ELSE 0 END) AS charges_impayees,
                SUM(CASE WHEN statut_paiement='PARTIEL' THEN 1 ELSE 0 END) AS charges_partielles,
                COALESCE(SUM(CASE WHEN statut_paiement='PAYE' THEN CAST(montant AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_paye,
                COALESCE(SUM(CASE WHEN statut_paiement!='PAYE' THEN CAST(COALESCE(part_locataire, montant) AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_restant
            FROM charges_mensuelles
            WHERE contrat_id IN ($placeholders)
        ";

        $stats = $this->conn->executeQuery($sql, $contractIds)->fetchAssociative();

        if (!$stats) {
            return ['total_charges' => 0, 'charges_payees' => 0, 'charges_impayees' => 0,
                    'charges_partielles' => 0, 'montant_paye' => 0, 'montant_restant' => 0];
        }

        return [
            'total_charges'      => (int)$stats['total_charges'],
            'charges_payees'     => (int)$stats['charges_payees'],
            'charges_impayees'   => (int)$stats['charges_impayees'],
            'charges_partielles' => (int)$stats['charges_partielles'],
            'montant_paye'       => round((float)$stats['montant_paye'], 3),
            'montant_restant'    => round((float)$stats['montant_restant'], 3),
        ];
    }

    /**
     * Données graphique évolution (Chart.js) — Requête 3
     * @param array<int> $contractIds
     * @return array<string, mixed>
     */
    public function getEvolutionData(array $contractIds): array
    {
        if (empty($contractIds)) return ['labels' => [], 'datasets' => []];

        $placeholders = implode(',', array_fill(0, count($contractIds), '?'));

        $sql = "
            SELECT type_charge, periode, SUM(CAST(montant AS DECIMAL(10,3))) as total
            FROM charges_mensuelles
            WHERE contrat_id IN ($placeholders)
            GROUP BY type_charge, periode
            ORDER BY STR_TO_DATE(periode, '%Y-%m-%d') ASC
            LIMIT 24
        ";

        $results   = $this->conn->executeQuery($sql, $contractIds)->fetchAllAssociative();
        $chartData = ['labels' => [], 'datasets' => []];
        $types     = ['ELECTRICITE' => [], 'EAU' => [], 'INTERNET' => [], 'AUTRE' => []];
        $allLabels = [];

        foreach ($results as $row) {
            if (!$row['periode']) continue;
            $label = date('M y', strtotime($row['periode']));
            if (!in_array($label, $allLabels)) $allLabels[] = $label;
            $type = $row['type_charge'] ?? 'AUTRE';
            if (!array_key_exists($type, $types)) $type = 'AUTRE';
            $types[$type][$label] = (float)$row['total'];
        }

        $chartData['labels'] = $allLabels;

        foreach ($types as $type => $data) {
            $datasetData = [];
            foreach ($allLabels as $lbl) {
                $datasetData[] = $data[$lbl] ?? 0;
            }
            if (array_sum($datasetData) > 0) {
                $meta = self::TYPE_ICONS[$type];
                $chartData['datasets'][] = [
                    'label'           => $meta['label'],
                    'data'            => $datasetData,
                    'borderColor'     => $meta['color'],
                    'backgroundColor' => $meta['color'] . '20',
                    'tension'         => 0.4,
                    'fill'            => true,
                    'pointRadius'     => 4,
                    'pointHoverRadius'=> 6,
                ];
            }
        }

        return $chartData;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SECTION 3 : CRUD COMPLET
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Créer une charge (Requête 4)
     * @param array<string, mixed> $data
     */
    public function creerCharge(array $data): int|false
    {
        $typeCharge = strtoupper($data['type_charge'] ?? 'AUTRE');
        if (!in_array($typeCharge, self::VALID_TYPES)) return false;

        $montant      = (float)($data['montant'] ?? 0);
        $partColoc    = !empty($data['partage_coloc']) ? 1 : 0;
        $nbColoc      = max(1, (int)($data['nombre_colocataires'] ?? 1));
        $partLocataire = $partColoc ? round($montant / $nbColoc, 3) : $montant;

        try {
            $this->conn->executeStatement(
                "INSERT INTO charges_mensuelles 
                (contrat_id, type_charge, periode, montant, partage_coloc,
                 nombre_colocataires, part_locataire, statut_paiement,
                 description, fichier_facture, date_ajout)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'NON_PAYE', ?, ?, CURRENT_TIMESTAMP)",
                [
                    $data['contrat_id'],
                    $typeCharge,
                    $data['periode'] ?? date('Y-m-01'),
                    number_format($montant, 3, '.', ''),
                    $partColoc,
                    $nbColoc,
                    number_format($partLocataire, 3, '.', ''),
                    $data['description'] ?? null,
                    $data['fichier_facture'] ?? null,
                ]
            );
        $id = (int)$this->conn->lastInsertId();
            return $id > 0 ? $id : false;
        } catch (\Exception $e) {
            error_log('[ChargeLocataireService] creerCharge: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------
    //  MOTEUR D'ANOMALIE : appelé après création / modification
    // ------------------------------------------------------------------

    /** @var array<string, ?float> */
    private array $movingAverageCache = [];

    /**
     * Calcule la moyenne glissante des 6 derniers mois pour un contrat + type donnés.
     * Retourne NULL si moins de 3 enregistrements historiques (pas assez de données).
     */
    public function getMovingAverage(int $contratId, string $typeCharge, string $periodeActuelle): ?float
    {
        // Doctrine Doctor fix: Cache en mémoire (memoization) pour éviter les requêtes fréquentes identiques
        $cacheKey = "{$contratId}_{$typeCharge}_{$periodeActuelle}";
        if (array_key_exists($cacheKey, $this->movingAverageCache)) {
            return $this->movingAverageCache[$cacheKey];
        }

        // On sélectionne les 6 mois précédents EN EXCLUANT la période actuelle
        $sql = "
            SELECT CAST(montant AS DECIMAL(10,3)) AS montant
            FROM charges_mensuelles
            WHERE contrat_id = :contratId
              AND type_charge = :typeCharge
              AND periode < :periodeActuelle
            ORDER BY periode DESC
            LIMIT 6
        ";

        try {
            $rows = $this->conn->fetchAllAssociative($sql, [
                'contratId'      => (string) $contratId,
                'typeCharge'     => $typeCharge,
                'periodeActuelle'=> $periodeActuelle,
            ]);
        } catch (\Throwable $e) {
            error_log('[ChargeLocataireService] getMovingAverage: ' . $e->getMessage());
            return null;
        }

        // Minimum 3 mois d'historique pour éviter les faux positifs
        if (count($rows) < 3) {
            $this->movingAverageCache[$cacheKey] = null;
            return null;
        }

        $total = array_sum(array_map(fn($r) => (float)$r['montant'], $rows));
        $avg = round($total / count($rows), 3);
        $this->movingAverageCache[$cacheKey] = $avg;
        
        return $avg;
    }

    /**
     * Vérifie si le montant dépasse 40 % de la moyenne glissante et,
     * si c'est le cas, envoie une notification in-app + email au locataire ET au propriétaire.
     *
     * @param int    $contratId   ID du contrat concerné
     * @param string $typeCharge  Type de charge (EAU, ELECTRICITE, GAZ)
     * @param float  $montant     Nouveau montant saisi
     * @param string $periode     Période de la charge (YYYY-MM-01)
     * @param int    $locataireId ID du locataire pour la notification in-app
     */
    public function checkAndNotifyAnomaly(
        int    $contratId,
        string $typeCharge,
        float  $montant,
        string $periode,
        int    $locataireId
    ): void {
        // Seuls les fluides concernés
        $fluides = ['EAU', 'ELECTRICITE', 'GAZ'];
        if (!in_array($typeCharge, $fluides, true)) {
            return;
        }

        $moyenne = $this->getMovingAverage($contratId, $typeCharge, $periode);
        if ($moyenne === null || $moyenne <= 0) {
            return; // Pas assez d'historique ou moyenne incohérente
        }

        $seuil     = 0.40; // +40%
        $threshold = $moyenne * (1 + $seuil);

        if ($montant <= $threshold) {
            return; // Consommation normale
        }

        // ---   Contexte métier   ---
        $meta       = self::TYPE_ICONS[$typeCharge];
        $label      = $meta['label'];
        $pctHausse  = (int) round((($montant - $moyenne) / $moyenne) * 100);
        $ts = $periode ? strtotime($periode) : false;
        $periodeStr = ($ts !== false) ? date('F Y', $ts) : 'N/A';
        $montantFmt = number_format($montant, 3, ',', ' ');
        $moyenneFmt = number_format($moyenne, 3, ',', ' ');

        // --- Récupération infos pour email ---
        $locataireInfo = $this->getLocataireInfo($locataireId);
        $ownerInfo     = $this->getProprietaireByContrat($contratId);

        // --- EMAIL ALERT uniquement (les alertes UI sont gérées par getAnomalyAlertsForContrats()) ---
        // NOTE : notificationService.addNotification() supprimé pour ce module.
        // Les fonctionnalités avancées du module paiement utilisent des alertes UI (banners).
        // Le NotificationService reste intact pour le module annonce.
        $this->sendAnomalyAlertEmail(
            $locataireInfo,
            $ownerInfo,
            $label,
            $typeCharge,
            $montantFmt,
            $moyenneFmt,
            $pctHausse,
            $periodeStr
        );
    }


    /**
     * Envoi de l'email d'alerte urgente aux deux parties.
     * Réutilise exactement le même pattern cURL/Brevo que sendPaymentConfirmationEmail().
     * @param array<string, mixed>|null $locataire
     * @param array<string, mixed>|null $owner
     */
    private function sendAnomalyAlertEmail(
        ?array $locataire,
        ?array $owner,
        string $labelType,
        string $typeCharge,
        string $montantFmt,
        string $moyenneFmt,
        int    $pctHausse,
        string $periodeStr
    ): void {
        $apiKey      = $_ENV['BREVO_API_KEY']      ?? '';
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? 'noreply@sakan.tn';
        $senderName  = $_ENV['BREVO_SENDER_NAME']  ?? 'Sakan Platform';

        if (empty($apiKey)) {
            return;
        }

        $meta       = self::TYPE_ICONS[$typeCharge] ?? self::TYPE_ICONS['AUTRE'];
        $iconEmoji  = match ($typeCharge) {
            'EAU'         => '💧',
            'ELECTRICITE' => '⚡',
            'GAZ'         => '🔥',
            default       => '⚠️',
        };

        // Construction des destinataires
        $recipients = [];
        if (!empty($locataire['email'])) {
            $recipients[] = [
                'email' => $locataire['email'],
                'name'  => trim(($locataire['prenom'] ?? '') . ' ' . ($locataire['nom'] ?? '')),
            ];
        }
        if (!empty($owner['email'])) {
            $recipients[] = [
                'email' => $owner['email'],
                'name'  => trim(($owner['prenom'] ?? '') . ' ' . ($owner['nom'] ?? '')),
            ];
        }

        if (empty($recipients)) {
            return;
        }

        $htmlContent = "<!DOCTYPE html>
<html><body style='margin:0;padding:0;font-family:Arial,sans-serif;background:#fff7ed;'>
<div style='max-width:600px;margin:30px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.10);border-top:5px solid #f97316;'>
    <div style='background:#f97316;padding:28px;text-align:center;'>
        <h1 style='color:white;margin:0;font-size:22px;'>{$iconEmoji} Alerte Surconsommation Détectée</h1>
        <p style='color:#fed7aa;margin:6px 0 0;font-size:13px;'>Sakan – Surveillance Intelligente des Charges</p>
    </div>
    <div style='padding:28px;'>
        <div style='background:#fff7ed;border:2px solid #f97316;border-radius:10px;padding:16px;margin-bottom:20px;'>
            <p style='margin:0;font-size:15px;font-weight:700;color:#c2410c;'>
                {$iconEmoji} Hausse de {$pctHausse}% détectée sur {$labelType}
            </p>
        </div>
        <table style='width:100%;border-collapse:collapse;margin:20px 0;'>
            <tr style='background:#f9fafb;'>
                <td style='padding:12px 16px;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;'>Type de charge</td>
                <td style='padding:12px 16px;font-size:14px;font-weight:600;border-bottom:1px solid #e5e7eb;'>{$labelType}</td>
            </tr>
            <tr>
                <td style='padding:12px 16px;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;'>Période</td>
                <td style='padding:12px 16px;font-size:14px;font-weight:600;border-bottom:1px solid #e5e7eb;'>{$periodeStr}</td>
            </tr>
            <tr style='background:#fff7ed;'>
                <td style='padding:14px 16px;font-size:15px;font-weight:700;color:#c2410c;'>Montant actuel</td>
                <td style='padding:14px 16px;font-size:18px;font-weight:700;color:#dc2626;'>{$montantFmt} TND</td>
            </tr>
            <tr>
                <td style='padding:12px 16px;font-size:14px;color:#6b7280;'>Moyenne 6 mois</td>
                <td style='padding:12px 16px;font-size:14px;font-weight:600;color:#16a34a;'>{$moyenneFmt} TND</td>
            </tr>
        </table>
        <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;margin-top:16px;'>
            <p style='margin:0;font-size:13px;color:#991b1b;'>
                <strong>Actions recommandées</strong> : Vérifier les robinets, les compteurs ou contacter
                un technicien pour inspecter les installations.
            </p>
        </div>
        <p style='font-size:12px;color:#9ca3af;margin-top:20px;'>&#128197; Détecté le " . date('d/m/Y H:i') . "</p>
    </div>
    <div style='background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb;'>
        <p style='font-size:12px;color:#9ca3af;margin:0;'>
            Sakan – Surveillance Intelligente &copy; " . date('Y') . "
        </p>
    </div>
</div>
</body></html>";

        $body = [
            'sender'      => ['name' => $senderName, 'email' => $senderEmail],
            'to'          => $recipients,
            'subject'     => "⚠️ Sakan Alert – Abnormal {$labelType} Consumption Detected (+{$pctHausse}%)",
            'htmlContent' => $htmlContent,
        ];

        try {
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            if ($ch === false) { return; }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => (string)(json_encode($body) ?: '{}'),
                CURLOPT_HTTPHEADER     => [
                    'api-key: ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT        => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode !== 201) {
                error_log('[ChargeLocataireService] Anomaly email HTTP ' . $httpCode . ': ' . $response);
            }
        } catch (\Throwable $e) {
            error_log('[ChargeLocataireService] sendAnomalyAlertEmail: ' . $e->getMessage());
        }
    }

    /**
     * Vérifie si une charge impayée (NON_PAYE / PARTIEL) affecte une caution active.
     * Si oui, envoie une notification in-app immédiate au propriétaire.
     *
     * Déclenché après creerCharge() et modifierCharge() — non-bloquant.
     *
     * @param int    $contratId   ID du contrat
     * @param string $statut      Statut de la charge ('NON_PAYE', 'PARTIEL', 'PAYE')
     * @param float  $montant     Montant de la charge
     * @param string $typeCharge  Type de charge (EAU, ELECTRICITE, etc.)
     */
    public function checkCautionRisk(int $contratId, string $statut, float $montant, string $typeCharge): void
    {
        // Seules les charges impayées ou partielles sont concernées (détection robuste de la casse)
        $statutNormalise = strtoupper($statut);
        if (!in_array($statutNormalise, ['NON_PAYE', 'PARTIEL'], true) || $contratId <= 0) {
            return;
        }

        try {
            // 1. Vérifier si une caution DETENU existe pour ce contrat
            $caution = $this->conn->fetchAssociative(
                "SELECT ca.id AS caution_id, ca.montant_initial, ca.contrat_id,
                        u.id AS proprietaire_id, u.nom AS proprietaire_nom,
                        a.titre AS titre_bien
                 FROM caution ca
                 INNER JOIN contrat   c  ON ca.contrat_id  = c.id
                 INNER JOIN annonce   a  ON c.annonceId    = a.id
                 INNER JOIN utilisateur u ON a.proprietaireId = u.id
                 WHERE ca.contrat_id = :contratId
                   AND ca.statut = :statutDetenu
                 LIMIT 1",
                [
                    'contratId' => $contratId,
                    'statutDetenu' => 'DETENU',
                ]
            );

            if (!$caution) {
                return; // Pas de caution active, rien à faire
            }

            // 2. Calculer le total des charges impayées pour ce contrat (Correction Nom Colonne SQL)
            $totalImpaye = (float)($this->conn->fetchOne(
                "SELECT COALESCE(SUM(CAST(montant AS DECIMAL(10,3))), 0)
                 FROM charges_mensuelles
                 WHERE contrat_id = :contratId
                   AND statut_paiement IN (:statutNonPaye, :statutPartiel)",
                [
                    'contratId' => $contratId,
                    'statutNonPaye' => 'NON_PAYE',
                    'statutPartiel' => 'PARTIEL',
                ]
            ) ?: 0);

            $montantInitial  = (float)$caution['montant_initial'];
            $ownerId         = (int)$caution['proprietaire_id'];
            $titreBien       = $caution['titre_bien'] ?? 'Bien';
            $labelType       = self::TYPE_ICONS[$typeCharge]['label'] ?? $typeCharge;

            // 3. Calculer le risque relatif
            $pctCouverture = $montantInitial > 0
                ? round(($totalImpaye / $montantInitial) * 100, 1)
                : 0;

            // 4. Choisir le niveau d'alerte selon la couverture
            if ($totalImpaye >= $montantInitial) {
                // Situation critique : la dette dépasse ou égale la caution
                $msg = sprintf(
                    '🚨 CRITIQUE — %s : Les charges impayées (%.3f TND) dépassent la caution (%.3f TND). '
                    . 'Nouvelle charge "%s" : %.3f TND. Action immédiate requise.',
                    $titreBien,
                    $totalImpaye,
                    $montantInitial,
                    $labelType,
                    $montant
                );
                $type = 'ALERTE_BIEN';
            } elseif ($pctCouverture >= 50) {
                // Alerte modérée : plus de 50% de la caution est à risque
                $msg = sprintf(
                    '⚠️ Alerte caution — %s : %.1f%% de la caution est à risque (charges impayées: %.3f TND / caution: %.3f TND). '
                    . 'Nouvelle charge "%s" enregistrée : %.3f TND.',
                    $titreBien,
                    $pctCouverture,
                    $totalImpaye,
                    $montantInitial,
                    $labelType,
                    $montant
                );
                $type = 'ALERTE_BIEN';
            } else {
                // Information préventive : dette < 50% de la caution
                $msg = sprintf(
                    'ℹ️ Info caution — %s : Une charge "%s" (%.3f TND) est enregistrée comme impayée. '
                    . 'Total impayé : %.3f TND / Caution : %.3f TND.',
                    $titreBien,
                    $labelType,
                    $montant,
                    $totalImpaye,
                    $montantInitial
                );
                $type = 'ALERTE_CONSOMMATION';
            }

            // 5. Alerte UI uniquement — pas de notificationService pour ce module
            // Les alertes sont affichées via getCautionRiskOwnerAlerts() dans le template owner.
            // NOTE : notificationService.addNotification() supprimé intentionnellement.
            // Le NotificationService reste intact pour le module annonce de l'ami.
            error_log(sprintf(
                '[ChargeLocataireService] checkCautionRisk caution alert: contrat=%d totalImpaye=%.3f montantInitial=%.3f pct=%.1f%%',
                $contratId, $totalImpaye, $montantInitial, $pctCouverture
            ));

        } catch (\Throwable $e) {
            error_log('[ChargeLocataireService] checkCautionRisk error (non-blocking): ' . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SECTION ALERTES UI — Données pour les banners Twig (sans NotificationService)
    //  Ces méthodes sont appelées par les controllers lors du rendu de la page.
    //  Elles remplacent les notifications in-app pour les fonctionnalités avancées
    //  du module paiement/charges.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * FEATURE 1 : Détection de fuites — Alertes UI pour le locataire
     *
     * @param array<int> $contractIds  Liste des contrat_id du locataire
     * @return list<array<string, mixed>>
     */
    public function getAnomalyAlertsForContrats(array $contractIds): array
    {
        if (empty($contractIds)) {
            return [];
        }

        $fluides = ['EAU', 'ELECTRICITE', 'GAZ'];
        $alerts  = [];
        $seuil   = 0.40; // +40%

        try {
            $placeholders = implode(',', array_fill(0, count($contractIds), '?'));

            // Récupérer les charges fluides du mois courant et du mois précédent
            $rows = $this->conn->fetchAllAssociative(
                "SELECT cm.contrat_id, cm.type_charge, cm.periode,
                        CAST(cm.montant AS DECIMAL(10,3)) AS montant
                 FROM charges_mensuelles cm
                 WHERE cm.contrat_id IN ($placeholders)
                   AND cm.type_charge IN ('EAU', 'ELECTRICITE', 'GAZ')
                   AND cm.periode >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 2 MONTH), '%Y-%m-01')
                 ORDER BY cm.periode DESC",
                $contractIds
            );

            foreach ($rows as $row) {
                $typeCharge = $row['type_charge'];
                $contratId  = (int)$row['contrat_id'];
                $montant    = (float)$row['montant'];
                $periode    = $row['periode'];

                $moyenne = $this->getMovingAverage($contratId, $typeCharge, $periode);
                if ($moyenne === null || $moyenne <= 0) {
                    continue;
                }

                $threshold = $moyenne * (1 + $seuil);
                if ($montant <= $threshold) {
                    continue; // Consommation normale
                }

                $meta      = self::TYPE_ICONS[$typeCharge] ?? self::TYPE_ICONS['AUTRE'];
                $pctHausse = round((($montant - $moyenne) / $moyenne) * 100);
                $emoji     = match($typeCharge) {
                    'EAU'         => '💧',
                    'ELECTRICITE' => '⚡',
                    'GAZ'         => '🔥',
                    default       => '⚠️',
                };

                // Clé unique par contrat+type pour éviter les doublons
                $key = $contratId . '_' . $typeCharge;
                $alerts[$key] = [
                    'type'       => $typeCharge,
                    'label'      => $meta['label'],
                    'emoji'      => $emoji,
                    'couleur'    => $meta['color'],
                    'montant'    => number_format($montant, 3, ',', ' '),
                    'moyenne'    => number_format($moyenne, 3, ',', ' '),
                    'pct'        => $pctHausse,
                    'periode'    => $periode ? date('F Y', strtotime($periode)) : 'N/A',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ChargeLocataireService] getAnomalyAlertsForContrats: ' . $e->getMessage());
        }

        return array_values($alerts);
    }

    /**
     * FEATURE 2 : Charges impayées vs Caution — Alertes UI pour le propriétaire
     *
     * @param int $proprietaireId  ID du propriétaire connecté
     * @return list<array<string, mixed>>
     */
    public function getCautionRiskOwnerAlerts(int $proprietaireId): array
    {
        $alerts = [];

        try {
            // Récupérer toutes les cautions actives (DETENU) du propriétaire
            $cautions = $this->conn->fetchAllAssociative(
                "SELECT ca.id, ca.montant_initial, ca.contrat_id,
                        a.titre AS titre_bien
                 FROM caution ca
                 INNER JOIN contrat   c  ON ca.contrat_id  = c.id
                 INNER JOIN annonce   a  ON c.annonceId    = a.id
                 INNER JOIN utilisateur u ON a.proprietaireId = u.id
                 WHERE u.id = :proprietaireId
                   AND ca.statut = :statutDetenu",
                [
                    'proprietaireId' => $proprietaireId,
                    'statutDetenu'   => 'DETENU',
                ]
            );

            foreach ($cautions as $caution) {
                $contratId      = (int)$caution['contrat_id'];
                $montantInitial = (float)$caution['montant_initial'];

                if ($montantInitial <= 0) {
                    continue;
                }

                // Total charges impayées pour ce contrat
                $totalImpaye = (float)($this->conn->fetchOne(
                    "SELECT COALESCE(SUM(CAST(montant AS DECIMAL(10,3))), 0)
                     FROM charges_mensuelles
                     WHERE contrat_id = :contratId
                       AND statut_paiement IN (:statutNonPaye, :statutPartiel)",
                    [
                        'contratId' => $contratId,
                        'statutNonPaye' => 'NON_PAYE',
                        'statutPartiel' => 'PARTIEL',
                    ]
                ) ?: 0);

                if ($totalImpaye <= 0) {
                    continue; // Aucune dette, pas d'alerte
                }

                $pctCouverture = round(($totalImpaye / $montantInitial) * 100, 1);

                // Niveau d'alerte selon couverture
                if ($totalImpaye >= $montantInitial) {
                    $niveau  = 'critique';
                    $couleur = '#dc2626'; // rouge
                    $emoji   = '🚨';
                } elseif ($pctCouverture >= 50) {
                    $niveau  = 'avertissement';
                    $couleur = '#d97706'; // orange
                    $emoji   = '⚠️';
                } else {
                    $niveau  = 'info';
                    $couleur = '#2563eb'; // bleu
                    $emoji   = 'ℹ️';
                }

                $alerts[] = [
                    'titre_bien'      => $caution['titre_bien'] ?? 'Appartement',
                    'total_impaye'    => number_format($totalImpaye, 3, ',', ' '),
                    'montant_caution' => number_format($montantInitial, 3, ',', ' '),
                    'pct'             => $pctCouverture,
                    'niveau'          => $niveau,
                    'couleur'         => $couleur,
                    'emoji'           => $emoji,
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ChargeLocataireService] getCautionRiskOwnerAlerts: ' . $e->getMessage());
        }

        return $alerts;
    }

    /**
     * Modifier une charge (Requête 6)
     * @param array<string, mixed> $data
     */
    public function modifierCharge(int $chargeId, int $locataireId, array $data): bool
    {
        $charge = $this->getChargeById($chargeId, $locataireId);
        if (!$charge) {
            return false;
        }

        $typeCharge = strtoupper($data['type_charge'] ?? 'AUTRE');
        if (!in_array($typeCharge, self::VALID_TYPES, true)) {
            return false;
        }

        $montant = (float)($data['montant'] ?? 0);
        if ($montant <= 0) {
            return false;
        }

        $partageColoc = !empty($charge['partage_coloc']);
        $nbColoc = max(1, (int)($charge['nombre_colocataires'] ?? 1));
        $partLocataire = $partageColoc ? round($montant / $nbColoc, 3) : $montant;

        try {
            $this->conn->beginTransaction();

            $this->conn->executeStatement(
                "UPDATE charges_mensuelles SET
                 type_charge = ?, montant = ?, periode = ?,
                 part_locataire = ?, description = ?, date_modification = CURRENT_TIMESTAMP
                 WHERE id = ?",
                [
                    $typeCharge,
                    number_format($montant, 3, '.', ''),
                    $data['periode'] ?? date('Y-m-01'),
                    number_format($partLocataire, 3, '.', ''),
                    $data['description'] ?? null,
                    $chargeId,
                ]
            );

            // Le nouveau montant peut faire basculer le statut (PAYE/PARTIEL/NON_PAYE).
            $this->updateStatutCharge($chargeId);

            $this->conn->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->conn->isTransactionActive()) {
                $this->conn->rollBack();
            }
            error_log('[ChargeLocataireService] modifierCharge: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer une charge (Requête 7) avec vérification propriété
     */
    public function supprimerCharge(int $chargeId, int $locataireId): bool
    {
        // Vérification propriété avant suppression
        $charge = $this->getChargeById($chargeId, $locataireId);
        if (!$charge) return false;

        try {
            // Supprimer les paiements liés d'abord
            $this->conn->executeStatement(
                'DELETE FROM paiement_charges WHERE charge_id = ?',
                [$chargeId]
            );

            $rows = $this->conn->executeStatement(
                'DELETE FROM charges_mensuelles WHERE id = ?',
                [$chargeId]
            );
            return $rows > 0;
        } catch (\Exception $e) {
            error_log('[ChargeLocataireService] supprimerCharge: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mettre à jour le fichier facture (après upload)
     */
    public function updateFichierFacture(int $chargeId, int $locataireId, string $filename): bool
    {
        if (!$this->getChargeById($chargeId, $locataireId)) return false;

        try {
            $rows = $this->conn->executeStatement(
                'UPDATE charges_mensuelles SET fichier_facture = ?, date_modification = CURRENT_TIMESTAMP WHERE id = ?',
                [$filename, $chargeId]
            );
            return $rows > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SECTION 4 : PAIEMENT MANUEL (NON-STRIPE)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Marquer comme payé manuellement avec preuve (Requête 5 + 8 + 9)
     * Upload preuve de paiement puis insertion du paiement.
     */
    public function marquerCommePaye(
        int    $chargeId,
        int    $locataireId,
        float  $montant,
        string $methode    = 'MANUEL',
        string $reference  = '',
        string $notes      = 'Paiement enregistré par le locataire'
    ): bool {
        if (!$this->getChargeById($chargeId, $locataireId)) return false;

        try {
            $this->conn->beginTransaction();

            // 1. Insertion paiement (Requête 8)
            $this->conn->executeStatement(
                "INSERT INTO paiement_charges 
                (charge_id, montant_paye, date_paiement, methode_paiement,
                 reference_transaction, notes, date_creation)
                VALUES (?, ?, CURRENT_TIMESTAMP, ?, ?, ?, CURRENT_TIMESTAMP)",
                [
                    $chargeId,
                    number_format($montant, 3, '.', ''),
                    $methode,
                    $reference ?: null,
                    $notes,
                ]
            );

            // 2. Mise à jour statut automatique (Requête 9)
            $this->updateStatutCharge($chargeId);

            $this->conn->commit();
            return true;
        } catch (\Exception $e) {
            $this->conn->rollBack();
            error_log('[ChargeLocataireService] marquerCommePaye: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mise à jour automatique du statut NON_PAYE/PARTIEL/PAYE (Requête 9)
     */
    public function updateStatutCharge(int $chargeId): bool
    {
        try {
            // Calcul plus sûr en PHP plutôt qu'une sous-requête MySQL complexe
            $sum = (float) $this->conn->executeQuery(
                "SELECT SUM(CAST(montant_paye AS DECIMAL(10,3))) FROM paiement_charges WHERE charge_id = ?",
                [$chargeId]
            )->fetchOne();

            $charge = $this->conn->executeQuery(
                "SELECT montant, part_locataire, partage_coloc FROM charges_mensuelles WHERE id = ?",
                [$chargeId]
            )->fetchAssociative();

            if (!$charge) return false;

            $montantDu = !empty($charge['partage_coloc']) ? (float)$charge['part_locataire'] : (float)$charge['montant'];

            if ($sum >= ($montantDu - 0.001)) { // Marge d'arrondi
                $statut = 'PAYE';
            } elseif ($sum > 0) {
                $statut = 'PARTIEL';
            } else {
                $statut = 'NON_PAYE';
            }

            $this->conn->executeStatement(
                "UPDATE charges_mensuelles SET statut_paiement = ?, date_modification = CURRENT_TIMESTAMP WHERE id = ?",
                [$statut, $chargeId]
            );

            return true;
        } catch (\Exception $e) {
            error_log('[ChargeLocataireService] updateStatutCharge: ' . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SECTION 5 : PAIEMENT STRIPE CHECKOUT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Créer une session Stripe Checkout pour une charge
     * @return array<string, mixed>
     */
    public function createStripeCheckoutSession(
        int    $chargeId,
        int    $locataireId,
        string $successUrl,
        string $cancelUrl
    ): array {
        $charge = $this->getChargeById($chargeId, $locataireId);
        if (!$charge) {
            return ['error' => 'Charge introuvable ou accès non autorisé'];
        }

        $stripeKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($stripeKey)) {
            return ['error' => 'Stripe non configuré (STRIPE_SECRET_KEY manquante)'];
        }

        try {
            \Stripe\Stripe::setApiKey($stripeKey);

            $montantA = (float)($charge['montant_a_payer'] ?? $charge['montant']);
            $montantCents = (int)round($montantA * 1000); // TND en millimes → passer en millimes

            $meta = self::TYPE_ICONS[$charge['type_charge']] ?? self::TYPE_ICONS['AUTRE'];
            $periodeLabel = $charge['periode'] ? date('F Y', strtotime($charge['periode'])) : 'Période N/A';

            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency'     => 'tnd',
                        'unit_amount'  => $montantCents,
                        'product_data' => [
                            'name'        => $meta['label'] . ' — ' . $periodeLabel,
                            'description' => $charge['description'] ?? 'Charge locative Sakan',
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'mode'        => 'payment',
                'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}&charge_id=' . $chargeId,
                'cancel_url'  => $cancelUrl,
                'metadata'    => [
                    'charge_id'    => (string)$chargeId,
                    'locataire_id' => (string)$locataireId,
                    'type_charge'  => $charge['type_charge'],
                    'source'       => 'sakan_charges_locataire',
                ],
            ]);

            return [
                'session_id'  => $session->id,
                'checkout_url'=> $session->url,
            ];

        } catch (\Exception $e) {
            error_log('[ChargeLocataireService] Stripe: ' . $e->getMessage());
            return ['error' => 'Erreur Stripe: ' . $e->getMessage()];
        }
    }

    /**
     * Valider un paiement Stripe après retour (confirmation webhook / success)
     */
    public function confirmStripePayment(string $sessionId, int $chargeId, int $locataireId): bool
    {
        $stripeKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($stripeKey)) return false;

        try {
            \Stripe\Stripe::setApiKey($stripeKey);
            $session = \Stripe\Checkout\Session::retrieve($sessionId);

            if ($session->payment_status !== 'paid') return false;

            $montant = $session->amount_total / 1000; // millimes → TND

            return $this->marquerCommePaye(
                $chargeId,
                $locataireId,
                $montant,
                'STRIPE',
                $session->payment_intent ?? $sessionId,
                'Paiement Stripe validé — Session: ' . $sessionId
            );

        } catch (\Exception $e) {
            error_log('[ChargeLocataireService] confirmStripePayment: ' . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SECTION 6 : EMAIL NOTIFICATIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Envoie un email de notification au proprietaire quand une charge est declaree payee.
     * @param array<string, mixed> $charge
     * @param array<string, mixed> $locataire
     */
    public function sendPaymentConfirmationEmail(
        array  $charge,
        array  $locataire,
        float  $montantPaye,
        string $methode,
        string $fichierPreuve = ''
    ): bool {
        $owner = $this->getProprietaireByContrat((int)($charge['contrat_id'] ?? 0));

        $apiKey      = $_ENV['BREVO_API_KEY']      ?? '';
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? 'noreply@sakan.tn';
        $senderName  = $_ENV['BREVO_SENDER_NAME']  ?? 'Sakan Platform';

        if (empty($apiKey) || empty($owner['email'])) return false;

        $meta           = self::TYPE_ICONS[$charge['type_charge']] ?? self::TYPE_ICONS['AUTRE'];
        $periodeStr     = $charge['periode'] ? date('F Y', strtotime($charge['periode'])) : 'N/A';
        $montantFmt     = number_format($montantPaye, 3, ',', ' ');
        $locataireNom   = trim(($locataire['prenom'] ?? '') . ' ' . ($locataire['nom'] ?? ''));
        $proprietaireNom= trim(($owner['prenom'] ?? '') . ' ' . ($owner['nom'] ?? ''));

        // Section preuve de paiement
        $preuveHtml = '';
        if (!empty($fichierPreuve)) {
            $ext      = strtolower(pathinfo($fichierPreuve, PATHINFO_EXTENSION));
            $isImage  = in_array($ext, ['jpg', 'jpeg', 'png', 'webp']);
            $preuveUrl = 'http://127.0.0.1:8000/uploads/factures/' . basename($fichierPreuve);
            $imgBlock  = $isImage
                ? "<div style='text-align:center;margin:10px 0;'>"
                  . "<img src='{$preuveUrl}' alt='Preuve de paiement' "
                  . "style='max-width:100%;max-height:300px;border-radius:8px;border:1px solid #d1fae5;'>"
                  . "</div>"
                : '';
            $preuveHtml = "
    <div style='margin-top:20px;padding:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;'>
        <p style='font-size:13px;font-weight:700;color:#15803d;margin:0 0 10px 0;'>
            \xF0\x9F\x93\x8E Preuve de paiement jointe
        </p>
        {$imgBlock}
        <div style='text-align:center;margin-top:10px;'>
            <a href='{$preuveUrl}' target='_blank'
               style='display:inline-block;padding:10px 20px;background:#16a34a;color:white;
                      text-decoration:none;border-radius:8px;font-size:13px;font-weight:600;'>
                \xF0\x9F\x93\x84 Voir la preuve de paiement
            </a>
        </div>
    </div>";
        }

        $htmlContent = "<!DOCTYPE html>
<html><body style='margin:0;padding:0;font-family:Arial,sans-serif;background:#f3f4f6;'>
<div style='max-width:600px;margin:30px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>
    <div style='background:#4F46E5;padding:28px;text-align:center;'>
        <h1 style='color:white;margin:0;font-size:22px;'>&#10003; Paiement Declar\u00e9</h1>
        <p style='color:#c7d2fe;margin:6px 0 0;font-size:13px;'>Sakan &ndash; Gestion Locative</p>
    </div>
    <div style='padding:28px;'>
        <p style='font-size:15px;'>Bonjour <strong>{$proprietaireNom}</strong>,</p>
        <p style='color:#6b7280;font-size:14px;'>Le locataire <strong>{$locataireNom}</strong> a enregistr\u00e9 une preuve de paiement pour la charge ci-dessous.</p>
        <table style='width:100%;border-collapse:collapse;margin:20px 0;'>
            <tr style='background:#f9fafb;'>
                <td style='padding:12px 16px;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;'>Type de charge</td>
                <td style='padding:12px 16px;font-size:14px;font-weight:600;border-bottom:1px solid #e5e7eb;'>{$meta['label']}</td>
            </tr>
            <tr>
                <td style='padding:12px 16px;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;'>P\u00e9riode</td>
                <td style='padding:12px 16px;font-size:14px;font-weight:600;border-bottom:1px solid #e5e7eb;'>{$periodeStr}</td>
            </tr>
            <tr style='background:#f0fdf4;'>
                <td style='padding:14px 16px;font-size:15px;font-weight:700;'>&#10003; Montant pay\u00e9</td>
                <td style='padding:14px 16px;font-size:18px;font-weight:700;color:#16a34a;'>{$montantFmt} TND</td>
            </tr>
            <tr>
                <td style='padding:12px 16px;font-size:14px;color:#6b7280;'>M\u00e9thode d\u00e9clar\u00e9e</td>
                <td style='padding:12px 16px;font-size:14px;font-weight:600;'>{$methode}</td>
            </tr>
        </table>
        <p style='font-size:13px;color:#9ca3af;'>&#128197; Date : " . date('d/m/Y H:i') . "</p>
        {$preuveHtml}
    </div>
    <div style='background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb;'>
        <p style='font-size:12px;color:#9ca3af;margin:0;'>
            Consultez votre interface Propri\u00e9taire pour contr\u00f4ler cette facture.<br>
            &copy; " . date('Y') . " Sakan &ndash; Gestion Locative
        </p>
    </div>
</div>
</body></html>";

        $body = [
            'sender'      => ['name' => $senderName, 'email' => $senderEmail],
            'to'          => [[
                'email' => $owner['email'],
                'name'  => $proprietaireNom ?: 'Proprietaire',
            ]],
            'subject'     => 'Sakan - Paiement declare par votre locataire (' . $meta['label'] . ')',
            'htmlContent' => $htmlContent,
        ];

        try {
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            if ($ch === false) { return false; }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => (string)(json_encode($body) ?: '{}'),
                CURLOPT_HTTPHEADER     => [
                    'api-key: ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT       => 30,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode !== 201) {
                error_log('[ChargeLocataireService] Brevo HTTP ' . $httpCode . ': ' . $response);
            }
            return $httpCode === 201;
        } catch (\Exception $e) {
            error_log('[ChargeLocataireService] Email: ' . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SECTION 7 : UPLOAD FICHIER
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Sauvegarder un fichier facture uploadé dans public/uploads/factures/
     * Retourne le nom du fichier ou false.
     */
    public function saveFactureFile(
        \Symfony\Component\HttpFoundation\File\UploadedFile $file,
        int    $chargeId,
        string $type,
        string $projectDir
    ): string|false {
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        $allowedExts  = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $ext          = strtolower($file->guessExtension() ?? '');

        if (!in_array($file->getMimeType(), $allowedMimes) || !in_array($ext, $allowedExts)) {
            return false;
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            return false;
        }

        $uploadDir = $projectDir . '/public/uploads/factures';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $safeType = preg_replace('/[^a-zA-Z0-9]/', '_', $type);
        $filename = time() . '_' . $safeType . '_charge' . $chargeId . '.' . $ext;

        try {
            $file->move($uploadDir, $filename);
            return $filename;
        } catch (\Exception $e) {
            error_log('[ChargeLocataireService] saveFactureFile: ' . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SECTION 8 : HELPERS INTERNES
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string> */
    public function getValidTypes(): array
    {
        return self::VALID_TYPES;
    }

    /** @return array<string, mixed> */
    public function getTypeIcons(): array
    {
        return self::TYPE_ICONS;
    }

    /**
     * Récupérer les infos du locataire (email, nom) pour les emails
     * @return array<string, mixed>|null
     */
    public function getLocataireInfo(int $locataireId): ?array
    {
        return $this->conn->executeQuery(
            "SELECT id, nom, '' AS prenom, email, telephone FROM utilisateur WHERE id = ?",
            [$locataireId]
        )->fetchAssociative() ?: null;
    }

    /**
     * Vérifie si le contrat appartient bien au locataire
     * @return array<string, mixed>|null
     */
    public function getProprietaireByContrat(int $contratId): ?array
    {
        if ($contratId <= 0) {
            return null;
        }

        return $this->conn->executeQuery(
            "SELECT u.nom, '' AS prenom, u.email
             FROM utilisateur u
             INNER JOIN annonce a ON a.proprietaireId = u.id
             INNER JOIN contrat c ON c.annonceId = a.id
             WHERE c.id = ?",
            [$contratId]
        )->fetchAssociative() ?: null;
    }

    public function ownsContrat(int $contratId, int $locataireId): bool
    {
        $row = $this->conn->executeQuery(
            'SELECT id FROM contrat WHERE id = ? AND locataireId = ?',
            [$contratId, $locataireId]
        )->fetchAssociative();
        return $row !== false;
    }

    /**
     * Enrichissement d'une rangée avec métadonnées icon/couleur
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichRow(array $row): array
    {
        $type = $row['type_charge'] ?? 'AUTRE';
        $meta = self::TYPE_ICONS[$type] ?? self::TYPE_ICONS['AUTRE'];
        $row['icon']        = $meta['icon'];
        $row['color']       = $meta['color'];
        $row['label_type']  = $meta['label'];

        // Calcul montant restant (si non présent)
        if (!isset($row['montant_restant'])) {
            $montantA = (float)($row['montant_a_payer'] ?? $row['montant'] ?? 0);
            $dejaPaye = (float)($row['montant_paye_total'] ?? 0);
            $row['montant_restant'] = max(0, $montantA - $dejaPaye);
        }

        return $row;
    }
}





