<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PhoneVerificationService
{
    private const VERIFY_API_BASE_URL = 'https://verify.twilio.com/v2/Services/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly string $verifyServiceSid,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->accountSid !== ''
            && $this->authToken !== ''
            && $this->verifyServiceSid !== '';
    }

    public function startVerification(string $phoneNumber): void
    {
        $this->assertConfigured();

        $response = $this->httpClient->request('POST', $this->buildUrl('Verifications'), [
            'auth_basic' => [$this->accountSid, $this->authToken],
            'body' => [
                'To' => trim($phoneNumber),
                'Channel' => 'sms',
            ],
        ]);

        $payload = $response->toArray(false);
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400 || ($payload['status'] ?? null) !== 'pending') {
            throw new \RuntimeException($this->extractErrorMessage($payload, 'Impossible d\'envoyer le code de vérification SMS pour le moment.'));
        }
    }

    public function checkVerification(string $phoneNumber, string $code): bool
    {
        $this->assertConfigured();

        $sanitizedCode = preg_replace('/\D/', '', trim($code));
        if ($sanitizedCode === '') {
            return false;
        }

        $response = $this->httpClient->request('POST', $this->buildUrl('VerificationCheck'), [
            'auth_basic' => [$this->accountSid, $this->authToken],
            'body' => [
                'To' => trim($phoneNumber),
                'Code' => $sanitizedCode,
            ],
        ]);

        $payload = $response->toArray(false);
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 500) {
            throw new \RuntimeException($this->extractErrorMessage($payload, 'Le service de vérification téléphone est indisponible pour le moment.'));
        }

        if ($statusCode >= 400) {
            return false;
        }

        return ($payload['status'] ?? null) === 'approved';
    }

    private function assertConfigured(): void
    {
        if ($this->isConfigured()) {
            return;
        }

        throw new \RuntimeException('La vérification téléphone n\'est pas configurée pour le moment.');
    }

    private function buildUrl(string $suffix): string
    {
        return self::VERIFY_API_BASE_URL . rawurlencode($this->verifyServiceSid) . '/' . $suffix;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractErrorMessage(array $payload, string $fallback): string
    {
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return $fallback;
        }

        return $message;
    }
}
