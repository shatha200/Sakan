<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GeminiInvoiceService — Analyse automatique de factures via Google Gemini AI
 * Port exact de GeminiInvoiceService.java (gemini-2.5-flash-lite)
 */
class GeminiInvoiceService
{
    private const GEMINI_MODEL    = 'gemini-2.5-flash-lite';
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/'
                                  . self::GEMINI_MODEL . ':generateContent';
    private const MAX_FILE_SIZE   = 4 * 1024 * 1024;   // 4 MB images
    private const MAX_PDF_SIZE    = 10 * 1024 * 1024;  // 10 MB PDFs
    private const MAX_IMAGE_DIM   = 1920;

    private string $apiKey;

    public function __construct(private readonly HttpClientInterface $client)
    {
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    }

    /**
     * Analyse un fichier facture (image ou PDF) et retourne les données structurées.
     * Retry automatique sur erreur 429 (quota Gemini).
     *
     * @param string $filePath  Chemin absolu vers le fichier
     * @param string $mimeType  MIME type (image/jpeg, image/png, application/pdf…)
     * @return array{type_charge:string, periode:string, montant:float, statut_paiement:string,
     *               partage_coloc:bool, nombre_colocataires:int, description:string}
     */
    public function analyzeInvoice(string $filePath, string $mimeType): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Clé API Gemini manquante (GEMINI_API_KEY)');
        }

        [$data, $resolvedMime] = $this->prepareFile($filePath, $mimeType);
        $base64 = base64_encode((string)$data);

        $body = $this->buildRequestBody($base64, $resolvedMime);
        $url  = self::GEMINI_ENDPOINT . '?key=' . $this->apiKey;

        $retryDelays = [10, 30, 60]; // seconds
        $lastError   = null;

        for ($attempt = 0; $attempt <= count($retryDelays); $attempt++) {
            try {
                $response = $this->client->request('POST', $url, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => json_encode($body),
                    'timeout' => 60,
                ]);

                $status = $response->getStatusCode();

                if ($status === 200) {
                    /** @var array{type_charge:string,periode:string,montant:float,statut_paiement:string,partage_coloc:bool,nombre_colocataires:int,description:string} $result */
                    $result = $this->parseResponse($response->toArray(false));
                    return $result;
                }

                if ($status === 429 && $attempt < count($retryDelays)) {
                    $delay = $retryDelays[$attempt];
                    error_log("[GeminiInvoiceService] Quota 429 — attente {$delay}s (tentative " . ($attempt + 1) . ")");
                    sleep($delay);
                    continue;
                }

                throw new \RuntimeException("Erreur Gemini API (HTTP $status)");

            } catch (\Exception $e) {
                $lastError = $e;
                if ($attempt < count($retryDelays)) {
                    sleep($retryDelays[$attempt]);
                    continue;
                }
            }
        }

        throw $lastError ?? new \RuntimeException('Échec analyse Gemini après plusieurs tentatives');
    }

    // ─────────────────────────────────────────────────────────────────
    // Préparation du fichier (resize images > 1920px ou > 4MB)
    // ─────────────────────────────────────────────────────────────────
    /** @return array{0: string|false, 1: string} */
    private function prepareFile(string $filePath, string $mimeType): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Fichier introuvable: $filePath");
        }

        $size = filesize($filePath);
        $ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // PDF : pas de resize, limite 10 MB
        if ($ext === 'pdf' || $mimeType === 'application/pdf') {
            if ($size > self::MAX_PDF_SIZE) {
                throw new \RuntimeException('PDF trop lourd (max 10 MB)');
            }
            return [file_get_contents($filePath), 'application/pdf'];
        }

        // Image : resize si nécessaire
        $needsResize = $size > self::MAX_FILE_SIZE;

        if (!$needsResize && function_exists('getimagesize')) {
            $info = @getimagesize($filePath);
            if ($info) {
                $maxSide = max($info[0], $info[1]);
                if ($maxSide > self::MAX_IMAGE_DIM) {
                    $needsResize = true;
                }
            }
        }

        if (!$needsResize) {
            return [file_get_contents($filePath), $mimeType ?: 'image/jpeg'];
        }

        // GD resize
        if (!extension_loaded('gd')) {
            // GD non disponible, envoyer tel quel
            return [file_get_contents($filePath), $mimeType ?: 'image/jpeg'];
        }

        $src = @imagecreatefromstring((string)file_get_contents($filePath));
        if ($src === false) {
            return [file_get_contents($filePath), $mimeType ?: 'image/jpeg'];
        }

        $origW = imagesx($src);
        $origH = imagesy($src);
        $scale = self::MAX_IMAGE_DIM / max($origW, $origH);
        $newW  = max(1, (int)($origW * $scale));
        $newH  = max(1, (int)($origH * $scale));

        $dst = imagecreatetruecolor($newW, $newH);
        if ($dst === false) {
            imagedestroy($src);
            return [file_get_contents($filePath), $mimeType ?: 'image/jpeg'];
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        ob_start();
        imagejpeg($dst, null, 90);
        $resized = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return [$resized, 'image/jpeg'];
    }

    // ─────────────────────────────────────────────────────────────────
    // Construction du body Gemini avec prompt expert factures tunisiennes
    // ─────────────────────────────────────────────────────────────────
    /** @return array<string, mixed> */
    private function buildRequestBody(string $base64Data, string $mimeType): array
    {
        $prompt = <<<'PROMPT'
Tu es un expert en analyse de factures pour un système tunisien de gestion locative.
Analyse cette image avec une précision maximale. Réponds UNIQUEMENT avec un objet JSON valide.

RÈGLE CRITIQUE N°1 — FORMAT DES MONTANTS TUNISIENS
En Tunisie, les montants utilisent 3 décimales (millimes).
FORMAT A — Point comme séparateur (factures STEG) :
  540.000 = cinq cent quarante dinars = 540 TND
  PIÈGE : 540.000 n'est PAS 540 000. C'est 540 TND.
FORMAT B — Virgule comme séparateur (factures SONEDE) :
  19,700 = dix-neuf dinars et 700 millimes = 19.700 TND

RÈGLE ABSOLUE : Tout montant a 3 chiffres après virgule/point → c'est TOUJOURS un montant en dinars. Ne multiplie JAMAIS par 1000.

Le JSON doit avoir exactement ces champs :
{
  "type_charge": "ELECTRICITE|EAU|GAZ|CHAUFFAGE|INTERNET|ENTRETIEN|ORDURES|CHARGES_COPRO|AUTRE",
  "periode": "YYYY-MM-01",
  "montant": 123.456,
  "statut_paiement": "PAYE|NON_PAYE|PARTIEL",
  "partage_coloc": false,
  "nombre_colocataires": 1,
  "description": "Fournisseur, période, montant, ref..."
}

Règles supplémentaires :
- type_charge : Déduis-le du fournisseur (STEG→ELECTRICITE, SONEDE→EAU, Topnet/Orange→INTERNET…)
- periode : Premier jour du mois de la facture au format YYYY-MM-01
- statut_paiement : PAYE si la facture porte mention "payé/reçu/acquitté", sinon NON_PAYE
- partage_coloc : false par défaut sauf mention explicite de colocation
- JSON uniquement, rien avant ni après, pas de markdown ```
PROMPT;

        return [
            'contents' => [[
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data'     => $base64Data,
                        ]
                    ],
                    ['text' => $prompt],
                ]
            ]],
            'generationConfig' => [
                'temperature'     => 0.0,
                'maxOutputTokens' => 2048,
                'thinkingConfig'  => ['thinkingBudget' => 0]
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Parsing de la réponse Gemini
    // ─────────────────────────────────────────────────────────────────
    /**
     * @param array<string, mixed> $responseData
     * @return array<string, mixed>
     */
    private function parseResponse(array $responseData): array
    {
        // Parcourir tous les parts pour trouver le texte JSON (robuste pour les modèles thinking)
        $text = '';
        $parts = $responseData['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            // Ignorer les parts de type "thought"
            if (isset($part['thought']) && $part['thought'] === true) continue;
            if (!empty($part['text'])) {
                $text = $part['text'];
                break;
            }
        }

        if (empty($text)) {
            error_log('[GeminiInvoiceService] Réponse vide de Gemini. Data: ' . json_encode($responseData));
            throw new \RuntimeException('Réponse vide de Gemini');
        }

        // Nettoyage markdown ```json ... ```
        $text = preg_replace('/```json\s*/i', '', $text) ?? $text;
        $text = preg_replace('/```\s*/', '', $text) ?? $text;
        $text = trim($text);

        // Extraire le JSON même si du texte précède
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $text = $matches[0];
        }

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            error_log('[GeminiInvoiceService] JSON invalide: ' . $text);
            throw new \RuntimeException('Réponse Gemini invalide: impossible de parser le JSON');
        }

        $validTypes = [
            'ELECTRICITE', 'EAU', 'GAZ', 'CHAUFFAGE', 'INTERNET',
            'ENTRETIEN', 'ORDURES', 'CHARGES_COPRO', 'AUTRE'
        ];

        $typeCharge = strtoupper($data['type_charge'] ?? 'AUTRE');
        if (!in_array($typeCharge, $validTypes)) {
            $typeCharge = 'AUTRE';
        }

        $statut = strtoupper($data['statut_paiement'] ?? 'NON_PAYE');
        if (!in_array($statut, ['PAYE', 'NON_PAYE', 'PARTIEL'])) {
            $statut = 'NON_PAYE';
        }

        // Validation et nettoyage de la période
        $periode = $data['periode'] ?? date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periode)) {
            $periode = date('Y-m-01');
        }

        return [
            'type_charge'          => $typeCharge,
            'periode'              => $periode,
            'montant'              => round((float)($data['montant'] ?? 0), 3),
            'statut_paiement'      => $statut,
            'partage_coloc'        => (bool)($data['partage_coloc'] ?? false),
            'nombre_colocataires'  => max(1, (int)($data['nombre_colocataires'] ?? 1)),
            'description'          => (string)($data['description'] ?? ''),
        ];
    }
}
