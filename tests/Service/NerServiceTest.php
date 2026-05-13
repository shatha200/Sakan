<?php

namespace App\Tests\Service;

use App\Service\NerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class NerServiceTest extends TestCase
{
    private $httpClient;
    private $logger;
    private $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new NerService($this->httpClient, $this->logger);
    }

    public function testExtractEntitiesSuccess(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn([
            'problem' => 'fuite d\'eau',
            'location' => 'cuisine'
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', 'http://127.0.0.1:8081/extract', $this->callback(function($options) {
                return $options['json']['text'] === 'J\'ai une fuite d\'eau dans ma cuisine';
            }))
            ->willReturn($mockResponse);

        $result = $this->service->extractEntities('J\'ai une fuite d\'eau dans ma cuisine');

        $this->assertEquals('fuite d\'eau', $result['problem']);
        $this->assertEquals('cuisine', $result['location']);
    }

    public function testExtractEntitiesApiFailure(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(500);

        $this->httpClient->method('request')->willReturn($mockResponse);
        
        // En cas d'erreur status != 200, il retourne les valeurs par défaut
        $result = $this->service->extractEntities('Texte quelconque');

        $this->assertEquals('Erreur analyse', $result['problem']);
        $this->assertEquals('N/A', $result['location']);
    }

    public function testExtractEntitiesException(): void
    {
        $this->httpClient->method('request')
            ->willThrowException(new \Exception('Connection refused'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('AI NER Error: Connection refused'));

        $result = $this->service->extractEntities('Texte quelconque');

        $this->assertEquals('Erreur analyse', $result['problem']);
        $this->assertEquals('N/A', $result['location']);
    }
}
