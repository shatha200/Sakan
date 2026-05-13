<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CustomVisionService — Appelle le modèle YOLOv8n local (FastAPI sur ai_model/server.py)
 * pour détecter les tags de dégradation sur les photos de caution.
 *
 * Classes réelles du modèle entraîné (sakan_v2 / best.pt) :
 *   - crack           → fissure
 *   - stairstep_crack → fissure en escalier (structurelle)
 *   - peeling_paint   → peinture écaillée
 *   - mold            → moisissure
 *   - water_seepage   → infiltration d'eau / fuite
 *
 * Si l'URL est vide, le serveur est absent ou timeout → fallback silencieux,
 * l'analyse Gemini continue exactement comme avant.
 */
class CustomVisionService
{
    private HttpClientInterface $client;

    /**
     * Correspondance tag YOLO (classes réelles du modèle) → ENUM type_dommage.
     * Source : résultats d'entraînement YOLOv8n sakan_v2 (best.pt).
     */
    private const TAG_TO_TYPE_DOMMAGE = [
        'crack'           => 'PEINTURE',   // Fissure / craquelure (top perf: mAP50=0.924)
        'stairstep_crack' => 'PEINTURE',   // Fissure en escalier structurelle (mAP50=0.995)
        'peeling_paint'   => 'PEINTURE',   // Peinture écaillée (mAP50=0.15)
        'mold'            => 'PEINTURE',   // Moisissure (mAP50=0.0818)
        'water_seepage'   => 'PLOMBERIE',  // Infiltration d'eau (mAP50=0.168)
    ];

    /**
     * Traduction des tags anglais du modèle en descriptions françaises
     * pour enrichir le prompt Gemini de manière compréhensible.
     */
    private const TAG_FR_LABELS = [
        'crack'           => 'fissure / craquelure',
        'stairstep_crack' => 'fissure structurelle en escalier',
        'peeling_paint'   => 'peinture écaillée / décollée',
        'mold'            => 'moisissure',
        'water_seepage'   => 'infiltration d\'eau / tache d\'humidité',
    ];

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * Analyse une image via le modèle YOLOv8n local.
     *
     * @param string $imageBase64 Image encodée en base64
     * @param string $mimeType    Type MIME de l'image (ex: image/jpeg)
     *
     * @return array<string, mixed>
     */
    public function analyserImage(string $imageBase64, string $mimeType): array
    {
        $apiUrl = $_ENV['CUSTOM_VISION_API_URL'] ?? '';

        // Fallback silencieux si l'URL n'est pas configurée
        if (empty($apiUrl)) {
            return $this->emptyResult();
        }

        try {
            $response = $this->client->request('POST', rtrim($apiUrl, '/') . '/detect', [
                'json'    => [
                    'image'     => $imageBase64,
                    'mime_type' => $mimeType,
                ],
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 8, // timeout suffisant pour l'inférence YOLOv8 (2.2ms inference)
            ]);

            $httpCode = $response->getStatusCode();
            if ($httpCode !== 200) {
                error_log("[CustomVisionService] HTTP {$httpCode} du serveur IA local.");
                return $this->emptyResult();
            }

            $data = $response->toArray();

            // Si le modèle n'était pas chargé côté serveur → fallback silencieux
            if (!($data['model_loaded'] ?? true)) {
                error_log('[CustomVisionService] Modèle non chargé côté serveur Python.');
                return $this->emptyResult();
            }

            $rawDetections = $data['detections'] ?? [];

            // Filtrer: confidence >= 0.40 (abaissé légèrement pour mold/water_seepage
            // dont les métriques d'entraînement sont plus faibles mais qui restent utiles)
            $filtered = array_filter(
                $rawDetections,
                static fn(array $d) => ($d['confidence'] ?? 0) >= 0.40
            );

            // Trier par confidence décroissante
            usort($filtered, static fn(array $a, array $b) => $b['confidence'] <=> $a['confidence']);

            // Réindexer + enrichir avec le label français
            $tags = [];
            foreach ($filtered as $detection) {
                $tagKey   = $detection['tag'] ?? '';
                $tags[] = [
                    'tag'        => $tagKey,
                    'confidence' => $detection['confidence'],
                    'label_fr'   => self::TAG_FR_LABELS[$tagKey] ?? $tagKey,
                ];
            }

            // Trouver le type_dommage le plus probable (premier tag après tri)
            $typeDommageSuggere = 'AUTRE';
            foreach ($tags as $detection) {
                $tagKey = $detection['tag'];
                if (isset(self::TAG_TO_TYPE_DOMMAGE[$tagKey])) {
                    $typeDommageSuggere = self::TAG_TO_TYPE_DOMMAGE[$tagKey];
                    break;
                }
            }

            return [
                'tags'                 => $tags,
                'type_dommage_suggere' => $typeDommageSuggere,
                'model_available'      => true,
            ];

        } catch (\Throwable $e) {
            // Timeout, connexion refusée, ou toute autre erreur → fallback silencieux
            error_log('[CustomVisionService] Fallback silencieux: ' . $e->getMessage());
            return $this->emptyResult();
        }
    }

    /**
     * Formate les tags détectés en français pour le prompt Gemini.
     * Utilise les labels français (ex: "fissure / craquelure (96%)").
     *
     * @param array<array{tag: string, confidence: float, label_fr: string}> $tags
     */
    public function formaterTagsPourPrompt(array $tags): string
    {
        if (empty($tags)) {
            return '';
        }

        $parts = [];
        foreach ($tags as $detection) {
            $label = $detection['label_fr'];
            $pct   = (int) round((float)$detection['confidence'] * 100);
            $parts[] = "{$label} ({$pct}%)";
        }

        return implode(', ', $parts);
    }

    /**
     * Résultat vide retourné quand le service n'est pas disponible.
     *
     * @return array<string, mixed>
     */
    private function emptyResult(): array
    {
        return [
            'tags'                 => [],
            'type_dommage_suggere' => 'AUTRE',
            'model_available'      => false,
        ];
    }
}
