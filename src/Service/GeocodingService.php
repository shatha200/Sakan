<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Système de Validation Géographique IA (Sans API Key)
 * Utilise Nominatim (OpenStreetMap) pour authentifier l'existence physique des adresses.
 */
class GeocodingService
{
    private const API_URL = 'https://nominatim.openstreetmap.org/search';
    
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger
    ) {}

    /**
     * Vérifie si une adresse existe réellement sur la mappemonde.
     * @param string $adresse
     * @return bool
     */
    public function validateAddress(string $adresse): bool
    {
        if (empty($adresse)) return true; // On ne bloque pas si vide

        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => [
                    'q' => $adresse,
                    'format' => 'json',
                    'limit' => 1
                ],
                'headers' => [
                    'User-Agent' => 'SakanApp/1.0 (Contact: sakan-pidev@esprit.tn)'
                ],
                'timeout' => 5
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                // Si on trouve au moins un résultat avec un score de confiance
                return !empty($data);
            }
        } catch (\Exception $e) {
            $this->logger->warning("Geocoding API unavailable: " . $e->getMessage());
        }

        return true; // Fallback permissif en cas de coupure API pour ne pas bloquer l'UX
    }
}
