<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class EmailService
{
    private HttpClientInterface $client;
    private string $brevoApiKey;
    private string $brevoSenderEmail;
    private string $brevoSenderName;

    public function __construct(
        HttpClientInterface $client,
        string $brevoApiKey,
        string $brevoSenderEmail,
        string $brevoSenderName
    ) {
        $this->client           = $client;
        // Nettoyer les guillemets résiduels (protection double sécurité)
        $this->brevoApiKey      = trim($brevoApiKey, '"\' ');
        $this->brevoSenderEmail = trim($brevoSenderEmail, '"\' ');
        $this->brevoSenderName  = trim($brevoSenderName, '"\' ');
    }

    /**
     * Notifie le locataire du remboursement de caution
     * Flux identique à EmailNotificationService.notifierRemboursementCaution (Java)
     * @param array<string, mixed> $data
     */
    public function notifierRemboursementCaution(array $data): bool
    {
        // Utilisation des paramètres injectés par Symfony (lecture propre du .env.local)
        $apiKey      = $this->brevoApiKey;
        $senderEmail = $this->brevoSenderEmail;
        $senderName  = $this->brevoSenderName;

        $locataireEmail = trim($data['locataire_email'] ?? '');
        $locataireNom   = $data['locataire_nom']   ?? 'Locataire';

        error_log(sprintf(
            '[EmailService] notifierRemboursementCaution → email=%s | apiKey=%s... | sender=%s',
            $locataireEmail,
            substr($apiKey, 0, 20),
            $senderEmail
        ));

        if (empty($locataireEmail) || empty($apiKey)) {
            error_log('[EmailService] ABORT: locataireEmail ou apiKey vide. email=[' . $locataireEmail . '] key_len=' . strlen($apiKey));
            return false;
        }

        $statut           = $data['statut']              ?? 'TOTALEMENT_REMBOURSE';
        $montantInitial   = (float)($data['montant_initial']   ?? 0);
        $montantRetenu    = (float)($data['montant_retenu']    ?? 0);
        $montantRembourse = (float)($data['montant_rembourse'] ?? 0);
        $proprietaireNom  = htmlspecialchars($data['nom_proprietaire']    ?? 'Le propriétaire');
        $descRetenue      = htmlspecialchars($data['description_retenue'] ?? '');
        $dateRemb         = htmlspecialchars($data['date_remboursement']  ?? date('d/m/Y'));
        $numeroRecu       = htmlspecialchars($data['numero_recu']         ?? '');
        $photos           = $data['photos']           ?? [];

        $estPartiel = $statut === 'PARTIELLEMENT_REMBOURSE' || $montantRetenu > 0;

        $sujet = $estPartiel
            ? 'Sakan – Remboursement partiel de votre caution'
            : 'Sakan – Remboursement intégral de votre caution';

        $htmlContent = $this->buildEmailHtml([
            'locataireNom'    => $locataireNom,
            'proprietaireNom' => $proprietaireNom,
            'montantInitial'  => $montantInitial,
            'montantRetenu'   => $montantRetenu,
            'montantRembourse'=> $montantRembourse,
            'descRetenue'     => $descRetenue,
            'dateRemb'        => $dateRemb,
            'numeroRecu'      => $numeroRecu,
            'estPartiel'      => $estPartiel,
            'photos'          => $photos,
            'charges_impayees'=> $data['charges_impayees'] ?? [], // Détail charges déduites
        ]);

        $body = [
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to'     => [['email' => $locataireEmail, 'name' => $locataireNom]],
            'subject'     => $sujet,
            'htmlContent' => $htmlContent,
        ];

        // Ajouter les photos en pièces jointes pour garantir l'affichage
        $attachments = [];
        foreach ($photos as $photo) {
            $imgUrl = $photo['fichier_url'] ?? '';
            if ($imgUrl && str_starts_with($imgUrl, '/uploads')) {
                // SAKAN FIX: Use base64 content instead of url to avoid Brevo 400 Bad Request on localhost
                $absoluteLocalPath = __DIR__ . '/../../public' . $imgUrl;
                if (file_exists($absoluteLocalPath)) {
                    $attachments[] = [
                        'content' => base64_encode((string)file_get_contents($absoluteLocalPath)),
                        'name'    => basename($imgUrl)
                    ];
                } else {
                    $baseUrl = $_ENV['APP_URL'] ?? ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000');
                    if (!str_starts_with($baseUrl, 'http')) {
                        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                        $baseUrl = $scheme . '://' . $baseUrl;
                    }
                    $absoluteUrl = rtrim($baseUrl, '/') . $imgUrl;
                    $attachments[] = [
                        'url'  => $absoluteUrl,
                        'name' => basename($imgUrl)
                    ];
                }
            } elseif ($imgUrl && str_starts_with($imgUrl, 'http')) {
                $attachments[] = [
                    'url'  => $imgUrl,
                    'name' => basename($imgUrl)
                ];
            }
        }
        if (!empty($attachments)) {
            $body['attachment'] = $attachments;
        }

        try {
            $response = $this->client->request('POST', 'https://api.brevo.com/v3/smtp/email', [
                'headers' => [
                    'api-key'      => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json' => $body,
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            $success    = $statusCode === 201;

            if ($success) {
                error_log('[EmailService] ✅ Email remboursement caution envoyé avec succès à ' . $locataireEmail);
            } else {
                $responseBody = '';
                try { $responseBody = $response->getContent(false); } catch (\Throwable $ignored) {}
                error_log(sprintf(
                    '[EmailService] ❌ Brevo HTTP %d pour %s — Réponse: %s',
                    $statusCode, $locataireEmail, substr($responseBody, 0, 300)
                ));
            }

            // Brevo retourne 201 Created en cas de succès
            return $success;
        } catch (\Exception $e) {
            error_log('[EmailService] ❌ Brevo exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Notifie le locataire lorsqu'une charge est marquée comme payée
     * @param array<string, mixed> $data
     */
    public function notifierPaiementCharge(array $data): bool
    {
        // Utilisation des paramètres injectés par Symfony
        $apiKey      = $this->brevoApiKey;
        $senderEmail = $this->brevoSenderEmail;
        $senderName  = $this->brevoSenderName;

        $locataireEmail = trim($data['locataire_email'] ?? '');
        $locataireNom   = $data['locataire_nom']   ?? 'Locataire';

        if (empty($locataireEmail) || empty($apiKey)) {
            error_log('[EmailService] ABORT notifierPaiementCharge: email ou apiKey vide');
            return false;
        }

        $typeCharge     = htmlspecialchars($data['type_charge'] ?? 'Charge');
        $periode        = htmlspecialchars($data['periode'] ?? '');
        $montantTotal   = (float)($data['montant_total'] ?? 0);
        $montantPaye    = (float)($data['montant_paye'] ?? 0);
        $proprietaireNom= htmlspecialchars($data['proprietaire_nom'] ?? 'Le propriétaire');
        $nomBien        = htmlspecialchars($data['nom_bien'] ?? '');
        $methode        = htmlspecialchars($data['methode'] ?? 'Autre');

        $sujet = 'Sakan – Confirmation de paiement ('. $typeCharge .')';

        $htmlContent = $this->buildEmailChargeHtml([
            'locataireNom'    => $locataireNom,
            'proprietaireNom' => $proprietaireNom,
            'typeCharge'      => $typeCharge,
            'periode'         => $periode,
            'montantTotal'    => $montantTotal,
            'montantPaye'     => $montantPaye,
            'nomBien'         => $nomBien,
            'methode'         => $methode,
        ]);

        $body = [
            'sender' => ['name' => $senderName, 'email' => $senderEmail],
            'to'     => [['email' => $locataireEmail, 'name' => $locataireNom]],
            'subject'     => $sujet,
            'htmlContent' => $htmlContent,
        ];

        try {
            $response = $this->client->request('POST', 'https://api.brevo.com/v3/smtp/email', [
                'headers' => [
                    'api-key'      => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json' => $body,
                'timeout' => 30,
            ]);
            return $response->getStatusCode() === 201;
        } catch (\Exception $e) {
            error_log('[EmailService] Brevo error (Charge): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Construit le template HTML de l'email — identique à buildEmailRemboursementCaution (Java)
     * @param array<string, mixed> $p
     */
    private function buildEmailHtml(array $p): string
    {
        $fmtMnt = fn(float $v): string => number_format($v, 3, ',', ' ');
        $retention = $p['montantInitial'] - $p['montantRembourse'];

        // ─── Section photos avec analyses Gemini ───
        $photosHtml = '';
        if (!empty($p['photos'])) {
            $photosHtml .= '
            <div style="margin-top:24px;">
                <h3 style="font-size:15px;color:#374151;border-bottom:2px solid #e5e7eb;padding-bottom:8px;">
                    📸 Justificatifs de retenue (analysés par IA)
                </h3>';

            foreach ($p['photos'] as $photo) {
                $gravite    = htmlspecialchars($photo['gravite_gemini'] ?? 'AUCUN');
                $type       = htmlspecialchars($photo['type_dommage']   ?? 'AUTRE');
                $motsCles   = htmlspecialchars($photo['mots_cles_gemini'] ?? $photo['mots_cles_valides'] ?? '');
                $analyse    = htmlspecialchars($photo['analyse_gemini']  ?? '');
                $montant    = $photo['montant_estime'] ? $fmtMnt((float)$photo['montant_estime']) . ' TND' : '—';

                $graviteColor = match($gravite) {
                    'CRITIQUE'  => '#dc2626',
                    'IMPORTANT' => '#ea580c',
                    'MODERE'    => '#d97706',
                    'MINEUR'    => '#2563eb',
                    default     => '#6b7280',
                };

                // Badges mots-clés
                $badgesHtml = '';
                if ($motsCles) {
                    foreach (explode(',', $motsCles) as $mc) {
                        $mc = trim(htmlspecialchars($mc));
                        if ($mc) {
                            $badgesHtml .= "<span style='background:#e0e7ff;color:#4338ca;padding:2px 8px;border-radius:12px;font-size:11px;margin:2px;display:inline-block;'>$mc</span>";
                        }
                    }
                }

                // Image (URL publique si disponible)
                $imgHtml = '';
                $imgUrl  = $photo['fichier_url'] ?? '';
                if ($imgUrl && str_starts_with($imgUrl, '/uploads')) {
                    $baseUrl = $_ENV['APP_URL'] ?? ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000');
                    if (!str_starts_with($baseUrl, 'http')) {
                        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                        $baseUrl = $scheme . '://' . $baseUrl;
                    }
                    $absoluteUrl = rtrim($baseUrl, '/') . $imgUrl;
                    $imgHtml = "<div style='margin-top:10px;text-align:center;'>"
                        . "<img src='$absoluteUrl' style='max-width:100%;max-height:200px;border-radius:8px;border:1px solid #e5e7eb;'>"
                        . "</div>";
                } elseif ($imgUrl && str_starts_with($imgUrl, 'http')) {
                    $imgHtml = "<div style='margin-top:10px;text-align:center;'>"
                        . "<img src='$imgUrl' style='max-width:100%;max-height:200px;border-radius:8px;border:1px solid #e5e7eb;'>"
                        . "</div>";
                }

                $photosHtml .= "
                <div style='background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:12px;'>
                    <div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;'>
                        <span style='font-weight:700;font-size:13px;color:#374151;'>$type</span>
                        <span style='background:$graviteColor;color:white;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;'>$gravite</span>
                    </div>
                    " . ($analyse ? "<p style='font-size:13px;color:#6b7280;margin:6px 0;line-height:1.4;'>$analyse</p>" : '') . "
                    <div style='margin:6px 0;'>$badgesHtml</div>
                    $imgHtml
                </div>";
            }
            $photosHtml .= '</div>';
        }

        // ─── Section charges impayées déduites (NOUVEAU — Audit Trail visible) ──────────
        $chargesDeduitsHtml = '';
        $chargesImpayees = $p['charges_impayees'] ?? [];
        if (!empty($chargesImpayees)) {
            $chargesDeduitsHtml = '
            <div style="margin-top:24px;">
                <h3 style="font-size:15px;color:#374151;border-bottom:2px solid #fecaca;padding-bottom:8px;">
                    🧾 Détail des charges déduites de la caution
                </h3>
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="background:#fef2f2;">
                            <th style="padding:10px 14px;font-size:12px;color:#991b1b;text-align:left;">Type</th>
                            <th style="padding:10px 14px;font-size:12px;color:#991b1b;text-align:left;">Période</th>
                            <th style="padding:10px 14px;font-size:12px;color:#991b1b;text-align:right;">Montant</th>
                            <th style="padding:10px 14px;font-size:12px;color:#991b1b;text-align:center;">Statut</th>
                        </tr>
                    </thead>
                    <tbody>';

            $totalChargesDed = 0.0;
            foreach ($chargesImpayees as $charge) {
                $mntCharge = (float)($charge['montant'] ?? 0);
                $totalChargesDed += $mntCharge;
                $statutPaiement = $charge['statut_paiement'] ?? $charge['statut'] ?? 'NON_PAYE';
                $statutColor = $statutPaiement === 'PARTIEL' ? '#d97706' : '#dc2626';
                $chargesDeduitsHtml .= sprintf(
                    '<tr style="border-bottom:1px solid #fee2e2;">'
                    . '<td style="padding:10px 14px;font-size:13px;">%s</td>'
                    . '<td style="padding:10px 14px;font-size:13px;">%s</td>'
                    . '<td style="padding:10px 14px;font-size:13px;text-align:right;font-weight:600;color:#dc2626;">- %s TND</td>'
                    . '<td style="padding:10px 14px;font-size:12px;text-align:center;">'
                    . '<span style="background:%s;color:white;padding:2px 8px;border-radius:10px;">%s</span>'
                    . '</td></tr>',
                    htmlspecialchars($charge['type_charge'] ?? 'N/A'),
                    htmlspecialchars($charge['periode']     ?? 'N/A'),
                    number_format($mntCharge, 3, ',', ' '),
                    $statutColor,
                    htmlspecialchars($charge['statut_paiement'] ?? $charge['statut'] ?? 'N/A')
                );
            }

            $chargesDeduitsHtml .= sprintf(
                '</tbody><tfoot><tr style="background:#fef2f2;">'
                . '<td colspan="2" style="padding:12px 14px;font-size:14px;font-weight:700;color:#991b1b;">'
                . 'Total charges déduites</td>'
                . '<td style="padding:12px 14px;font-size:14px;font-weight:700;text-align:right;color:#dc2626;">- %s TND</td>'
                . '<td></td></tr></tfoot></table></div>',
                number_format($totalChargesDed, 3, ',', ' ')
            );
        }

        // ─── Section retenue (si partielle) ───────────────────────────────────────────────
        $retenueBlock = '';
        if ($p['estPartiel'] && $p['montantRetenu'] > 0) {
            $descHtml = $p['descRetenue']
                ? "<p style='font-size:13px;color:#92400e;margin:6px 0;'><strong>Motif :</strong> {$p['descRetenue']}</p>"
                : '';
            $retenueBlock = "
            <div style='background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:14px;margin:16px 0;'>
                <p style='color:#92400e;font-weight:700;margin:0 0 6px;'>⚠️ Retenue appliquée</p>
                <p style='color:#92400e;margin:4px 0;'><strong>Montant retenu :</strong> {$fmtMnt($p['montantRetenu'])} TND</p>
                $descHtml
            </div>
            $photosHtml";
        }

        // ─── Template principal ───
        $titre = $p['estPartiel']
            ? 'Remboursement partiel de votre caution'
            : 'Remboursement intégral de votre caution ✅';

        $recuLine = $p['numeroRecu']
            ? "<p style='font-size:12px;color:#9ca3af;margin-top:4px;'>N° reçu : {$p['numeroRecu']}</p>"
            : '';

        return "<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;'>
  <div style='max-width:600px;margin:30px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>
    
    <!-- Header -->
    <div style='background:#4F46E5;padding:28px;text-align:center;'>
      <h1 style='color:white;margin:0;font-size:22px;'>Sakan – Gestion Locative</h1>
      <p style='color:#c7d2fe;margin:6px 0 0;font-size:13px;'>Système de remboursement automatique</p>
    </div>

    <!-- Body -->
    <div style='padding:28px;'>
      <h2 style='color:#374151;font-size:18px;margin-bottom:4px;'>$titre</h2>
      $recuLine

      <p style='font-size:15px;margin:16px 0;'>
        Bonjour <strong>{$p['locataireNom']}</strong>,
      </p>
      <p style='font-size:14px;color:#6b7280;margin-bottom:20px;'>
        Le propriétaire <strong style='color:#374151;'>{$p['proprietaireNom']}</strong> 
        a enregistré le remboursement de votre dépôt de garantie via la plateforme Sakan.
      </p>

      <!-- Tableau financier -->
      <table style='width:100%;border-collapse:collapse;margin-bottom:16px;'>
        <tr style='background:#f9fafb;'>
          <td style='padding:12px 16px;font-size:14px;color:#6b7280;border-bottom:1px solid #e5e7eb;'>Caution initiale versée</td>
          <td style='padding:12px 16px;font-size:14px;text-align:right;border-bottom:1px solid #e5e7eb;font-weight:600;'>{$fmtMnt($p['montantInitial'])} TND</td>
        </tr>
        " . ($p['montantRetenu'] > 0 ? "
        <tr>
          <td style='padding:12px 16px;font-size:14px;color:#dc2626;border-bottom:1px solid #e5e7eb;'>Montant retenu</td>
          <td style='padding:12px 16px;font-size:14px;text-align:right;color:#dc2626;border-bottom:1px solid #e5e7eb;font-weight:600;'>- {$fmtMnt($p['montantRetenu'])} TND</td>
        </tr>" : '') . "
        <tr style='background:#f0fdf4;'>
          <td style='padding:14px 16px;font-size:15px;font-weight:700;'>✅ Montant remboursé</td>
          <td style='padding:14px 16px;font-size:18px;font-weight:700;text-align:right;color:#16a34a;'>{$fmtMnt($p['montantRembourse'])} TND</td>
        </tr>
      </table>

      <p style='font-size:13px;color:#9ca3af;'>📅 Date : {$p['dateRemb']}</p>

      $chargesDeduitsHtml

      $retenueBlock

    </div>

    <!-- Footer -->
    <div style='background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb;'>
      <p style='font-size:12px;color:#9ca3af;margin:0;'>
        Cet email est envoyé automatiquement par Sakan. Merci de ne pas y répondre.<br>
        © " . date('Y') . " Sakan – Gestion Locative
      </p>
    </div>
  </div>
</body>
</html>";
    }

    /**
     * Construit le template HTML pour le paiement d'une charge
     * @param array<string, mixed> $p
     */
    private function buildEmailChargeHtml(array $p): string
    {
        $fmtMnt = fn(float $v): string => number_format($v, 3, ',', ' ');
        $datePaiement = date('d/m/Y');

        return "<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;'>
  <div style='max-width:600px;margin:30px auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>
    
    <!-- Header -->
    <div style='background:#10B981;padding:28px;text-align:center;'>
      <h1 style='color:white;margin:0;font-size:22px;'>Sakan – Reçu de paiement</h1>
      <p style='color:#d1fae5;margin:6px 0 0;font-size:13px;'>Vos charges locatives</p>
    </div>

    <!-- Body -->
    <div style='padding:28px;'>
      <h2 style='color:#374151;font-size:18px;margin-bottom:16px;'>Confirmation de paiement</h2>

      <p style='font-size:15px;margin:0 0 16px 0;'>
        Bonjour <strong>{$p['locataireNom']}</strong>,
      </p>
      <p style='font-size:14px;color:#6b7280;margin-bottom:20px;line-height:1.5;'>
        Nous vous confirmons que le propriétaire <strong style='color:#374151;'>{$p['proprietaireNom']}</strong> 
        a bien validé la réception de votre paiement pour la charge suivante :
      </p>

      <div style='background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin-bottom:20px;'>
        <p style='margin:0 0 8px 0;font-size:14px;'><span style='color:#6b7280;'>Type de charge :</span> <strong>{$p['typeCharge']}</strong></p>
        <p style='margin:0 0 8px 0;font-size:14px;'><span style='color:#6b7280;'>Période :</span> <strong>{$p['periode']}</strong></p>
        <p style='margin:0 0 8px 0;font-size:14px;'><span style='color:#6b7280;'>Logement :</span> <strong>{$p['nomBien']}</strong></p>
        <p style='margin:0 0 8px 0;font-size:14px;'><span style='color:#6b7280;'>Méthode de paiement :</span> <strong>{$p['methode']}</strong></p>
      </div>

      <table style='width:100%;border-collapse:collapse;margin-bottom:16px;'>
        <tr style='background:#f0fdf4;'>
          <td style='padding:14px 16px;font-size:15px;font-weight:700;border-radius:8px 0 0 8px;'>💰 Montant payé</td>
          <td style='padding:14px 16px;font-size:18px;font-weight:700;text-align:right;color:#16a34a;border-radius:0 8px 8px 0;'>{$fmtMnt($p['montantPaye'])} TND</td>
        </tr>
      </table>

      <p style='font-size:13px;color:#9ca3af;'>📅 Date d'enregistrement : $datePaiement</p>
      <p style='font-size:14px;color:#6b7280;margin-top:20px;'>
        Merci pour votre réactivité. Vous pouvez retrouver l'historique complet de vos charges sur votre espace Sakan.
      </p>
    </div>

    <!-- Footer -->
    <div style='background:#f9fafb;padding:20px;text-align:center;border-top:1px solid #e5e7eb;'>
      <p style='font-size:12px;color:#9ca3af;margin:0;'>
        Cet email est envoyé automatiquement par Sakan. Merci de ne pas y répondre.<br>
        © " . date('Y') . " Sakan – Gestion Locative
      </p>
    </div>
  </div>
</body>
</html>";
    }
}
