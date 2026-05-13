<?php

namespace App\Tests\Service;

use App\Service\SightengineService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SightengineServiceTest extends TestCase
{
    private $httpClient;
    private $logger;
    private $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new SightengineService('user', 'secret', $this->httpClient, $this->logger);
    }

    public function testIsTextSafeTrue(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'status' => 'success',
            'profanity' => ['matches' => []],
            'sexual' => 0.01,
            'insulting' => 0.05
        ]);

        $this->httpClient->method('request')->willReturn($mockResponse);

        $this->assertTrue($this->service->isTextSafe('Hello world'));
    }

    public function testIsTextSafeFalseByProfanity(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'status' => 'success',
            'profanity' => ['matches' => [['type' => 'profanity']]]
        ]);

        $this->httpClient->method('request')->willReturn($mockResponse);

        $this->assertFalse($this->service->isTextSafe('Bad word'));
    }

    public function testIsTextSafeFalseByScore(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'status' => 'success',
            'profanity' => ['matches' => []],
            'insulting' => 0.8
        ]);

        $this->httpClient->method('request')->willReturn($mockResponse);

        $this->assertFalse($this->service->isTextSafe('Inappropriate content'));
    }

    public function testDetectFakeImageReal(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'status' => 'success',
            'type' => ['ai_generated' => 0.1]
        ]);

        $this->httpClient->method('request')->willReturn($mockResponse);

        $this->assertEquals('REAL', $this->service->detectFakeImage('binary_data'));
    }

    public function testDetectFakeImageFake(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'status' => 'success',
            'type' => ['ai_generated' => 0.9]
        ]);

        $this->httpClient->method('request')->willReturn($mockResponse);

        $this->assertEquals('FAKE', $this->service->detectFakeImage('binary_data'));
    }

    public function testDetectFakeImageError(): void
    {
        $this->httpClient->method('request')->willThrowException(new \Exception('API Error'));

        $this->assertEquals('ERROR', $this->service->detectFakeImage('binary_data'));
    }
}
