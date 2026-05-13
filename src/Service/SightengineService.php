<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class SightengineService
{
    private string $apiUser;
    private string $apiSecret;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(string $apiUser, string $apiSecret, HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->apiUser = $apiUser;
        $this->apiSecret = $apiSecret;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Checks if the text content is safe (no inappropriate content).
     * Uses Sightengine Text Moderation (profanity, hate speech, etc.)
     */
    public function isTextSafe(string $text): bool
    {
        if (empty(trim($text))) {
            return true;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.sightengine.com/1.0/text/check.json', [
                'query' => [
                    'text' => $text,
                    'lang' => 'fr,en',
                    'mode' => 'ml,rules',
                    'api_user' => $this->apiUser,
                    'api_secret' => $this->apiSecret,
                ]
            ]);

            $result = $response->toArray();

            // 1. Check Rule-based moderation (profanity, etc.)
            if (isset($result['profanity']['matches']) && count($result['profanity']['matches']) > 0) {
                return false; 
            }

            // 2. Check ML-based moderation scores (> 0.5)
            $models = ['sexual', 'discriminatory', 'insulting', 'violent', 'toxic'];
            foreach ($models as $model) {
                if (isset($result[$model]) && $result[$model] > 0.5) {
                    return false;
                }
            }
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Sightengine AI Moderation Error: ' . $e->getMessage());
            return true; // Fail open
        }
    }

    /**
     * Detects if an image is fake or AI-generated using Sightengine (genai model).
     *
     * @param string $imageData Binary image data from DB
     * @return string 'REAL', 'FAKE', or 'ERROR'
     */
    public function detectFakeImage($imageData): string
    {
        if (!$imageData) {
            return 'ERROR';
        }

        try {
            // Modern Symfony HttpClient way to send multipart data
            $response = $this->httpClient->request('POST', 'https://api.sightengine.com/1.0/check.json', [
                'query' => [
                    'models' => 'genai',
                    'api_user' => $this->apiUser,
                    'api_secret' => $this->apiSecret,
                ],
                'body' => [
                    'media' => $imageData
                ],
                'timeout' => 30
            ]);

            $result = $response->toArray();

            if (isset($result['type']['ai_generated'])) {
                $score = $result['type']['ai_generated'];
                return ($score > 0.5) ? 'FAKE' : 'REAL';
            }

            return 'ERROR';
        } catch (\Exception $e) {
            $this->logger->error('Sightengine Image Detection Error: ' . $e->getMessage());
            return 'ERROR';
        }
    }
}

