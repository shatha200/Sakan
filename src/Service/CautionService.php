<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class CautionService
{
    private EntityManagerInterface $em;
    private CautionRecuService $recuService;
    private CautionAuditService $auditService;

    public function __construct(
        EntityManagerInterface $em,
        CautionRecuService $recuService,
        CautionAuditService $auditService
    ) {
        $this->em = $em;
        $this->recuService = $recuService;
        $this->auditService = $auditService;
    }

    public function terminateContract(int $cautionId): bool
    {
        $conn = $this->em->getConnection();

        $sql1 = "SELECT contrat_id FROM caution WHERE id = ?";
        $contratId = $conn->fetchOne($sql1, [$cautionId]);

        if (!$contratId) {
            return false;
        }

        $sql2 = "UPDATE contrat SET statut = 'TERMINE', date_fin = DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY) WHERE id = ?";
        $conn->executeStatement($sql2, [$contratId]);

        return true;
    }

    public function saveRetentionComplete(int $cautionId, string $retentionAmount, string $description): bool
    {
        $conn = $this->em->getConnection();

        $sql = 'SELECT montant_initial FROM caution WHERE id = ?';
        $montantInitial = (float) $conn->fetchOne($sql, [$cautionId]);

        $retentionAmountFloat = (float) $retentionAmount;
        if ($retentionAmountFloat < 0 || $retentionAmountFloat > $montantInitial) {
            return false;
        }

        if ($retentionAmountFloat > 0 && empty(trim($description))) {
            return false;
        }

        if ($retentionAmountFloat > 0) {
            $countPhotos = $conn->fetchOne(
                'SELECT COUNT(*) FROM caution_retenue_photo WHERE caution_id = ?',
                [$cautionId]
            );
            if ($countPhotos == 0) {
                return false; // Photo justificative obligatoire via IA
            }
        }

        // ── AUDIT INTELLIGENT : calcul automatique des charges impayées ────────
        // Non-bloquant : une erreur ici ne doit jamais empêcher la sauvegarde
        $auditTrailPrefix = '';
        try {
            $audit = $this->auditService->calculateSmartAudit($cautionId);

            // 2. Notifier le propriétaire selon le scénario détecté (in-app)
            $this->auditService->notifyOwner($audit);
        } catch (\Throwable $e) {
            error_log('[CautionService] Audit hook error (non-blocking): ' . $e->getMessage());
        }
        // ──────────────────────────────────────────────────────────────────────

        // Garder uniquement la description saisie par le propriétaire (ne plus polluer avec l'audit)
        $descriptionFinale = trim($description);

        $conn->executeStatement(
            'UPDATE caution
             SET montant_retention     = ?,
                 description_retenue   = ?,
                 date_modification     = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$retentionAmount, $descriptionFinale, $cautionId]
        );

        return true;
    }

    public function confirmRefundPayment(int $cautionId, string $amountPaid, string $transactionId): bool
    {
        $conn = $this->em->getConnection();

        // ── 1. Mettre à jour la caution en DB (statut + montant_rembourse) ──────
        $sql = "UPDATE caution 
                SET montant_rembourse = COALESCE(montant_rembourse, 0) + ?,
                    statut = CASE 
                                WHEN (montant_initial - COALESCE(montant_retention, 0)) <= (COALESCE(montant_rembourse, 0) + ?) THEN 
                                    CASE WHEN COALESCE(montant_retention, 0) > 0 THEN 'PARTIELLEMENT_REMBOURSE' ELSE 'TOTALEMENT_REMBOURSE' END
                                ELSE 'EN_ATTENTE_REMBOURSEMENT'
                             END,
                    date_remboursement = CURRENT_TIMESTAMP,
                    date_modification  = CURRENT_TIMESTAMP 
                WHERE id = ?";

        $conn->executeStatement($sql, [$amountPaid, $amountPaid, $cautionId]);

        // ── 2. Générer le reçu ET envoyer l'email ──────────────────────────────
        // On passe $amountPaid explicitement pour que l'email affiche le bon montant
        // (évite de relire montant_rembourse depuis la DB qui peut être encore 0
        //  si la transaction DB n'est pas encore commitée côté lecture)
        return $this->recuService->genererRecu($cautionId, $transactionId, (float) $amountPaid);
    }

    public function rembourserPartiel(int $cautionId, string $amount): bool
    {
        $conn = $this->em->getConnection();

        $sql = "UPDATE caution 
                SET montant_rembourse = montant_rembourse + ?,
                    statut = CASE 
                                WHEN montant_rembourse + ? >= (montant_initial - montant_retention) THEN 'TOTALEMENT_REMBOURSE' 
                                ELSE 'PARTIELLEMENT_REMBOURSE' 
                             END,
                    date_remboursement = CURRENT_TIMESTAMP, 
                    date_modification = CURRENT_TIMESTAMP
                WHERE id = ?";

        $conn->executeStatement($sql, [$amount, $amount, $cautionId]);

        return true;
    }
}
