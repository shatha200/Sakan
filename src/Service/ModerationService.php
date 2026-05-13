<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ModerationService
{
    /**
     * French insults & profanity — API Ninjas is English-centric and misses these.
     * Stems are matched with word boundaries, so "idiot" matches "idiots", "idiote".
     * Keep entries lowercase, unaccented — the checker normalizes input before comparison.
     */
    private const FR_BLACKLIST = [
        // Insultes courantes
        'idiot', 'idiote', 'crétin', 'cretin', 'cretine', 'crétine',
        'débile', 'debile', 'abruti', 'abrutie', 'imbécile', 'imbecile',
        'con', 'conne', 'connard', 'connasse', 'conard',
        'salaud', 'salop', 'salope', 'salopard',
        'pute', 'putain', 'putin',
        'merde', 'merdique', 'merdeux', 'merdeuse',
        'enculé', 'encule', 'enculée', 'enculee', 'enculer',
        'bâtard', 'batard', 'bâtarde', 'batarde',
        'tapette', 'tarlouze', 'tapete',
        'chiant', 'chiante', 'chier', 'chieur', 'chieuse',
        'couillon', 'couillonne',
        'bougnoule', 'négro', 'negro',
        // Abréviations SMS
        'fdp', 'ntm', 'tg',
        // Expressions composées (détectées en sous-chaîne)
        'ferme ta gueule', 'ta gueule', 'va te faire', 'nique ta',
        'fils de pute', 'fils de chien',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey
    ) {}

    public function containsProfanity(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        // 1) Local French blacklist (fast, offline)
        if ($this->containsFrenchProfanity($text)) {
            return true;
        }

        // 2) API Ninjas fallback for English profanity
        try {
            $response = $this->httpClient->request('GET',
                'https://api.api-ninjas.com/v1/profanityfilter',
                [
                    'headers' => [
                        'X-Api-Key' => $this->apiKey,
                    ],
                    'query' => [
                        'text' => $text,
                    ],
                    'timeout' => 5,
                ]
            );

            $data = $response->toArray();
            return (bool) ($data['has_profanity'] ?? false);
        } catch (\Throwable) {
            // API down / quota reached → fail-open, keep avis visible
            return false;
        }
    }

    private function containsFrenchProfanity(string $text): bool
    {
        // Normalize: lowercase, strip accents
        $normalized = mb_strtolower($text, 'UTF-8');
        $normalized = strtr($normalized,
            'àâäãåáçèéêëìíîïñòóôöõùúûüýÿ',
            'aaaaaaceeeeiiiinooooouuuuyy'
        );

        foreach (self::FR_BLACKLIST as $word) {
            $needle = strtr(mb_strtolower($word, 'UTF-8'),
                'àâäãåáçèéêëìíîïñòóôöõùúûüýÿ',
                'aaaaaaceeeeiiiinooooouuuuyy'
            );

            // Multi-word expressions → simple substring match
            if (str_contains($needle, ' ')) {
                if (str_contains($normalized, $needle)) {
                    return true;
                }
                continue;
            }

            // Single word → word-boundary regex (handles plurals, punctuation around)
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
