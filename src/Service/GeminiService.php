<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiService
{
    private HttpClientInterface $client;

    // Types ENUM valides dans la table caution_retenue_photo
    private const TYPE_DOMMAGE_MAP = [
        'DEGRADATION'     => 'PEINTURE',
        'SALISSURE'       => 'NETTOYAGE',
        'EQUIPEMENT_CASSE'=> 'MENUISERIE',
        'TRAVAUX_REQUIS'  => 'PLOMBERIE',
        'LOYER_IMPAYE'    => 'AUTRE',
        'PEINTURE'        => 'PEINTURE',
        'MENUISERIE'      => 'MENUISERIE',
        'PLOMBERIE'       => 'PLOMBERIE',
        'ELECTRICITE'     => 'ELECTRICITE',
        'SOL'             => 'SOL',
        'NETTOYAGE'       => 'NETTOYAGE',
        'AUTRE'           => 'AUTRE',
    ];

    private const GRAVITE_VALID = ['AUCUN', 'MINEUR', 'MODERE', 'IMPORTANT', 'CRITIQUE'];

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * @param array<string, mixed> $customTags
     * @return array<string, mixed>
     */
    public function analyserPhoto(string $imageBase64, string $mimeType, array $customTags = [], string $tagsFormates = ''): array
    {
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        if (empty($apiKey)) {
            error_log('[GeminiService] GEMINI_API_KEY manquante');
            return $this->defaultResult('Clé API Gemini non configurée');
        }

        // gemini-2.5-flash-lite confirmé disponible avec generateContent
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key={$apiKey}";

        // ── Architecture Sakan AI : YOLOv8 détecte, Gemini rédige la description en arrière-plan ──
        if (!empty($customTags) && !empty($tagsFormates)) {
            // YOLO a détecté des éléments → Rédaction d'une description pour aider le propriétaire
            $prompt = "Tu es l'assistant IA de la plateforme Sakan.

Le modèle Sakan AI (YOLOv8) a détecté les dégradations suivantes sur cette photo : {$tagsFormates}.
Ces détections sont objectives et factuelles.

Ta mission UNIQUE : rédiger une description factuelle, claire et très professionnelle de ces dégradations, 
destinée à aider le propriétaire à justifier sa retenue sur caution auprès du locataire.
NE PAS estimer de coût. NE PAS inventer d'autres dommages. La description doit être en français uniquement.

Réponds UNIQUEMENT avec ce JSON valide, sans markdown, sans texte avant ou après :
{\"description_fr\":\"Description complète et professionnelle en français (3 à 4 phrases).\",\"gravite\":\"MODERE\",\"type_dommage\":\"PEINTURE\"}

Règles strictes :
- description_fr : 3 à 4 phrases en français clair et professionnel.
- gravite : exactement l'un de : AUCUN, MINEUR, MODERE, IMPORTANT, CRITIQUE
- type_dommage : exactement l'un de : PEINTURE, MENUISERIE, PLOMBERIE, ELECTRICITE, SOL, NETTOYAGE, AUTRE
- Répondre SEULEMENT avec le JSON, rien d'autre";
        } else {
            // Pas de détection YOLO → Gemini décrit visuellement
            $prompt = "Tu es l'assistant IA de la plateforme Sakan.

Regarde cette photo d'état des lieux de sortie de logement.
Rédige une description factuelle, claire et très professionnelle des dommages ou dégradations visibles, 
destinée à aider le propriétaire à justifier sa retenue sur caution auprès du locataire.
NE PAS estimer de coût. La description doit être en français uniquement.

Réponds UNIQUEMENT avec ce JSON valide, sans markdown, sans texte avant ou après :
{\"description_fr\":\"Description complète et professionnelle en français (3 à 4 phrases).\",\"gravite\":\"AUCUN\",\"type_dommage\":\"AUTRE\"}

Règles strictes :
- description_fr : 3 à 4 phrases en français clair et professionnel.
- gravite : exactement l'un de : AUCUN, MINEUR, MODERE, IMPORTANT, CRITIQUE
- type_dommage : exactement l'un de : PEINTURE, MENUISERIE, PLOMBERIE, ELECTRICITE, SOL, NETTOYAGE, AUTRE
- Si aucun dommage visible : {\"description_fr\":\"Aucun dommage visible n'a été détecté sur cette photo.\",\"gravite\":\"AUCUN\",\"type_dommage\":\"AUTRE\"}
- Répondre SEULEMENT avec le JSON, rien d'autre";
        }



        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inlineData' => ['mimeType' => $mimeType, 'data' => $imageBase64]]
                ]
            ]],
            // Désactiver le thinking pour avoir une réponse JSON directe sans blocs intermédiaires
            'generationConfig' => [
                'temperature'     => 0.1,
                'topP'            => 0.9,
                'maxOutputTokens' => 512,
                'thinkingConfig'  => ['thinkingBudget' => 0]
            ]
        ];

        try {
            $maxRetries = 2;
            $httpCode   = 0;
            $response   = null;

            // ── Retry automatique sur 503 (Gemini transiently unavailable) ──────
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $response = $this->client->request('POST', $url, [
                    'json'    => $body,
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 60,
                ]);

                $httpCode = $response->getStatusCode();
                if ($httpCode === 200) break; // Succès → sortie de boucle

                $rawBody = $response->getContent(false);
                error_log("[GeminiService] HTTP {$httpCode} (tentative {$attempt}/{$maxRetries}): {$rawBody}");

                if ($httpCode === 503 && $attempt < $maxRetries) {
                    sleep(2); // Attente courte avant retry
                    continue;
                }
                // Tout autre code ou dernière tentative → on abandonne
                return $this->defaultResult("Erreur HTTP {$httpCode} de l'API Gemini");
            }

            if ($httpCode !== 200) {
                return $this->defaultResult("Erreur HTTP {$httpCode} de l'API Gemini");
            }

            $data = $response->toArray();

            // Parcourir tous les parts pour trouver le texte JSON (robuste pour les modèles thinking)
            $textResponse = '';
            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            foreach ($parts as $part) {
                // Ignorer les parts de type "thought"
                if (isset($part['thought']) && $part['thought'] === true) continue;
                if (!empty($part['text'])) {
                    $textResponse = $part['text'];
                    break;
                }
            }

            if (empty($textResponse)) {
                error_log('[GeminiService] Réponse vide de Gemini. Data: ' . json_encode($data));
                return $this->defaultResult('Réponse vide de Gemini');
            }

            // Nettoyer les éventuels blocs markdown
            $textResponse = preg_replace('/```json\s*/i', '', $textResponse) ?? $textResponse;
            $textResponse = preg_replace('/```\s*/', '', $textResponse) ?? $textResponse;
            $textResponse = trim($textResponse);

            // Extraire le JSON même si du texte précède
            if (preg_match('/\{.*\}/s', $textResponse, $matches)) {
                $textResponse = $matches[0];
            }

            $result = json_decode($textResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
                error_log('[GeminiService] JSON invalide: ' . $textResponse);
                return $this->defaultResult('Réponse IA non parseable: ' . $textResponse);
            }

            // Normaliser le type_dommage vers l'ENUM valide en base
            $typeBrut   = strtoupper(trim($result['type_dommage'] ?? 'AUTRE'));
            $typeValide = self::TYPE_DOMMAGE_MAP[$typeBrut] ?? 'AUTRE';

            // Normaliser la gravité
            $graviteBrut   = strtoupper(trim($result['gravite'] ?? 'AUCUN'));
            $graviteValide = in_array($graviteBrut, self::GRAVITE_VALID) ? $graviteBrut : 'AUCUN';

            return [
                'mots_cles'          => [],
                'gravite'            => $graviteValide,
                'description_courte' => (string)($result['description_fr'] ?? $result['description_courte'] ?? ''),
                'description_ar'     => '', // Arabe retiré
                'type_dommage'       => $typeValide,
                'montant_estime_min' => 0,  // Pas d'estimation financière par Gemini
                'montant_estime_max' => 0,
            ];

        } catch (\Exception $e) {
            error_log('[GeminiService] Exception: ' . $e->getMessage());
            return $this->defaultResult('Erreur IA : ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function defaultResult(string $errorMsg = ''): array
    {
        return [
            'error'              => $errorMsg,
            'mots_cles'          => [],
            'gravite'            => 'AUCUN',
            // Ne pas exposer le message d'erreur technique dans la description (le controller gère l'affichage)
            'description_courte' => '',
            'type_dommage'       => 'AUTRE',
            'montant_estime_min' => 0,
            'montant_estime_max' => 0,
        ];
    }
}

