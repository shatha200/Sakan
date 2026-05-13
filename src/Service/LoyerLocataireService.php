<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LoyerLocataireService — Identique à LoyerService.java
 *
 * Flux métier:
 *   - Lecture loyers par contrat, groupage EN_RETARD / EN_ATTENTE / PAYE
 *   - Paiement via Stripe Checkout (contrairement aux Charges qui sont hors-ligne)
 *   - Génération quittance PDF (référencée dans table `facture`)
 *   - Notification email Brevo au propriétaire après paiement
 */
class LoyerLocataireService
{
    public function __construct(
        private Connection $conn,
        private HttpClientInterface $httpClient,
    ) {}

    /**
     * Parse une période dans différents formats possibles
     * Formats supportés: 2025-T3 (trimestre), 2025-01-01 (date), 2025-01 (mois), etc.
     */
    private function parsePeriode(?string $periode): ?\DateTimeInterface
    {
        if (!$periode) return null;

        // Format trimestriel: 2025-T3 → 2025-09-01 (début du trimestre)
        if (preg_match('/^(\d{4})-T(\d)$/', $periode, $matches)) {
            $year = $matches[1];
            $trimestre = (int)$matches[2];
            $month = (($trimestre - 1) * 3) + 1;
            return new \DateTime("$year-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . "-01");
        }

        // Format mois: 2025-01 → 2025-01-01
        if (preg_match('/^(\d{4})-(\d{2})$/', $periode, $matches)) {
            return new \DateTime($periode . '-01');
        }

        // Format date standard: 2025-01-05
        try {
            return new \DateTime($periode);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. LOGEMENTS DU LOCATAIRE (housing selector)
    // ═══════════════════════════════════════════════════════════════════

    /** @return list<array<string, mixed>> */
    public function getLogementsByLocataire(int $locataireId): array
    {
        // Doctrine Doctor fix: paramétrer les chaînes avec '#' ou literals pour éviter le warning injection
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

    // ═══════════════════════════════════════════════════════════════════
    // 2. LOYERS PAR CONTRAT avec calcul retard
    // ═══════════════════════════════════════════════════════════════════

    /** @return list<array<string, mixed>> */
    public function getLoyersByContrat(int $contratId): array
    {
        $sql = "
            SELECT pl.id, pl.contrat_id, pl.periode, pl.montant, pl.penalite,
                pl.date_echeance, pl.date_paiement, pl.statut,
                pl.methode_paiement, pl.reference_transaction
            FROM paiement_loyer pl
            WHERE pl.contrat_id = :contratId
            ORDER BY pl.periode DESC
        ";

        $rows = $this->conn->executeQuery($sql, [
            'contratId' => $contratId,
        ])->fetchAllAssociative();

        // Doctrine Doctor fix: Résoudre le problème N+1 sur les quittances
        $paiementIds = array_column($rows, 'id');
        $quittances = [];
        
        if (!empty($paiementIds)) {
            // Créer les placeholders (?,?,?)
            $inClause = implode(',', array_fill(0, count($paiementIds), '?'));
            
            $sqlQuittances = "
                SELECT reference_id, fichier
                FROM facture
                WHERE type_reference = ?
                  AND type_document = ?
                  AND reference_id IN ($inClause)
                ORDER BY id DESC
            ";
            
            $params = array_merge(['PAIEMENT_LOYER', 'QUITTANCE'], $paiementIds);
            $stmt = $this->conn->executeQuery($sqlQuittances, $params);
            $quittanceRows = $stmt->fetchAllAssociative();
            
            // Prendre seulement le plus récent (le tri DESC gère ça)
            foreach ($quittanceRows as $qRow) {
                $refId = $qRow['reference_id'];
                if (!isset($quittances[$refId])) {
                    $quittances[$refId] = $qRow['fichier'];
                }
            }
        }

        foreach ($rows as &$row) {
            $row['penalite'] = (float)($row['penalite'] ?? 0);
            $row['montant']  = (float)($row['montant']  ?? 0);
            $row['total']    = $row['montant'] + $row['penalite'];

            // Calcul jours de retard
            if ($row['date_echeance']) {
                $echeance   = new \DateTime($row['date_echeance']);
                $echeance->setTime(0, 0, 0);
                $aujourdhui = new \DateTime();
                $aujourdhui->setTime(0, 0, 0);
                $diff = (int)$echeance->diff($aujourdhui)->format('%r%a');
                $row['jours_retard'] = $diff; // positif = en retard
            } else {
                $row['jours_retard'] = 0;
            }

            // Quittance si payé (chargée depuis la table temporaire pour éviter N+1)
            $row['quittance_url'] = null;
            if ($row['statut'] === 'PAYE') {
                $row['quittance_url'] = $quittances[$row['id']] ?? null;
            }
        }

        return $rows;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. UN LOYER PAR ID (avec vérification propriétaire)
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, mixed>|null */
    public function getById(int $id, int $locataireId): ?array
    {
        $sql = "
            SELECT pl.*, c.montant as loyer_mensuel, c.id AS verif_contrat
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            WHERE pl.id = :id AND c.locataireId = :locataireId
        ";

        $row = $this->conn->executeQuery($sql, [
            'id'          => $id,
            'locataireId' => $locataireId,
        ])->fetchAssociative();

        if (!$row) return null;

        $row['penalite'] = (float)($row['penalite'] ?? 0);
        $row['montant']  = (float)($row['montant']  ?? 0);
        $row['total']    = $row['montant'] + $row['penalite'];

        return $row;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. STATS PAR CONTRAT
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    public function getStatsForContrat(int $contratId): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total_paiements,
                SUM(CASE WHEN statut='PAYE' THEN 1 ELSE 0 END) AS paiements_effectues,
                SUM(CASE WHEN statut='EN_RETARD' THEN 1 ELSE 0 END) AS paiements_en_retard,
                SUM(CASE WHEN statut IN ('EN_ATTENTE','PARTIEL') THEN 1 ELSE 0 END) AS paiements_a_venir,
                COALESCE(SUM(CAST(penalite AS DECIMAL(10,3))), 0) AS total_penalites,
                COALESCE(SUM(CASE WHEN statut='PAYE' THEN CAST(montant AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_paye_total,
                COALESCE(SUM(CASE WHEN statut != 'PAYE' THEN CAST(montant AS DECIMAL(10,3)) + COALESCE(CAST(penalite AS DECIMAL(10,3)), 0) ELSE 0 END), 0) AS montant_restant
            FROM paiement_loyer
            WHERE contrat_id = :contratId
        ";

        $stats = $this->conn->executeQuery($sql, [
            'contratId' => $contratId,
        ])->fetchAssociative();

        return $stats ?: [
            'total_paiements'      => 0,
            'paiements_effectues'  => 0,
            'paiements_en_retard'  => 0,
            'paiements_a_venir'    => 0,
            'total_penalites'      => 0,
            'montant_paye_total'   => 0,
            'montant_restant'      => 0,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5. MARQUER COMME PAYÉ (après callback Stripe)
    //    Identique à: loyerService.payerLoyer(id, locataireId, "CARTE", transactionId)
    // ═══════════════════════════════════════════════════════════════════

    public function payerLoyer(
        int    $id,
        int    $locataireId,
        string $methode,
        string $reference
    ): bool {
        // UPDATE avec vérification via JOIN contrat → locataireId
        $sql = "
            UPDATE paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            SET
                pl.date_paiement        = CURRENT_TIMESTAMP,
                pl.statut               = 'PAYE',
                pl.methode_paiement     = :methode,
                pl.reference_transaction = :reference,
                pl.date_modification    = CURRENT_TIMESTAMP
            WHERE pl.id = :id
              AND c.locataireId = :locataireId
        ";

        $affected = $this->conn->executeStatement($sql, [
            'id'          => $id,
            'locataireId' => $locataireId,
            'methode'     => $methode,
            'reference'   => $reference,
        ]);

        return $affected > 0;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6. QUITTANCE — URL depuis table `facture`
    // ═══════════════════════════════════════════════════════════════════

    public function getQuittanceUrl(int $paiementId): ?string
    {
        // Doctrine Doctor fix: paramétrer les literals
        $sql = "
            SELECT fichier
            FROM facture
            WHERE type_reference = :typeRef
              AND reference_id   = :paiementId
              AND type_document  = :typeDoc
            ORDER BY id DESC
            LIMIT 1
        ";

        $result = $this->conn->executeQuery($sql, [
            'paiementId' => $paiementId,
            'typeRef'    => 'PAIEMENT_LOYER',
            'typeDoc'    => 'QUITTANCE',
        ])->fetchOne();

        return $result ?: null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7. ENREGISTRER UNE QUITTANCE EN BASE (après génération PDF)
    //    Identique à: INSERT INTO facture ... (Java ReceiptService)
    // ═══════════════════════════════════════════════════════════════════

    public function enregistrerQuittance(int $paiementId, float $montant, string $fichierUrl): bool
    {
        // Récupérer période du loyer
        $periode = $this->conn->executeQuery(
            "SELECT periode FROM paiement_loyer WHERE id = :id",
            ['id' => $paiementId]
        )->fetchOne();

        // Doctrine Doctor fix: paramétrer les literals
        $sql = "
            INSERT INTO facture
                (type_reference, reference_id, type_document, fichier, periode, montant, date_emission, date_creation)
            VALUES
                (:typeRef, :paiementId, :typeDoc, :fichierUrl, :periode, :montant, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ";

        try {
            $this->conn->executeStatement($sql, [
                'typeRef'    => 'PAIEMENT_LOYER',
                'typeDoc'    => 'QUITTANCE',
                'paiementId' => $paiementId,
                'fichierUrl' => $fichierUrl,
                'periode'    => $periode ?: date('Y-m-01'),
                'montant'    => $montant,
            ]);
            return true;
        } catch (\Exception $e) {
            error_log('[LoyerLocataireService] enregistrerQuittance: ' . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // 8. INFOS LOCATAIRE (pour email)
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, mixed>|null */
    public function getLocataireInfo(int $locataireId): ?array
    {
        // Note: la table utilisateur n'a pas de colonne 'prenom', on la simule
        $sql = "
            SELECT u.nom, u.email, '' as prenom
            FROM utilisateur u
            WHERE u.id = :id
        ";

        return $this->conn->executeQuery($sql, ['id' => $locataireId])->fetchAssociative() ?: null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 9. INFOS PROPRIÉTAIRE par contrat (pour notification email)
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, mixed>|null */
    public function getProprietaireByContrat(int $contratId): ?array
    {
        // Note: la table utilisateur n'a pas de colonne 'prenom'
        $sql = "
            SELECT u.nom, u.email, '' as prenom
            FROM utilisateur u
            INNER JOIN annonce a  ON a.proprietaireId = u.id
            INNER JOIN contrat c  ON c.annonceId      = a.id
            WHERE c.id = :contratId
        ";

        return $this->conn->executeQuery($sql, ['contratId' => $contratId])->fetchAssociative() ?: null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 10. STRIPE — Créer session Checkout
    //     Identique à: stripeService.createCheckoutSession(...) en Java
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    public function createStripeCheckoutSession(
        int    $loyerId,
        int    $locataireId,
        float  $montant,
        string $description,
        string $successUrl,
        string $cancelUrl
    ): array {
        $stripeKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';

        if (empty($stripeKey)) {
            return ['error' => 'Stripe non configuré (STRIPE_SECRET_KEY manquant)'];
        }

        // Stripe exige les montants en centimes (entiers)
        // TND n'est pas supporté par Stripe — on convertit en EUR pour les tests
        $currency   = 'eur'; // Stripe test
        $unitAmount = (int)round($montant * 100);

        $payload = [
            'mode'        => 'payment',
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'line_items'  => [
                [
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => $currency,
                        'unit_amount'  => $unitAmount,
                        'product_data' => [
                            'name' => $description,
                        ],
                    ],
                ],
            ],
            // Métadonnées pour retrouver le loyer dans le webhook
            'metadata' => [
                'loyer_id'     => $loyerId,
                'locataire_id' => $locataireId,
            ],
        ];

        try {
            $response = $this->httpClient->request('POST', 'https://api.stripe.com/v1/checkout/sessions', [
                'auth_basic' => [$stripeKey, ''],
                'body'       => $this->flattenStripeParams($payload),
            ]);

            $data = $response->toArray(false);

            if ($response->getStatusCode() !== 200) {
                $errMsg = $data['error']['message'] ?? 'Erreur Stripe inconnue';
                error_log('[LoyerLocataireService] Stripe error: ' . $errMsg);
                return ['error' => $errMsg];
            }

            return [
                'checkout_url' => $data['url'],
                'session_id'   => $data['id'],
            ];
        } catch (\Exception $e) {
            error_log('[LoyerLocataireService] Stripe exception: ' . $e->getMessage());
            return ['error' => 'Erreur connexion Stripe: ' . $e->getMessage()];
        }
    }

    /**
     * Convertit le payload Stripe imbriqué en format form-urlencoded plat
     * car Stripe n'accepte pas JSON mais des paramètres PHP form-encoded
     */
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function flattenStripeParams(array $params, string $prefix = ''): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            $fullKey = $prefix ? "{$prefix}[{$key}]" : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenStripeParams($value, $fullKey));
            } else {
                $result[$fullKey] = $value;
            }
        }
        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════
    // 11. STRIPE — Vérifier une session Checkout
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, mixed>|null */
    public function retrieveStripeSession(string $sessionId): ?array
    {
        $stripeKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        if (empty($stripeKey)) return null;

        try {
            $response = $this->httpClient->request(
                'GET',
                "https://api.stripe.com/v1/checkout/sessions/{$sessionId}",
                ['auth_basic' => [$stripeKey, '']]
            );

            return $response->toArray(false);
        } catch (\Exception $e) {
            error_log('[LoyerLocataireService] retrieveStripeSession: ' . $e->getMessage());
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // 12. EMAIL — Notification paiement loyer au propriétaire
    //     Identique à: emailService.notifierPaiementLoyer(...) en Java
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @param array<string, mixed> $loyer
     * @param array<string, mixed> $locataire
     * @param array<string, mixed> $proprietaire
     */
    public function sendPaiementLoyerEmail(array $loyer, array $locataire, array $proprietaire): bool
    {
        $apiKey      = $_ENV['BREVO_API_KEY']      ?? '';
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? 'noreply@sakan.tn';
        $senderName  = $_ENV['BREVO_SENDER_NAME']  ?? 'Sakan Platform';

        if (empty($apiKey) || empty($proprietaire['email'])) {
            return false;
        }

        $periode    = ($parsed = $this->parsePeriode($loyer['periode'] ?? null))
            ? $parsed->format('F Y')
            : ($loyer['periode'] ?? 'N/A');
        $montant    = number_format((float)($loyer['montant'] ?? 0), 3, ',', ' ');
        $penalite   = (float)($loyer['penalite'] ?? 0);
        $total      = number_format((float)($loyer['montant'] ?? 0) + $penalite, 3, ',', ' ');
        $methode    = $loyer['methode_paiement'] ?? 'CARTE';
        $reference  = $loyer['reference_transaction'] ?? '';
        $datePay    = $loyer['date_paiement']
            ? (new \DateTime($loyer['date_paiement']))->format('d/m/Y H:i')
            : date('d/m/Y H:i');
        $locNom     = trim(($locataire['prenom'] ?? '') . ' ' . ($locataire['nom'] ?? 'Locataire'));
        $propNom    = trim(($proprietaire['prenom'] ?? '') . ' ' . ($proprietaire['nom'] ?? 'Propriétaire'));

        $penaliteHtml = $penalite > 0
            ? "<tr>
                <td style='padding:12px 16px;font-size:14px;color:#dc2626;border-bottom:1px solid #e5e7eb;'>Pénalité de retard</td>
                <td style='padding:12px 16px;font-size:14px;text-align:right;color:#dc2626;border-bottom:1px solid #e5e7eb;font-weight:600;'>+" . number_format($penalite, 3, ',', ' ') . " TND</td>
              </tr>"
            : '';

        $refHtml = $reference
            ? "<tr><td style='padding:10px 16px;font-size:13px;color:#6b7280;'>Référence transaction</td><td style='padding:10px 16px;font-size:13px;text-align:right;font-family:monospace;'>{$reference}</td></tr>"
            : '';

        $htmlContent = "<!DOCTYPE html><html><body style='margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;'>
  <div style='max-width:600px;margin:30px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>
    <div style='background:#5e17eb;padding:28px;text-align:center;'>
      <h1 style='color:white;margin:0;font-size:22px;'>💰 Paiement de Loyer Reçu</h1>
      <p style='color:#c4b5fd;margin:6px 0 0;font-size:13px;'>Sakan – Gestion Locative</p>
    </div>
    <div style='padding:28px;'>
      <p style='font-size:15px;margin:0 0 16px;'>Bonjour <strong>{$propNom}</strong>,</p>
      <p style='font-size:14px;color:#6b7280;margin:0 0 24px;'>
        Votre locataire <strong style='color:#374151;'>{$locNom}</strong> vient d'effectuer un paiement de loyer via la plateforme Sakan.
      </p>
      <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:20px;text-align:center;margin-bottom:24px;'>
        <div style='font-size:13px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;'>MONTANT TOTAL REÇU</div>
        <div style='font-size:36px;font-weight:800;color:#059669;'>{$total} TND</div>
        <div style='font-size:13px;color:#6b7280;margin-top:4px;'>Période : {$periode}</div>
      </div>
      <table style='width:100%;border-collapse:collapse;margin-bottom:24px;'>
        <tr style='background:#f9fafb;'>
          <td style='padding:12px 16px;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;'>Loyer mensuel</td>
          <td style='padding:12px 16px;font-size:14px;text-align:right;border-bottom:1px solid #e5e7eb;font-weight:600;'>{$montant} TND</td>
        </tr>
        {$penaliteHtml}
        <tr>
          <td style='padding:10px 16px;font-size:13px;color:#6b7280;'>Méthode</td>
          <td style='padding:10px 16px;font-size:13px;text-align:right;font-weight:600;'>{$methode}</td>
        </tr>
        {$refHtml}
        <tr>
          <td style='padding:10px 16px;font-size:13px;color:#6b7280;'>Date</td>
          <td style='padding:10px 16px;font-size:13px;text-align:right;'>{$datePay}</td>
        </tr>
      </table>
    </div>
    <div style='background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb;'>
      <p style='font-size:12px;color:#9ca3af;margin:0;'>Cet email est envoyé automatiquement par Sakan. © " . date('Y') . " Sakan</p>
    </div>
  </div>
</body></html>";

        $body = [
            'sender'      => ['name' => $senderName, 'email' => $senderEmail],
            'to'          => [['email' => $proprietaire['email'], 'name' => $propNom]],
            'subject'     => "Sakan – Paiement loyer {$periode} reçu de {$locNom}",
            'htmlContent' => $htmlContent,
        ];

        try {
            $response = $this->httpClient->request('POST', 'https://api.brevo.com/v3/smtp/email', [
                'headers' => [
                    'api-key'      => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json'    => $body,
                'timeout' => 30,
            ]);

            return $response->getStatusCode() === 201;
        } catch (\Exception $e) {
            error_log('[LoyerLocataireService] Email error: ' . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // 13. EMAIL — Confirmation au locataire après paiement
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @param array<string, mixed> $loyer
     * @param array<string, mixed> $locataire
     */
    public function sendConfirmationLocataireEmail(array $loyer, array $locataire, ?string $quittanceUrl = null): bool
    {
        $apiKey      = $_ENV['BREVO_API_KEY']      ?? '';
        $senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? 'noreply@sakan.tn';
        $senderName  = $_ENV['BREVO_SENDER_NAME']  ?? 'Sakan Platform';

        if (empty($apiKey) || empty($locataire['email'])) return false;

        $periode   = ($parsed = $this->parsePeriode($loyer['periode'] ?? null))
            ? $parsed->format('F Y')
            : ($loyer['periode'] ?? 'N/A');
        $total     = number_format((float)($loyer['total'] ?? $loyer['montant'] ?? 0), 3, ',', ' ');
        $locNom    = trim(($locataire['prenom'] ?? '') . ' ' . ($locataire['nom'] ?? 'Locataire'));
        $reference = $loyer['reference_transaction'] ?? '';

        $quittanceHtml = $quittanceUrl
            ? "<div style='text-align:center;margin-top:20px;'>
                <a href='{$quittanceUrl}' style='background:#059669;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;'>
                  📄 Télécharger votre quittance
                </a>
               </div>"
            : '';

        $htmlContent = "<!DOCTYPE html><html><body style='margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;'>
  <div style='max-width:600px;margin:30px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>
    <div style='background:#059669;padding:28px;text-align:center;'>
      <h1 style='color:white;margin:0;font-size:22px;'>✅ Paiement Confirmé</h1>
      <p style='color:#a7f3d0;margin:6px 0 0;font-size:13px;'>Merci pour votre paiement !</p>
    </div>
    <div style='padding:28px;'>
      <p style='font-size:15px;'>Bonjour <strong>{$locNom}</strong>,</p>
      <p style='font-size:14px;color:#6b7280;'>Votre paiement de loyer pour la période <strong>{$periode}</strong> a bien été enregistré.</p>
      <div style='background:#f0fdf4;border-radius:10px;padding:20px;text-align:center;margin:20px 0;'>
        <div style='font-size:13px;color:#6b7280;margin-bottom:8px;'>Montant payé</div>
        <div style='font-size:32px;font-weight:800;color:#059669;'>{$total} TND</div>
      </div>
      " . ($reference ? "<p style='font-size:12px;color:#9ca3af;text-align:center;'>Référence: <code>{$reference}</code></p>" : '') . "
      {$quittanceHtml}
    </div>
    <div style='background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb;'>
      <p style='font-size:12px;color:#9ca3af;margin:0;'>© " . date('Y') . " Sakan – Gestion Locative</p>
    </div>
  </div>
</body></html>";

        $body = [
            'sender'      => ['name' => $senderName, 'email' => $senderEmail],
            'to'          => [['email' => $locataire['email'], 'name' => $locNom]],
            'subject'     => "Sakan – Confirmation paiement loyer {$periode}",
            'htmlContent' => $htmlContent,
        ];

        try {
            $response = $this->httpClient->request('POST', 'https://api.brevo.com/v3/smtp/email', [
                'headers' => [
                    'api-key'      => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json'    => $body,
                'timeout' => 30,
            ]);
            return $response->getStatusCode() === 201;
        } catch (\Exception $e) {
            error_log('[LoyerLocataireService] Email locataire error: ' . $e->getMessage());
            return false;
        }
    }
}
