<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiProService
{
    public function __construct(
        private readonly HttpClientInterface $client
    ) {}

    public function generateDescription(string $type, string $currentText = ''): string
    {
        $prompt = trim($currentText) !== '' 
            ? "Continue this customer complaint about '$type' in a clear and formal way: " . $currentText 
            : "Write a clear and formal customer complaint in French about: " . $type;
            
        return $this->callGemini($prompt);
    }

    public function generateResponse(string $description, string $type, string $currentText = ''): string
    {
        $prompt = trim($currentText) !== '' 
            ? "Complete this customer service response regarding a '$type' complaint politely: " . $currentText 
            : "Write a polite customer service response in French regarding this '$type' complaint: " . $description;
            
        return $this->callGemini($prompt);
    }

    private function callGemini(string $prompt): string
    {
        $apiKey = $_ENV['GEMINI_AUTOCOMPLETE_KEY'] ?? $_ENV['GEMINI_API_KEY'] ?? '';
        
        if (empty($apiKey)) {
            return "";
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=" . $apiKey;
        
        $body = [
            'contents' => [['parts' => [['text' => "Continue this formal French text naturally: $prompt. Output ONLY the continuation text, no preamble. Keep it short and concise (maximum 15 words)."]]]]
        ];
        
        try {
            $response = $this->client->request('POST', $url, [
                'json' => $body,
                'timeout' => 15,
                'verify_peer' => false,
            ]);

            $data = $response->toArray();
            return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
        } catch (\Throwable) {
            return "";
        }
    }
}
