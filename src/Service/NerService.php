<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class NerService
{
    private \Symfony\Contracts\HttpClient\HttpClientInterface $client;
    private \Psr\Log\LoggerInterface $logger;
    private string $apiUrl = 'http://127.0.0.1:8081/extract';

    public function __construct(HttpClientInterface $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    /** @return array<string, mixed> */
    public function extractEntities(string $text): array
    {
        try {
            $response = $this->client->request('POST', $this->apiUrl, [
                'json' => ['text' => $text],
                'timeout' => 5, // Sécurité : ne pas bloquer le thread PHP
            ]);

            if ($response->getStatusCode() === 200) {
                return $response->toArray();
            }
        } catch (\Exception $e) {
            $this->logger->error("AI NER Error: " . $e->getMessage());
        }

        // Valeurs par défaut en cas d'échec
        return [
            'problem' => 'Erreur analyse',
            'location' => 'N/A'
        ];
    }
}
