<?php

namespace App\Tests\Service;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\AuthOtpService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class AuthOtpServiceTest extends TestCase
{
    public function testCreationOtpMotDePasseRetourneFalseSiEmailVide(): void
    {
        $service = new AuthOtpService(
            $this->createMock(Connection::class),
            $this->createMock(UtilisateurRepository::class),
            $this->createMock(MailerInterface::class),
        );

        $this->assertFalse($service->createPasswordResetOtp(''));
        $this->assertFalse($service->createPasswordResetOtp('   '));
    }

    public function testCreationOtpMotDePasseRetourneFalseSiUtilisateurInexistant(): void
    {
        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('test@example.com')
            ->willReturn(null);

        $service = new AuthOtpService(
            $this->createMock(Connection::class),
            $repository,
            $this->createMock(MailerInterface::class),
        );

        $this->assertFalse($service->createPasswordResetOtp(' Test@Example.com '));
    }

    public function testCreationOtpMotDePasseEnregistreOtpEtEnvoieEmail(): void
    {
        $user = $this->createUser(10, 'test@example.com');

        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('test@example.com')
            ->willReturn($user);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement');
        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1);
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'password_reset_otp',
                $this->callback(static function (array $data): bool {
                    return $data['user_id'] === 10
                        && password_get_info((string) $data['otp_hash'])['algoName'] === 'bcrypt'
                        && $data['resend_count'] === 0
                        && $data['attempts'] === 0
                        && $data['used'] === 0
                        && is_string($data['expires_at']);
                })
            );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(static function (Email $email): bool {
                return $email->getTo()[0]->getAddress() === 'test@example.com'
                    && str_contains((string) $email->getSubject(), 'Code de reinitialisation')
                    && preg_match('/\d{6}/', (string) $email->getTextBody()) === 1;
            }));

        $service = new AuthOtpService($connection, $repository, $mailer);

        $this->assertTrue($service->createPasswordResetOtp(' Test@Example.com '));
    }

    public function testStatutOtpMotDePasseRetourneStatutVideSiUtilisateurInconnu(): void
    {
        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('missing@example.com')
            ->willReturn(null);

        $service = new AuthOtpService(
            $this->createMock(Connection::class),
            $repository,
            $this->createMock(MailerInterface::class),
        );

        $this->assertSame([
            'expires_at' => null,
            'seconds_remaining' => 0,
            'resend_count' => 0,
            'max_resends' => AuthOtpService::OTP_MAX_RESENDS,
            'can_resend' => false,
        ], $service->getPasswordResetOtpStatus('missing@example.com'));
    }

    public function testRenvoiOtpMotDePasseRetourneFalseQuandLimiteAtteinte(): void
    {
        $user = $this->createUser(20, 'test@example.com');
        $futureExpiry = (new \DateTimeImmutable('+3 minutes'))->format('Y-m-d H:i:s');

        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('test@example.com')
            ->willReturn($user);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('executeStatement');
        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1);
        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 1,
                'resend_count' => AuthOtpService::OTP_MAX_RESENDS,
                'expires_at' => $futureExpiry,
                'used' => 0,
            ]);
        $connection
            ->expects($this->never())
            ->method('insert');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects($this->never())
            ->method('send');

        $service = new AuthOtpService($connection, $repository, $mailer);

        $this->assertFalse($service->resendPasswordResetOtp('test@example.com'));
    }

    public function testVerificationOtpMotDePasseRetourneTrueSiOtpValideEtLeMarqueUtilise(): void
    {
        $user = $this->createUser(30, 'test@example.com');
        $hash = password_hash('123456', PASSWORD_BCRYPT);

        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('test@example.com')
            ->willReturn($user);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement');
        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1);
        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 99,
                'otp_hash' => $hash,
                'attempts' => 0,
                'expires_at' => (new \DateTimeImmutable('+3 minutes'))->format('Y-m-d H:i:s'),
            ]);

        $service = new AuthOtpService(
            $connection,
            $repository,
            $this->createMock(MailerInterface::class),
        );

        $this->assertTrue($service->verifyPasswordResetOtp('test@example.com', '123 456'));
    }

    public function testVerificationOtpMotDePasseRetourneFalseEtIncrementeTentativesSiOtpIncorrect(): void
    {
        $user = $this->createUser(40, 'test@example.com');
        $hash = password_hash('123456', PASSWORD_BCRYPT);

        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('test@example.com')
            ->willReturn($user);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement');
        $connection
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(1, 1);
        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 100,
                'otp_hash' => $hash,
                'attempts' => 0,
                'expires_at' => (new \DateTimeImmutable('+3 minutes'))->format('Y-m-d H:i:s'),
            ]);

        $service = new AuthOtpService(
            $connection,
            $repository,
            $this->createMock(MailerInterface::class),
        );

        $this->assertFalse($service->verifyPasswordResetOtp('test@example.com', '000000'));
    }

    public function testEnvoiCodeDoubleAuthentificationEnregistreOtpEtEnvoieEmail(): void
    {
        $user = $this->createUser(50, 'twofactor@example.com');

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement');
        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1);
        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'login_2fa_otp',
                $this->callback(static function (array $data): bool {
                    return $data['user_id'] === 50
                        && password_get_info((string) $data['otp_hash'])['algoName'] === 'bcrypt'
                        && $data['used'] === 0;
                })
            );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(static function (Email $email): bool {
                return $email->getTo()[0]->getAddress() === 'twofactor@example.com'
                    && str_contains((string) $email->getSubject(), 'Verification 2FA')
                    && preg_match('/\d{6}/', (string) $email->getTextBody()) === 1;
            }));

        $service = new AuthOtpService(
            $connection,
            $this->createMock(UtilisateurRepository::class),
            $mailer,
        );

        $this->assertTrue($service->sendLoginTwoFactorCode($user));
    }

    public function testVerificationCodeDoubleAuthentificationRefuseFormatOtpInvalide(): void
    {
        $service = new AuthOtpService(
            $this->createMock(Connection::class),
            $this->createMock(UtilisateurRepository::class),
            $this->createMock(MailerInterface::class),
        );

        $this->assertFalse($service->verifyLoginTwoFactorCode($this->createUser(60, 'test@example.com'), '12345'));
    }

    public function testCreationOtpChangementEmailRetourneFalseSiNouvelEmailVide(): void
    {
        $service = new AuthOtpService(
            $this->createMock(Connection::class),
            $this->createMock(UtilisateurRepository::class),
            $this->createMock(MailerInterface::class),
        );

        $this->assertFalse($service->createEmailChangeOtp($this->createUser(70, 'old@example.com'), '   '));
    }

    public function testVerificationOtpChangementEmailNormaliseEmailEtMarqueOtpValideUtilise(): void
    {
        $user = $this->createUser(80, 'old@example.com');
        $hash = password_hash('654321', PASSWORD_BCRYPT);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement');
        $connection
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1);
        $connection
            ->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->stringContains('email_change_otp'),
                [80, 'new@example.com']
            )
            ->willReturn([
                'id' => 101,
                'otp_hash' => $hash,
                'attempts' => 0,
                'expires_at' => (new \DateTimeImmutable('+3 minutes'))->format('Y-m-d H:i:s'),
            ]);

        $service = new AuthOtpService(
            $connection,
            $this->createMock(UtilisateurRepository::class),
            $this->createMock(MailerInterface::class),
        );

        $this->assertTrue($service->verifyEmailChangeOtp($user, ' New@Example.com ', '654321'));
    }

    private function createUser(int $id, string $email): Utilisateur
    {
        return (new Utilisateur())
            ->setId($id)
            ->setEmail($email);
    }
}
