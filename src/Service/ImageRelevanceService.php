<?php

namespace App\Service;

class ImageRelevanceService
{
    /** @return array<string, mixed> */
    public function analyzeRelevance(string $imageData, string $type): array
    {
        $geminiKey = $_ENV['GEMINI_RELEVANCE_KEY'] ?? $_ENV['GEMINI_API_KEY'] ?? '';
        if (empty($geminiKey)) {
            return ['relevant' => true, 'reason' => 'Clé API non configurée (fallback)'];
        }

        // Base64 cleanup
        $base64 = base64_encode($imageData);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=" . $geminiKey;

        $prompt = "Analyze if this image is relevant to a customer reclamation of type '$type' (e.g. if type is plumbing, is there a leak or water?). 
        Return JSON format: {\"relevant\": true/false, \"reason\": \"Brief explanation in French (max 15 words)\"}";

        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data' => $base64
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json'
            ]
        ];

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)(json_encode($body) ?: '{}'));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            
            $result = curl_exec($ch);
            curl_close($ch);

            if (!$result) return ['relevant' => true, 'reason' => 'Erreur de connexion'];

            $data = json_decode(is_string($result) ? $result : '', true);
            $jsonString = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            if (empty($jsonString)) return ['relevant' => true, 'reason' => 'Pas de réponse'];

            $resultData = json_decode($jsonString, true);
            return [
                'relevant' => $resultData['relevant'] ?? true,
                'reason' => $resultData['reason'] ?? 'Analyse terminée'
            ];
        } catch (\Exception $e) {
            return ['relevant' => true, 'reason' => 'Erreur: ' . $e->getMessage()];
        }
    }
}
