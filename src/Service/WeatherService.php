<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service Météo Intelligent (Porté de JavaFX).
 * Récupère les prévisions météo pour les visites ou utilise un fallback saisonnier.
 */
class WeatherService
{
    private const BASE_URL = 'https://api.openweathermap.org/data/2.5/forecast';
    private string $apiKey;

    public function __construct(
        private HttpClientInterface $httpClient,
        ?string $apiKey = null
    ) {
        // On récupère la clé depuis le paramètre injecté (via services.yaml ou .env)
        $this->apiKey = $apiKey ?: '8970e283f60579e0066144e54a5c0a3d'; // Clé par défaut "demo"
    }

    /**
     * Récupère la météo prévue pour une date et une ville.
     * @return array<string, mixed>
     */
    public function getWeatherForDate(\DateTimeInterface $date, string $ville = 'Tunis'): array
    {
        try {
            // OpenWeather api /forecast only handles 5 days. Skip if further.
            $now = new \DateTime();
            $diffSeconds = $date->getTimestamp() - $now->getTimestamp();
            if ($diffSeconds > 5 * 86400 || $diffSeconds < 0) {
                return $this->getFallbackWeather($date);
            }

            $response = $this->httpClient->request('GET', self::BASE_URL, [
                'query' => [
                    'q' => $ville . ',TN',
                    'appid' => $this->apiKey,
                    'units' => 'metric',
                    'lang' => 'fr',
                    'cnt' => 40
                ],
                'timeout' => 0.5
            ]);

            if ($response->getStatusCode() !== 200) {
                return $this->getFallbackWeather($date);
            }

            $data = $response->toArray();
            $targetDate = $date->format('Y-m-d H');

            foreach ($data['list'] as $entry) {
                if (str_starts_with($entry['dt_txt'], substr($targetDate, 0, 13))) {
                    $temp = round($entry['main']['temp']);
                    $weather = $entry['weather'][0];
                    return [
                        'icon' => $this->mapWeatherIcon($weather['icon']),
                        'temp' => $temp . '°C',
                        'desc' => ucfirst($weather['description'])
                    ];
                }
            }

            return $this->getFallbackWeather($date);
        } catch (\Exception $e) {
            return $this->getFallbackWeather($date);
        }
    }

    /**
     * Météo simulée intelligente basée sur le mois (Fallback Java logic).
     * @return array<string, mixed>
     */
    private function getFallbackWeather(\DateTimeInterface $date): array
    {
        $month = (int) $date->format('n');
        
        // Pseudo-random factor based on the exact date so different visits have different fallback weather
        $hash = md5($date->format('Y-m-d'));
        $variance = (hexdec(substr($hash, 0, 2)) % 8) - 4; // -4 to +3 degrees variance
        $cloudType = hexdec(substr($hash, 2, 1)) % 3; // 0, 1, 2
        
        if ($month >= 6 && $month <= 8) {
            $baseTemp = 32 + $variance;
            $icons = ['☀️', '🌤', '☀️'];
            return ['icon' => $icons[$cloudType], 'temp' => $baseTemp.'°C', 'desc' => 'Ensoleillé (Saisonnier)'];
        }
        if ($month >= 3 && $month <= 5) {
            $baseTemp = 22 + $variance;
            $icons = ['🌤', '🌥', '☀️'];
            $descs = ['Partiellement nuageux', 'Nuageux', 'Dégagé'];
            return ['icon' => $icons[$cloudType], 'temp' => $baseTemp.'°C', 'desc' => $descs[$cloudType] . ' (Saisonnier)'];
        }
        if ($month >= 9 && $month <= 11) {
            $baseTemp = 18 + $variance;
            $icons = ['🌥', '☁️', '🌧'];
            $descs = ['Nuageux', 'Très nuageux', 'Averses éparses'];
            return ['icon' => $icons[$cloudType], 'temp' => $baseTemp.'°C', 'desc' => $descs[$cloudType] . ' (Saisonnier)'];
        }
        
        $baseTemp = 12 + $variance;
        $icons = ['🌧', '☁️', '🌥'];
        $descs = ['Pluvieux', 'Couvert', 'Éclaircies'];
        return ['icon' => $icons[$cloudType], 'temp' => $baseTemp.'°C', 'desc' => $descs[$cloudType] . ' (Saisonnier)'];
    }

    private function mapWeatherIcon(string $iconCode): string
    {
        $code = substr($iconCode, 0, 2);
        return match($code) {
            '01' => '☀️',
            '02' => '🌤',
            '03', '04' => '☁️',
            '09', '10' => '🌧',
            '11' => '⛈',
            '13' => '🌨',
            '50' => '🌫',
            default => '🌡',
        };
    }
}
