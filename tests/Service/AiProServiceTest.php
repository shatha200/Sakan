<?php

namespace App\Tests\Service;

use App\Service\AiProService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AiProServiceTest extends TestCase
{
    private $httpClient;
    private $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = new AiProService($this->httpClient);
        
        // Mocking $_ENV for the test environment
        $_ENV['GEMINI_API_KEY'] = 'fake-key';
    }

    protected function tearDown(): void
    {
        unset($_ENV['GEMINI_API_KEY']);
        unset($_ENV['GEMINI_AUTOCOMPLETE_KEY']);
    }

    public function testGenerateDescriptionNew(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'Voici une plainte formelle.']]]]
            ]
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', $this->stringContains('generativelanguage.googleapis.com'), $this->callback(function($options) {
                return str_contains($options['json']['contents'][0]['parts'][0]['text'], "Write a clear and formal customer complaint");
            }))
            ->willReturn($mockResponse);

        $result = $this->service->generateDescription('plomberie');

        $this->assertEquals('Voici une plainte formelle.', $result);
    }

    public function testGenerateDescriptionContinue(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'suite de la plainte.']]]]
            ]
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', $this->stringContains('generativelanguage.googleapis.com'), $this->callback(function($options) {
                return str_contains($options['json']['contents'][0]['parts'][0]['text'], "Continue this customer complaint");
            }))
            ->willReturn($mockResponse);

        $result = $this->service->generateDescription('plomberie', 'J\'ai un problème');

        $this->assertEquals('suite de la plainte.', $result);
    }

    public function testGenerateResponse(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'candidates' => [
                ['content' => ['parts' => [['text' => 'Nous sommes désolés pour ce désagrément.']]]]
            ]
        ]);

        $this->httpClient->method('request')->willReturn($mockResponse);

        $result = $this->service->generateResponse('Fuite d\'eau', 'plomberie');

        $this->assertEquals('Nous sommes désolés pour ce désagrément.', $result);
    }

    public function testCallGeminiError(): void
    {
        $this->httpClient->method('request')->willThrowException(new \Exception('API Error'));

        $result = $this->service->generateDescription('test');

        $this->assertEquals('', $result);
    }
}
