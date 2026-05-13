<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class CautionRecuService
{
    private EntityManagerInterface $em;
    private EmailService           $emailService;
    private CautionAuditService    $auditService;

    public function __construct(
        EntityManagerInterface $em,
        EmailService           $emailService,
        CautionAuditService    $auditService
    ) {
        $this->em           = $em;
        $this->emailService = $emailService;
        $this->auditService = $auditService;
    }

    /**
     * Génère le reçu en base + envoie l'email de remboursement au locataire.
     *
     * @param float|null $amountPaidOverride  Montant réellement payé via Stripe.
     *   Si fourni, il PRIME sur la valeur lue en DB (montant_rembourse peut encore
     *   être à 0 si la transaction n'est pas encore commitée côté lecture).
     */
    public function genererRecu(int $cautionId, string $transactionReference, float $amountPaidOverride = null): bool
    {
        $conn = $this->em->getConnection();

        // 1. Récupérer toutes les données nécessaires (caution + contrat + locataire + propriétaire)
        $sql = "
            SELECT 
                ca.id,
                ca.montant_initial,
                ca.montant_retention,
                ca.montant_rembourse,
                ca.description_retenue,
                ca.date_remboursement,
                ca.statut,
                c.id       AS contrat_id,
                a.titre    AS titre_annonce,
                u.nom      AS locataire_nom,
                u.email    AS locataire_email,
                u2.nom     AS proprietaire_nom
            FROM caution ca
            INNER JOIN contrat c  ON ca.contrat_id = c.id
            INNER JOIN annonce a  ON c.annonceId = a.id
            INNER JOIN utilisateur u  ON c.locataireId = u.id
            INNER JOIN utilisateur u2 ON a.proprietaireId = u2.id
            WHERE ca.id = ?
        ";
        $data = $conn->fetchAssociative($sql, [$cautionId]);

        if (!$data) {
            return false;
        }

        // 2. Récupérer les photos pour l'email (avec analyses Gemini — identique Java)
        $photos = $conn->fetchAllAssociative(
            'SELECT * FROM caution_retenue_photo WHERE caution_id = ? ORDER BY id ASC',
            [$cautionId]
        );

        // 3. Calculer les montants
        $montantInitial    = (float)$data['montant_initial'];
        $montantRetention  = (float)($data['montant_retention'] ?? 0);

        // PRIORITÉ au montant passé en paramètre (Stripe vient de l'enregistrer,
        // mais la lecture DB peut retourner encore l'ancienne valeur 0)
        if ($amountPaidOverride !== null && $amountPaidOverride > 0) {
            $montantRembourse = $amountPaidOverride;
            error_log('[CautionRecuService] montantRembourse depuis paramètre Stripe : ' . $amountPaidOverride . ' TND');
        } else {
            $montantRembourse = (float)($data['montant_rembourse'] ?? ($montantInitial - $montantRetention));
            error_log('[CautionRecuService] montantRembourse depuis DB : ' . $montantRembourse . ' TND');
        }

        $now               = new \DateTime();
        $timestamp         = time();

        $numeroRecu           = "REC-CAUTION-{$cautionId}-{$timestamp}";
        $dateCreationStr      = $now->format('Y-m-d H:i:s');
        $dateRemboursementStr = $data['date_remboursement'] ?? $dateCreationStr;

        // 4. INSERT dans recu_remboursement_caution
        $insertSql = "
            INSERT INTO recu_remboursement_caution
            (caution_id, contrat_id, numero_recu, montant_initial, montant_retenu,
             montant_rembourse, description_retenue, transaction_reference,
             locataire_nom, locataire_email, proprietaire_nom,
             date_remboursement, date_creation)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE date_creation = date_creation
        ";

        try {
            $conn->executeStatement($insertSql, [
                $cautionId,
                $data['contrat_id'],
                $numeroRecu,
                number_format($montantInitial, 3, '.', ''),
                number_format($montantRetention, 3, '.', ''),
                number_format($montantRembourse, 3, '.', ''),
                $data['description_retenue'],
                $transactionReference,
                $data['locataire_nom'],
                $data['locataire_email'],
                $data['proprietaire_nom'],
                $dateRemboursementStr,
                $dateCreationStr,
            ]);
        } catch (\Exception $e) {
            // L'erreur INSERT ne doit pas bloquer — log et continue
            error_log('[CautionRecuService] INSERT error: ' . $e->getMessage());
        }

        // 5. Récupérer le détail des charges impayées (pour l'email audit trail)
        $chargesImpayees = [];
        try {
            $audit           = $this->auditService->calculateSmartAudit($cautionId);
            $chargesImpayees = $audit['charges_detail'] ?? [];
        } catch (\Throwable $e) {
            error_log('[CautionRecuService] Audit charges error (non-blocking): ' . $e->getMessage());
        }

        // 6. Envoyer email si email locataire disponible (non-bloquant comme Java)
        $locataireEmail = trim($data['locataire_email'] ?? '');
        if (!empty($locataireEmail)) {
            try {
                $statut = $montantRetention > 0 ? 'PARTIELLEMENT_REMBOURSE' : 'TOTALEMENT_REMBOURSE';

                error_log(sprintf(
                    '[CautionRecuService] → Déclenchement email remboursement caution #%d pour %s (%.3f TND remboursés / %.3f TND retenus)',
                    $cautionId, $locataireEmail, $montantRembourse, $montantRetention
                ));

                $sent = $this->emailService->notifierRemboursementCaution([
                    'locataire_nom'       => $data['locataire_nom'],
                    'locataire_email'     => $locataireEmail,
                    'nom_proprietaire'    => $data['proprietaire_nom'],
                    'montant_initial'     => $montantInitial,
                    'montant_retenu'      => $montantRetention,
                    'montant_rembourse'   => $montantRembourse,
                    'description_retenue' => $data['description_retenue'],
                    'date_remboursement'  => $now->format('d/m/Y H:i'),
                    'statut'              => $statut,
                    'photos'              => $photos,
                    'numero_recu'         => $numeroRecu,
                    'charges_impayees'    => $chargesImpayees,
                ]);

                if ($sent) {
                    error_log('[CautionRecuService] ✅ Email remboursement envoyé avec succès à ' . $locataireEmail);
                } else {
                    error_log('[CautionRecuService] ❌ ÉCHEC envoi email à ' . $locataireEmail . ' — vérifier logs EmailService');
                }
            } catch (\Exception $e) {
                // Non-bloquant (identique au catch Java)
                error_log('[CautionRecuService] ❌ Email exception (non-blocking): ' . $e->getMessage());
            }
        } else {
            error_log('[CautionRecuService] ⚠️ Email locataire vide pour caution #' . $cautionId . ' — email non envoyé');
        }

        return true;
    }
}
