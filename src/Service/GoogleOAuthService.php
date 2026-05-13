<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleOAuthService
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** @var array{client_id:string,client_secret:string,redirect_uri:string}|null */
    private ?array $resolvedConfig = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(string:GOOGLE_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(string:GOOGLE_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
        #[Autowire('%env(string:GOOGLE_REDIRECT_URI)%')]
        private readonly string $redirectUri,
    ) {
    }

    public function buildAuthorizationUrl(Request $request): string
    {
        $config = $this->getConfig();

        $state = $this->randomUrlSafeToken(32);
        $codeVerifier = $this->randomUrlSafeToken(64);
        $codeChallenge = $this->codeChallengeS256($codeVerifier);

        $session = $request->getSession();
        $session->set('google_oauth_state', $state);
        $session->set('google_oauth_code_verifier', $codeVerifier);

        $query = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTH_ENDPOINT . '?' . $query;
    }

    /**
     * @return array{sub:string,email:string,name:string,email_verified:bool}
     */
    public function fetchUserProfile(Request $request): array
    {
        $session = $request->getSession();

        $error = trim((string) $request->query->get('error', ''));
        if ($error !== '') {
            throw new \RuntimeException('Connexion Google annulee ou refusee: ' . $error);
        }

        $state = trim((string) $request->query->get('state', ''));
        $expectedState = (string) $session->get('google_oauth_state', '');
        if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            $session->remove('google_oauth_state');
            $session->remove('google_oauth_code_verifier');
            throw new \RuntimeException('Etat OAuth Google invalide. Reessayez.');
        }

        $code = trim((string) $request->query->get('code', ''));
        if ($code === '') {
            $session->remove('google_oauth_state');
            $session->remove('google_oauth_code_verifier');
            throw new \RuntimeException('Code OAuth Google manquant.');
        }

        $codeVerifier = (string) $session->get('google_oauth_code_verifier', '');
        $session->remove('google_oauth_state');
        $session->remove('google_oauth_code_verifier');

        $accessToken = $this->exchangeCodeForAccessToken($code, $codeVerifier);
        $payload = $this->fetchGoogleProfile($accessToken);

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $sub = trim((string) ($payload['sub'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $emailVerified = (bool) ($payload['email_verified'] ?? false);

        if ($email === '' || $sub === '') {
            throw new \RuntimeException('Profil Google incomplet (email/sub manquant).');
        }

        return [
            'sub' => $sub,
            'email' => $email,
            'name' => $name,
            'email_verified' => $emailVerified,
        ];
    }

    private function exchangeCodeForAccessToken(string $code, string $codeVerifier): string
    {
        $config = $this->getConfig();

        $body = [
            'code' => $code,
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'grant_type' => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ];

        $secret = $config['client_secret'];
        if ($secret !== '') {
            $body['client_secret'] = $secret;
        }

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_ENDPOINT, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $body,
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (\Throwable) {
            throw new \RuntimeException('Erreur reseau pendant Google OAuth.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $description = trim((string) ($payload['error_description'] ?? $payload['error'] ?? ''));
            throw new \RuntimeException(
                $description !== '' ? 'Echec token Google OAuth: ' . $description : 'Echec token Google OAuth.'
            );
        }

        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('Reponse token Google invalide.');
        }

        return $accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchGoogleProfile(string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', self::USERINFO_ENDPOINT, [
                'auth_bearer' => $accessToken,
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (\Throwable) {
            throw new \RuntimeException('Erreur reseau pendant lecture du profil Google.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Impossible de lire le profil Google.');
        }

        return $payload;
    }

    /**
     * @return array{client_id:string,client_secret:string,redirect_uri:string}
     */
    private function getConfig(): array
    {
        if ($this->resolvedConfig !== null) {
            return $this->resolvedConfig;
        }

        $clientId = trim($this->clientId);
        $clientSecret = trim($this->clientSecret);
        $redirectUri = trim($this->redirectUri);

        $external = $this->readGoogleConfigFromJavaEnv();
        if ($clientId === '' && isset($external['GOOGLE_CLIENT_ID'])) {
            $clientId = trim((string) $external['GOOGLE_CLIENT_ID']);
        }
        if ($clientSecret === '' && isset($external['GOOGLE_CLIENT_SECRET'])) {
            $clientSecret = trim((string) $external['GOOGLE_CLIENT_SECRET']);
        }
        if ($redirectUri === '' && isset($external['GOOGLE_REDIRECT_URI'])) {
            $redirectUri = trim((string) $external['GOOGLE_REDIRECT_URI']);
        }

        if ($clientId === '') {
            throw new \RuntimeException('Configuration Google OAuth manquante (GOOGLE_CLIENT_ID).');
        }

        if ($redirectUri === '') {
            $redirectUri = $this->urlGenerator->generate('app_google_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $this->resolvedConfig = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ];

        return $this->resolvedConfig;
    }

    /**
     * @return array<string, string>
     */
    private function readGoogleConfigFromJavaEnv(): array
    {
        foreach ($this->candidateJavaEnvPaths() as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $parsed = $this->parseDotEnvFile($path);
            if (isset($parsed['GOOGLE_CLIENT_ID']) || isset($parsed['GOOGLE_CLIENT_SECRET'])) {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function candidateJavaEnvPaths(): array
    {
        $paths = [];
        $projectRoot = dirname($this->projectDir);
        $desktopRoot = dirname($projectRoot, 2);

        $paths[] = $desktopRoot . DIRECTORY_SEPARATOR . 'pi dev' . DIRECTORY_SEPARATOR . 'Sakan_pi1' . DIRECTORY_SEPARATOR . '.env';
        $paths[] = $desktopRoot . DIRECTORY_SEPARATOR . 'pi dev' . DIRECTORY_SEPARATOR . 'Sakan_pi1' . DIRECTORY_SEPARATOR . 'Sakan_pi1' . DIRECTORY_SEPARATOR . '.env';

        $paths[] = $projectRoot . DIRECTORY_SEPARATOR . 'Sakan_pi1' . DIRECTORY_SEPARATOR . '.env';
        $paths[] = $projectRoot . DIRECTORY_SEPARATOR . 'Sakan_pi1' . DIRECTORY_SEPARATOR . 'Sakan_pi1' . DIRECTORY_SEPARATOR . '.env';

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string, string>
     */
    private function parseDotEnvFile(string $path): array
    {
        $rows = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rows === false) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            $line = trim((string) $row);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Supports both classic .env lines and "KEY=VALUE;KEY2=VALUE2" single-line format.
            $segments = array_filter(array_map('trim', explode(';', $line)), static fn (string $s): bool => $s !== '');
            foreach ($segments as $segment) {
                if (str_starts_with($segment, '#')) {
                    continue;
                }
                if (str_starts_with($segment, 'export ')) {
                    $segment = trim(substr($segment, 7));
                }

                $separatorPos = strpos($segment, '=');
                if ($separatorPos === false || $separatorPos <= 0) {
                    continue;
                }

                $key = trim(substr($segment, 0, $separatorPos));
                $value = trim(substr($segment, $separatorPos + 1));
                if ($key === '') {
                    continue;
                }

                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    $quote = $value[0];
                    if (str_ends_with($value, $quote)) {
                        $value = substr($value, 1, -1);
                    }
                } else {
                    $commentPos = strpos($value, ' #');
                    if ($commentPos !== false) {
                        $value = trim(substr($value, 0, $commentPos));
                    }
                }

                $values[$key] = trim($value);
            }
        }

        return $values;
    }

    private function randomUrlSafeToken(int $bytesLength): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(1, $bytesLength))), '+/', '-_'), '=');
    }

    private function codeChallengeS256(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
