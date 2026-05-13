<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * CautionAuditService — Moteur d'Audit Intelligent de Liquidation de Caution
 *
 * Analyse financière automatique déclenchée lors de la clôture d'un contrat.
 * Compare la somme des charges impayées + dégâts (Gemini) au montant initial
 * de la caution et retourne un diagnostic en 3 scénarios.
 *
 * Contraintes : Zéro modification BDD, Doctrine DBAL exclusivement.
 */
class CautionAuditService
{
    // Scénarios métiers retournés par l'audit
    public const SCENARIO_CERTIFIED_OK     = 'CERTIFIED_OK';      // Tout payé, aucune dette
    public const SCENARIO_POSITIVE_BALANCE = 'POSITIVE_BALANCE';  // Caution couvre toute la dette
    public const SCENARIO_CRITICAL_OVERAGE = 'CRITICAL_OVERAGE';  // Dette > Caution

    public function __construct(
        private readonly Connection          $conn,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Calcule l'audit complet de liquidation pour une caution donnée.
     *
     * @param int $cautionId  ID de la caution à analyser
     * @return array<string, mixed>
     */
    public function calculateSmartAudit(int $cautionId): array
    {
        // ── Étape 1 : Récupérer la caution + contexte contrat ─────────────────
        $caution = $this->conn->fetchAssociative(
            'SELECT ca.id, ca.montant_initial, ca.montant_retention, ca.contrat_id,
                    u.nom  AS locataire_nom,  u.email AS locataire_email,
                    u2.nom AS proprietaire_nom, u2.id AS proprietaire_id,
                    a.titre AS titre_bien
             FROM caution ca
             INNER JOIN contrat   c  ON ca.contrat_id  = c.id
             INNER JOIN utilisateur u  ON c.locataireId  = u.id
             INNER JOIN annonce    a  ON c.annonceId    = a.id
             INNER JOIN utilisateur u2 ON a.proprietaireId = u2.id
             WHERE ca.id = ?',
            [$cautionId]
        );

        if (!$caution) {
            return $this->emptyAudit($cautionId, 'Caution introuvable.');
        }

        $contratId          = (int)$caution['contrat_id'];
        $montantInitial     = (float)$caution['montant_initial'];
        $montantRetention   = (float)($caution['montant_retention'] ?? 0); // Issu de Gemini AI

        // ── Étape 2 : Total des charges impayées ou partielles ────────────────
        // Statuts concernés : NON_PAYE et PARTIEL (définis dans ChargeLocataireService)
        $totalImpaye = (float)($this->conn->fetchOne(
            "SELECT COALESCE(SUM(CAST(montant AS DECIMAL(10,3))), 0)
             FROM charges_mensuelles
             WHERE contrat_id = ?
               AND statut_paiement IN ('NON_PAYE', 'PARTIEL')",
            [$contratId]
        ) ?: 0);

        // ── Étape 3 : Détail ligne par ligne des charges impayées ─────────────
        $chargesDetail = $this->conn->fetchAllAssociative(
            "SELECT id, type_charge, periode, 
                    CAST(montant AS DECIMAL(10,3)) AS montant, statut_paiement
             FROM charges_mensuelles
             WHERE contrat_id = ?
               AND statut_paiement IN ('NON_PAYE', 'PARTIEL')
             ORDER BY periode ASC",
            [$contratId]
        ) ?: [];

        // ── Étape 4 : Calcul du solde net restitution ─────────────────────────
        $totalDette          = $totalImpaye + $montantRetention;
        $restitutionTheorique = $montantInitial - $totalDette;

        // ── Étape 5 : Déterminer le scénario ─────────────────────────────────
        $scenario = self::SCENARIO_CERTIFIED_OK;
        if ($totalImpaye > 0 || $montantRetention > 0) {
            $scenario = $restitutionTheorique > 0
                ? self::SCENARIO_POSITIVE_BALANCE
                : self::SCENARIO_CRITICAL_OVERAGE;
        }

        // ── Étape 6 : Message guide pour le propriétaire ──────────────────────
        $msgProprietaire = $this->buildMessage(
            $scenario,
            $totalImpaye,
            $montantRetention,
            $restitutionTheorique,
            $montantInitial,
            $chargesDetail
        );

        return [
            'caution_id'                 => $cautionId,
            'contrat_id'                 => $contratId,
            'locataire_nom'              => $caution['locataire_nom']    ?? '',
            'locataire_email'            => $caution['locataire_email']  ?? '',
            'proprietaire_id'            => (int)($caution['proprietaire_id'] ?? 0),
            'titre_bien'                 => $caution['titre_bien']       ?? '',
            'montant_initial'            => $montantInitial,
            'montant_retention_degats'   => $montantRetention,
            'total_degats_ia'            => $montantRetention, // Alias pour compatibilité frontend
            'total_charges_impayees'     => $totalImpaye,
            'total_dette'                => $totalDette,
            'restitution_theorique'      => $restitutionTheorique,
            'montant_restitution_suggere'=> max(0, $restitutionTheorique), // Alias pour compatibilité frontend
            'charges_detail'             => $chargesDetail,
            'scenario'                   => $scenario,
            'msg_proprietaire'           => $msgProprietaire,
        ];
    }

    /**
     * Génère l'audit trail textuel pour la colonne `description_retenue`.
     * Réutilise la colonne TEXT existante — zéro modification BDD.
     * @param array<string, mixed> $audit
     */
    public function buildAuditTrailText(array $audit): string
    {
        $lines = ['[AUTO-AUDIT SAKAN]'];

        if (!empty($audit['charges_detail'])) {
            $lines[] = 'Charges impayées détectées :';
            foreach ($audit['charges_detail'] as $c) {
                $lines[] = sprintf(
                    '  • %s — %s : %.3f TND [%s]',
                    $c['type_charge'],
                    $c['periode'] ?? 'N/A',
                    (float)$c['montant'],
                    $c['statut_paiement']
                );
            }
            $lines[] = sprintf('Total charges impayées : %.3f TND', $audit['total_charges_impayees']);
        } else {
            $lines[] = 'Aucune charge impayée détectée.';
        }

        if ($audit['montant_retention_degats'] > 0) {
            $lines[] = sprintf('Rétention dégâts (SAKAN AI) : %.3f TND', $audit['montant_retention_degats']);
        }

        $lines[] = sprintf(
            'Restitution nette calculée : %.3f TND (Caution initiale: %.3f TND — Dettes: %.3f TND)',
            $audit['restitution_theorique'],
            $audit['montant_initial'],
            $audit['total_dette']
        );

        return implode("\n", $lines);
    }

    /**
     * Envoie les notifications in-app au propriétaire selon le scénario détecté.
     * Non-bloquant : les erreurs sont loggées mais n'interrompent pas le flux.
     * @param array<string, mixed> $audit
     */
    public function notifyOwner(array $audit): void
    {
        $ownerId  = (int)($audit['proprietaire_id'] ?? 0);
        $scenario = $audit['scenario'] ?? '';

        if ($ownerId <= 0) {
            return;
        }

        try {
            switch ($scenario) {
                case self::SCENARIO_CERTIFIED_OK:
                    $this->notificationService->addNotification(
                        $ownerId,
                        'ALERTE_BIEN',
                        sprintf(
                            '✅ Audit de clôture — %s : Toutes les charges sont réglées. '
                            . 'Restitution intégrale possible : %.3f TND.',
                            $audit['titre_bien'] ?? 'Bien',
                            $audit['montant_initial']
                        )
                    );
                    break;

                case self::SCENARIO_POSITIVE_BALANCE:
                    $nbCharges = count($audit['charges_detail'] ?? []);
                    $this->notificationService->addNotification(
                        $ownerId,
                        'ALERTE_BIEN',
                        sprintf(
                            '⚠️ Audit de clôture — %s : %d charge(s) impayée(s) détectée(s) '
                            . 'pour %.3f TND. Restitution suggérée après déduction : %.3f TND.',
                            $audit['titre_bien'] ?? 'Bien',
                            $nbCharges,
                            $audit['total_charges_impayees'],
                            max(0, $audit['restitution_theorique'])
                        )
                    );
                    break;

                case self::SCENARIO_CRITICAL_OVERAGE:
                    $this->notificationService->addNotification(
                        $ownerId,
                        'ALERTE_BIEN',
                        sprintf(
                            '🚨 CRITIQUE — %s : La dette totale du locataire (%.3f TND) '
                            . 'DÉPASSE la caution (%.3f TND). '
                            . 'Une rétention totale est fortement recommandée.',
                            $audit['titre_bien'] ?? 'Bien',
                            $audit['total_dette'],
                            $audit['montant_initial']
                        )
                    );
                    break;
            }
        } catch (\Throwable $e) {
            error_log('[CautionAuditService] notifyOwner error: ' . $e->getMessage());
        }
    }

    // ── Helpers privés ────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $charges
     */
    private function buildMessage(
        string $scenario,
        float  $totalImpaye,
        float  $retention,
        float  $restitution,
        float  $initial,
        array  $charges
    ): string {
        return match ($scenario) {
            self::SCENARIO_CERTIFIED_OK => sprintf(
                'Toutes les charges ont été réglées et aucun dégât n\'a été constaté. '
                . 'La restitution intégrale de la caution (%.3f TND) est recommandée.',
                $initial
            ),
            self::SCENARIO_POSITIVE_BALANCE => sprintf(
                '%d charge(s) impayée(s) détectée(s) pour un total de %.3f TND%s. '
                . 'Après déduction, le montant à restituer au locataire est de %.3f TND.',
                count($charges),
                $totalImpaye,
                $retention > 0 ? sprintf(' + %.3f TND de dégâts (Gemini)', $retention) : '',
                max(0, $restitution)
            ),
            self::SCENARIO_CRITICAL_OVERAGE => sprintf(
                'ALERTE : La dette totale (%.3f TND de charges + %.3f TND de dégâts = %.3f TND) '
                . 'dépasse la caution initiale de %.3f TND. '
                . 'Il est conseillé de retenir la totalité de la caution et de contacter le locataire '
                . 'pour le solde restant dû.',
                $totalImpaye,
                $retention,
                $totalImpaye + $retention,
                $initial
            ),
            default => 'Audit non concluant.',
        };
    }

    /** @return array<string, mixed> */
    private function emptyAudit(int $cautionId, string $reason): array
    {
        return [
            'caution_id'               => $cautionId,
            'contrat_id'               => 0,
            'locataire_nom'            => '',
            'locataire_email'          => '',
            'proprietaire_id'          => 0,
            'titre_bien'               => '',
            'montant_initial'          => 0.0,
            'montant_retention_degats' => 0.0,
            'total_charges_impayees'   => 0.0,
            'total_dette'              => 0.0,
            'restitution_theorique'    => 0.0,
            'charges_detail'           => [],
            'scenario'                 => self::SCENARIO_CERTIFIED_OK,
            'msg_proprietaire'         => $reason,
        ];
    }
}
