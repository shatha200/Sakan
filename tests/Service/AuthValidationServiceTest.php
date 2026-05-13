<?php

namespace App\Tests\Service;

use App\Service\AuthValidationService;
use PHPUnit\Framework\TestCase;

class AuthValidationServiceTest extends TestCase
{
    public function testEmailValideRetourneTrue(): void
    {
        $service = new AuthValidationService();

        $this->assertTrue($service->isValidEmail('test@example.com'));
        $this->assertTrue($service->isValidEmail('user.name+tag@example.co'));
    }

    public function testEmailInvalideRetourneFalse(): void
    {
        $service = new AuthValidationService();

        $this->assertFalse($service->isValidEmail('test'));
        $this->assertFalse($service->isValidEmail('test@'));
        $this->assertFalse($service->isValidEmail('test@example'));
        $this->assertFalse($service->isValidEmail('@example.com'));
    }

    public function testMotDePasseValideRetourneTrue(): void
    {
        $service = new AuthValidationService();

        $this->assertTrue($service->isValidPassword('Abc123'));
        $this->assertTrue($service->isValidPassword('MotDePasse9'));
    }

    public function testMotDePasseTropCourtRetourneFalse(): void
    {
        $service = new AuthValidationService();

        $this->assertFalse($service->isValidPassword('Ab12'));
    }

    public function testMotDePasseSansMinusculeRetourneFalse(): void
    {
        $service = new AuthValidationService();

        $this->assertFalse($service->isValidPassword('ABC123'));
    }

    public function testMotDePasseSansMajusculeRetourneFalse(): void
    {
        $service = new AuthValidationService();

        $this->assertFalse($service->isValidPassword('abc123'));
    }

    public function testMotDePasseSansChiffreRetourneFalse(): void
    {
        $service = new AuthValidationService();

        $this->assertFalse($service->isValidPassword('Abcdef'));
    }

    public function testTelephoneTunisienValideRetourneTrue(): void
    {
        $service = new AuthValidationService();

        $this->assertTrue($service->isValidTunisiaPhone('20123456'));
        $this->assertTrue($service->isValidTunisiaPhone('99123456'));
    }

    public function testTelephoneTunisienInvalideRetourneFalse(): void
    {
        $service = new AuthValidationService();

        $this->assertFalse($service->isValidTunisiaPhone('10123456'));
        $this->assertFalse($service->isValidTunisiaPhone('2012345'));
        $this->assertFalse($service->isValidTunisiaPhone('201234567'));
        $this->assertFalse($service->isValidTunisiaPhone('20ABC456'));
    }

    public function testConversionTelephoneTunisienEnE164RetourneNumeroAvecIndicatif(): void
    {
        $service = new AuthValidationService();

        $this->assertSame('+21620123456', $service->toTunisiaE164('20123456'));
    }

    public function testConversionTelephoneTunisienInvalideLanceException(): void
    {
        $service = new AuthValidationService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Numero tunisien invalide');

        $service->toTunisiaE164('10123456');
    }
}
