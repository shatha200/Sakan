<?php

namespace App\Service;

/**
 * Service d'Analyse de Sentiment & Scoring d'Avis (Porté de JavaFX).
 * Extraction automatique des points clés et scoring NLP simulé.
 */
class SentimentAnalysisService
{
    private const POSITIVE_WORDS = [
        'super' => 1.0, 'excellent' => 1.0, 'parfait' => 1.0, 'génial' => 1.0,
        'magnifique' => 0.9, 'top' => 0.8, 'bien' => 0.5, 'bon' => 0.5,
        'propre' => 0.7, 'agréable' => 0.7, 'confortable' => 0.8, 'calme' => 0.7,
        'spacieux' => 0.8, 'lumineux' => 0.7, 'moderne' => 0.6, 'proche' => 0.6,
        'pratique' => 0.6, 'recommande' => 0.9, 'merci' => 0.4, 'plaisir' => 0.5
    ];

    private const NEGATIVE_WORDS = [
        'mauvais' => -0.8, 'sale' => -0.9, 'bruyant' => -0.7, 'bruit' => -0.7,
        'lent' => -0.5, 'cher' => -0.4, 'petit' => -0.3, 'vieux' => -0.4,
        'problème' => -0.6, 'panne' => -0.7, 'cassé' => -0.8, 'froid' => -0.5,
        'humide' => -0.6, 'loin' => -0.5, 'nul' => -1.0, 'horrible' => -1.0,
        'déçu' => -0.7, 'décevant' => -0.8
    ];

    private const TOPIC_KEYWORDS = [
        '🛋 Confort' => ['confort', 'lit', 'meublé', 'spacieux', 'équipé'],
        '🔇 Bruit' => ['bruit', 'bruyant', 'calme', 'silencieux', 'voisin'],
        '📶 WiFi' => ['wifi', 'internet', 'connexion', 'lent', 'rapide'],
        '📍 Localisation' => ['proche', 'transport', 'bus', 'métro', 'centre'],
        '🧹 Propreté' => ['propre', 'sale', 'nettoyage', 'hygiène'],
        '💰 Prix' => ['prix', 'cher', 'abordable', 'qualité']
    ];

    public function analyzeSentiment(?string $text): float
    {
        if (empty($text)) return 0.0;

        $lower = mb_strtolower($text);
        // Split on non-word characters
        $words = preg_split('/[\s,;.!?\'"]+/', $lower, -1, PREG_SPLIT_NO_EMPTY);

        $totalScore = 0;
        $matchCount = 0;
        $negate = false;

        foreach (($words ?: []) as $word) {
            if (in_array($word, ['pas', 'ne', 'ni', 'aucun'])) {
                $negate = true;
                continue;
            }

            if (isset(self::POSITIVE_WORDS[$word])) {
                $score = self::POSITIVE_WORDS[$word];
                $totalScore += ($negate ? -$score : $score);
                $matchCount++;
                $negate = false;
            } elseif (isset(self::NEGATIVE_WORDS[$word])) {
                $score = self::NEGATIVE_WORDS[$word];
                $totalScore += ($negate ? -$score : $score);
                $matchCount++;
                $negate = false;
            } else {
                $negate = false;
            }
        }

        if ($matchCount === 0) return 0.2; // Légèrement positif par défaut
        return max(-1.0, min(1.0, $totalScore / $matchCount));
    }

    /** @return array<string, mixed> */
    public function getSentimentBadge(float $score): array
    {
        if ($score >= 0.6)  return ['icon' => '😍', 'label' => 'Très positif', 'color' => '#16a34a'];
        if ($score >= 0.2)  return ['icon' => '😊', 'label' => 'Positif', 'color' => '#65a30d'];
        if ($score >= -0.2) return ['icon' => '😐', 'label' => 'Neutre', 'color' => '#d97706'];
        if ($score >= -0.6) return ['icon' => '😟', 'label' => 'Négatif', 'color' => '#dc2626'];
        return ['icon' => '😠', 'label' => 'Très négatif', 'color' => '#991b1b'];
    }

    /**
     * @param array<string> $avisContents
     * @return array<string, mixed>
     */
    public function extractTopics(array $avisContents): array
    {
        $topicCounts = [];
        foreach ($avisContents as $content) {
            $lower = mb_strtolower($content);
            foreach (self::TOPIC_KEYWORDS as $topic => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($lower, $keyword)) {
                        $topicCounts[$topic] = ($topicCounts[$topic] ?? 0) + 1;
                        break;
                    }
                }
            }
        }
        arsort($topicCounts);
        return $topicCounts;
    }
}
