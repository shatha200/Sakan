<?php

namespace App\Tests\Service;

use App\Service\GoogleOAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GoogleOAuthServiceTest extends TestCase
{
    public function testGenereUrlAutorisationGoogleCorrecte(): void
    {
        $request = $this->createRequestWithSession();
        $service = $this->createService();

        $url = $service->buildAuthorizationUrl($request);
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);

        $this->assertSame('https', $parts['scheme'] ?? null);
        $this->assertSame('accounts.google.com', $parts['host'] ?? null);
        $this->assertSame('/o/oauth2/v2/auth', $parts['path'] ?? null);
        $this->assertSame('google-client-id', $query['client_id'] ?? null);
        $this->assertSame('https://app.test/google/callback', $query['redirect_uri'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('openid email profile', $query['scope'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertSame('select_account', $query['prompt'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? null);
    }

    public function testEnregistreStateEtCodeVerifierDansSession(): void
    {
        $request = $this->createRequestWithSession();
        $service = $this->createService();

        $service->buildAuthorizationUrl($request);
        $session = $request->getSession();

        $this->assertNotEmpty($session->get('google_oauth_state'));
        $this->assertNotEmpty($session->get('google_oauth_code_verifier'));
    }

    public function testLanceExceptionSiClientIdManquant(): void
    {
        $service = $this->createService(clientId: '', projectDir: sys_get_temp_dir() . '/sakan-google-oauth-test-' . uniqid());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GOOGLE_CLIENT_ID');

        $service->buildAuthorizationUrl($this->createRequestWithSession());
    }

    public function testRefuseCallbackAvecErreurGoogle(): void
    {
        $request = $this->createRequestWithSession(['error' => 'access_denied']);
        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connexion Google annulee ou refusee');

        $service->fetchUserProfile($request);
    }

    public function testRefuseCallbackAvecStateInvalideEtNettoieSession(): void
    {
        $request = $this->createRequestWithSession(['state' => 'bad-state', 'code' => 'auth-code']);
        $session = $request->getSession();
        $session->set('google_oauth_state', 'expected-state');
        $session->set('google_oauth_code_verifier', 'code-verifier');

        $service = $this->createService();

        try {
            $service->fetchUserProfile($request);
            $this->fail('Une exception etait attendue.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Etat OAuth Google invalide', $exception->getMessage());
        }

        $this->assertFalse($session->has('google_oauth_state'));
        $this->assertFalse($session->has('google_oauth_code_verifier'));
    }

    public function testRefuseCallbackSansCodeEtNettoieSession(): void
    {
        $request = $this->createRequestWithSession(['state' => 'valid-state']);
        $session = $request->getSession();
        $session->set('google_oauth_state', 'valid-state');
        $session->set('google_oauth_code_verifier', 'code-verifier');

        $service = $this->createService();

        try {
            $service->fetchUserProfile($request);
            $this->fail('Une exception etait attendue.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Code OAuth Google manquant', $exception->getMessage());
        }

        $this->assertFalse($session->has('google_oauth_state'));
        $this->assertFalse($session->has('google_oauth_code_verifier'));
    }

    public function testRecupereProfilGoogleAvecCallbackValide(): void
    {
        $tokenResponse = new MockResponse('{"access_token":"access-token"}');
        $profileResponse = new MockResponse('{"sub":"google-sub","email":"USER@EXAMPLE.COM","name":" Alice ","email_verified":true}');
        $client = new MockHttpClient([$tokenResponse, $profileResponse]);

        $request = $this->createRequestWithSession(['state' => 'valid-state', 'code' => 'auth-code']);
        $session = $request->getSession();
        $session->set('google_oauth_state', 'valid-state');
        $session->set('google_oauth_code_verifier', 'code-verifier');

        $service = $this->createService(httpClient: $client);

        $profile = $service->fetchUserProfile($request);

        $this->assertSame([
            'sub' => 'google-sub',
            'email' => 'user@example.com',
            'name' => 'Alice',
            'email_verified' => true,
        ], $profile);

        $this->assertSame('POST', $tokenResponse->getRequestMethod());
        $this->assertSame('https://oauth2.googleapis.com/token', $tokenResponse->getRequestUrl());
        $this->assertSame('GET', $profileResponse->getRequestMethod());
        $this->assertSame('https://openidconnect.googleapis.com/v1/userinfo', $profileResponse->getRequestUrl());
        $this->assertStringContainsString('Bearer access-token', json_encode($profileResponse->getRequestOptions()));
    }

    public function testRefuseProfilGoogleIncomplet(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"access_token":"access-token"}'),
            new MockResponse('{"sub":"google-sub","name":"Alice","email_verified":true}'),
        ]);

        $request = $this->createRequestWithSession(['state' => 'valid-state', 'code' => 'auth-code']);
        $session = $request->getSession();
        $session->set('google_oauth_state', 'valid-state');
        $session->set('google_oauth_code_verifier', 'code-verifier');

        $service = $this->createService(httpClient: $client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Profil Google incomplet');

        $service->fetchUserProfile($request);
    }

    /**
     * @param array<string, string> $query
     */
    private function createRequestWithSession(array $query = []): Request
    {
        $request = Request::create('/google/callback', 'GET', $query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function createService(
        ?MockHttpClient $httpClient = null,
        string $clientId = 'google-client-id',
        string $clientSecret = 'google-client-secret',
        string $redirectUri = 'https://app.test/google/callback',
        ?string $projectDir = null,
    ): GoogleOAuthService {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('generate')
            ->with('app_google_callback', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://generated.test/google/callback');

        return new GoogleOAuthService(
            $httpClient ?? new MockHttpClient(),
            $urlGenerator,
            $projectDir ?? __DIR__,
            $clientId,
            $clientSecret,
            $redirectUri,
        );
    }
}
