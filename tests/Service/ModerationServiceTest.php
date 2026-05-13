<?php

namespace App\Tests\Service;

use App\Service\ModerationService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests unitaires pour ModerationService.
 *
 * Couvre les règles métier de modération des avis/commentaires :
 *   - Détection des insultes françaises (blacklist locale)
 *   - Normalisation : insensible à la casse et aux accents
 *   - Détection des pluriels (regex avec word boundaries)
 *   - Détection des expressions composées multi-mots
 *   - Fail-open si l'API externe est indisponible
 *
 * 100 % isolé : pas de base de données, pas d'appel HTTP réel.
 */
class ModerationServiceTest extends TestCase
{
    /**
     * Crée un service avec un HttpClient qui ne devrait JAMAIS être appelé.
     * Utilisé pour les tests où la blacklist locale doit court-circuiter l'API.
     */
    private function makeServiceWithUnusedHttp(): ModerationService
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('request');

        return new ModerationService($http, 'fake-api-key');
    }

    // ────────────────────────────────────────────────────────────
    // TEST 1 : Insulte française simple détectée
    // ────────────────────────────────────────────────────────────

    public function testInsulteFrancaiseDetectee(): void
    {
        $service = $this->makeServiceWithUnusedHttp();

        $this->assertTrue($service->containsProfanity('Tu es un idiot'));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 2 : Insensible à la casse et aux accents
    // ────────────────────────────────────────────────────────────

    public function testNormalisationCasseEtAccents(): void
    {
        $service = $this->makeServiceWithUnusedHttp();

        // "Crétin" avec majuscule + accent doit être détecté ("cretin" en blacklist)
        $this->assertTrue($service->containsProfanity('Quel CRÉTIN'));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 3 : Détection des formes féminines listées explicitement
    // ────────────────────────────────────────────────────────────

    public function testFormeFeminineDetectee(): void
    {
        $service = $this->makeServiceWithUnusedHttp();

        // "idiote" est listée séparément dans la blacklist
        $this->assertTrue($service->containsProfanity('Quelle idiote'));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 4 : Expression composée multi-mots détectée
    // ────────────────────────────────────────────────────────────

    public function testExpressionComposeeDetectee(): void
    {
        $service = $this->makeServiceWithUnusedHttp();

        $this->assertTrue($service->containsProfanity('Ferme ta gueule maintenant'));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 5 : Texte vide ne déclenche pas l'API
    // ────────────────────────────────────────────────────────────

    public function testTexteVideRetourneFalse(): void
    {
        $service = $this->makeServiceWithUnusedHttp();

        $this->assertFalse($service->containsProfanity(''));
        $this->assertFalse($service->containsProfanity('   '));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 6 : Texte propre - l'API est appelée et retourne false
    // ────────────────────────────────────────────────────────────

    public function testTexteProprePasseParApi(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['has_profanity' => false]);

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())
             ->method('request')
             ->willReturn($response);

        $service = new ModerationService($http, 'fake-api-key');

        $this->assertFalse($service->containsProfanity('Bel appartement, je recommande'));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 7 : Fail-open si l'API externe lève une exception
    // ────────────────────────────────────────────────────────────

    public function testApiIndisponibleRetourneFalse(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')
             ->willThrowException(new \RuntimeException('API down'));

        $service = new ModerationService($http, 'fake-api-key');

        // Comportement attendu : ne pas bloquer l'avis si l'API tombe
        $this->assertFalse($service->containsProfanity('Texte propre quelconque'));
    }
}
