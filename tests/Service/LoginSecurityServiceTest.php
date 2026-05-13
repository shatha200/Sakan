<?php

namespace App\Tests\Service;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\LoginSecurityService;
use App\Service\SecurityNotificationService;
use App\Service\UserSecurityStateService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LoginSecurityServiceTest extends TestCase
{
    public function testMessageBlocageContientNombreTentativesEtEmailSupport(): void
    {
        $service = $this->createService();

        $message = $service->getLockMessage();

        $this->assertStringContainsString((string) LoginSecurityService::MAX_FAILED_ATTEMPTS, $message);
        $this->assertStringContainsString(LoginSecurityService::SUPPORT_EMAIL, $message);
    }

    public function testConnexionReussieEnregistreTentativeEtEnvoieNotification(): void
    {
        $user = $this->createUser(10, ' USER@Example.com ');
        $insertParams = null;

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$insertParams): int {
                if (str_contains($sql, 'INSERT INTO `auth_login_attempt`')) {
                    $insertParams = $params;
                }

                return 1;
            });
        $connection
            ->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('101');

        $stateService = $this->createMock(UserSecurityStateService::class);
        $stateService
            ->expects($this->once())
            ->method('noteSuccessfulLogin')
            ->with($user, ' 127.0.0.1 ');

        $notificationService = $this->createMock(SecurityNotificationService::class);
        $notificationService
            ->expects($this->once())
            ->method('sendLoginNotice')
            ->with($user, ' 127.0.0.1 ');

        $service = $this->createService(
            connection: $connection,
            stateService: $stateService,
            notificationService: $notificationService,
        );

        $service->registerSuccessfulLogin($user, ' 127.0.0.1 ');

        $this->assertSame('user@example.com', $insertParams['identifier'] ?? null);
        $this->assertSame(10, $insertParams['user_id'] ?? null);
        $this->assertSame(1, $insertParams['success'] ?? null);
        $this->assertSame('login_success', $insertParams['reason'] ?? null);
        $this->assertSame('127.0.0.1', $insertParams['ip_address'] ?? null);
    }

    public function testConnexionReussieNeFaitRienSiEmailVide(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->never())
            ->method('executeStatement');

        $stateService = $this->createMock(UserSecurityStateService::class);
        $stateService
            ->expects($this->never())
            ->method('noteSuccessfulLogin');

        $notificationService = $this->createMock(SecurityNotificationService::class);
        $notificationService
            ->expects($this->never())
            ->method('sendLoginNotice');

        $service = $this->createService(
            connection: $connection,
            stateService: $stateService,
            notificationService: $notificationService,
        );

        $service->registerSuccessfulLogin($this->createUser(11, '   '), '127.0.0.1');
    }

    public function testTentativeEchoueeRetourneFalseSiIdentifiantVide(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->never())
            ->method('executeStatement');

        $service = $this->createService(connection: $connection);

        $this->assertFalse($service->registerFailedAttempt('   ', '127.0.0.1'));
    }

    public function testTentativeEchoueeUtilisateurInconnuEnregistreUnknownUser(): void
    {
        $insertParams = null;

        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('missing@example.com')
            ->willReturn(null);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$insertParams): int {
                if (str_contains($sql, 'INSERT INTO `auth_login_attempt`')) {
                    $insertParams = $params;
                }

                return 1;
            });
        $connection
            ->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('102');

        $service = $this->createService(connection: $connection, repository: $repository);

        $this->assertFalse($service->registerFailedAttempt(' Missing@Example.com ', ' 192.168.1.1 '));
        $this->assertSame('missing@example.com', $insertParams['identifier'] ?? null);
        $this->assertNull($insertParams['user_id'] ?? null);
        $this->assertSame(0, $insertParams['success'] ?? null);
        $this->assertSame('unknown_user', $insertParams['reason'] ?? null);
        $this->assertSame('192.168.1.1', $insertParams['ip_address'] ?? null);
    }

    public function testTentativeEchoueeEnvoieAvertissementApresDeuxEchecs(): void
    {
        $user = $this->createUser(20, 'test@example.com');

        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('test@example.com')
            ->willReturn($user);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturn(1);
        $connection
            ->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('103');
        $connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['success' => 0],
                ['success' => 0],
            ]);

        $stateService = $this->createMock(UserSecurityStateService::class);
        $stateService
            ->expects($this->once())
            ->method('hasFailedWarningBeenSent')
            ->with($user)
            ->willReturn(false);
        $stateService
            ->expects($this->once())
            ->method('noteFailedWarningSent')
            ->with($user);

        $notificationService = $this->createMock(SecurityNotificationService::class);
        $notificationService
            ->expects($this->once())
            ->method('sendFailedAttemptsWarning')
            ->with($user, 2);
        $notificationService
            ->expects($this->never())
            ->method('sendAccountSuspended');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->never())
            ->method('flush');

        $service = $this->createService(
            connection: $connection,
            repository: $repository,
            entityManager: $entityManager,
            stateService: $stateService,
            notificationService: $notificationService,
        );

        $this->assertFalse($service->registerFailedAttempt('test@example.com', '127.0.0.1'));
    }

    public function testTentativeEchoueeSuspendCompteApresCinqEchecs(): void
    {
        $user = $this->createUser(30, 'test@example.com');
        $lockUpdateCalled = false;

        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('test@example.com')
            ->willReturn($user);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$lockUpdateCalled): int {
                if (str_contains($sql, 'SET `lock_triggered` = 1')) {
                    $lockUpdateCalled = true;
                }

                return 1;
            });
        $connection
            ->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('104');
        $connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['success' => 0],
                ['success' => 0],
                ['success' => 0],
                ['success' => 0],
                ['success' => 0],
            ]);

        $stateService = $this->createMock(UserSecurityStateService::class);
        $stateService
            ->expects($this->once())
            ->method('hasFailedWarningBeenSent')
            ->with($user)
            ->willReturn(true);
        $stateService
            ->expects($this->never())
            ->method('noteFailedWarningSent');

        $notificationService = $this->createMock(SecurityNotificationService::class);
        $notificationService
            ->expects($this->never())
            ->method('sendFailedAttemptsWarning');
        $notificationService
            ->expects($this->once())
            ->method('sendAccountSuspended')
            ->with($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('flush');

        $service = $this->createService(
            connection: $connection,
            repository: $repository,
            entityManager: $entityManager,
            stateService: $stateService,
            notificationService: $notificationService,
        );

        $this->assertTrue($service->registerFailedAttempt('test@example.com', '127.0.0.1'));
        $this->assertSame('SUSPENDU', $user->getStatut());
        $this->assertTrue($lockUpdateCalled);
    }

    public function testTentativeEchoueeNeSuspendPasUtilisateurDejaSuspendu(): void
    {
        $user = $this->createUser(40, 'test@example.com', 'SUSPENDU');

        $repository = $this->createMock(UtilisateurRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneByIdentifier')
            ->with('test@example.com')
            ->willReturn($user);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturn(1);
        $connection
            ->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('105');
        $connection
            ->expects($this->never())
            ->method('fetchAllAssociative');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->never())
            ->method('flush');

        $notificationService = $this->createMock(SecurityNotificationService::class);
        $notificationService
            ->expects($this->never())
            ->method('sendAccountSuspended');

        $service = $this->createService(
            connection: $connection,
            repository: $repository,
            entityManager: $entityManager,
            notificationService: $notificationService,
        );

        $this->assertFalse($service->registerFailedAttempt('test@example.com', '127.0.0.1'));
        $this->assertSame('SUSPENDU', $user->getStatut());
    }

    private function createService(
        ?Connection $connection = null,
        ?UtilisateurRepository $repository = null,
        ?EntityManagerInterface $entityManager = null,
        ?UserSecurityStateService $stateService = null,
        ?SecurityNotificationService $notificationService = null,
    ): LoginSecurityService {
        return new LoginSecurityService(
            $connection ?? $this->createMock(Connection::class),
            $repository ?? $this->createMock(UtilisateurRepository::class),
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $stateService ?? $this->createMock(UserSecurityStateService::class),
            $notificationService ?? $this->createMock(SecurityNotificationService::class),
        );
    }

    private function createUser(int $id, string $email, ?string $statut = null): Utilisateur
    {
        return (new Utilisateur())
            ->setId($id)
            ->setEmail($email)
            ->setStatut($statut);
    }
}
