<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Système Avancé - Détection des Jours Fériés (Smart AI API)
 * Interroge l'API mondiale Nager.Date (Sans API Key) pour prévenir les conflits de disponibilité.
 */
class HolidayService
{
    private const API_URL = 'https://date.nager.at/api/v3/PublicHolidays';
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Vérifie si la date donnée est un jour férié en Tunisie (ou autre pays configuré).
     * @param \DateTimeInterface $date
     * @param string $countryCode (Par défaut 'TN' pour Tunisie)
     * @return string|null Retourne le nom du jour férié si trouvé, null sinon.
     */
    public function checkPublicHoliday(\DateTimeInterface $date, string $countryCode = 'TN'): ?string
    {
        $year = $date->format('Y');
        $dateStr = $date->format('Y-m-d');
        
        // Système de cache en mémoire ultra-simple
        static $cache = [];
        $cacheKey = $year . '_' . $countryCode;

        try {
            if (!isset($cache[$cacheKey])) {
                // Appel API HTTP Externe (Composant Avancé)
                $response = $this->httpClient->request('GET', self::API_URL . "/{$year}/{$countryCode}", [
                    'timeout' => 3 // Timeout court pour ne jamais bloquer l'UX
                ]);

                if ($response->getStatusCode() === 200) {
                    $cache[$cacheKey] = $response->toArray();
                } else {
                    $cache[$cacheKey] = []; // Fallback vide en cas d'erreur
                }
            }

            // Vérification algorithmique dans le dataset de l'année
            foreach ($cache[$cacheKey] as $holiday) {
                if ($holiday['date'] === $dateStr) {
                    return $holiday['localName'] ?? $holiday['name'];
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("HolidayService API Error: " . $e->getMessage());
        }

        return null;
    }
}
