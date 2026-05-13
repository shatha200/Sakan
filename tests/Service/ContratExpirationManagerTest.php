<?php

namespace App\Tests\Service;

use App\Entity\Contrat;
use App\Service\ContratExpirationManager;
use PHPUnit\Framework\TestCase;

class ContratExpirationManagerTest extends TestCase
{
    // ✅ TEST 1 : Date null → retourne null
    public function testDaysUntilContractEndDateNull(): void
    {
        $manager = new ContratExpirationManager();
        $today = new \DateTimeImmutable('2025-06-01');

        $result = $manager->daysUntilContractEnd(null, $today);
        $this->assertNull($result);
    }

    // ✅ TEST 2 : Date passée → retourne null
    public function testDaysUntilContractEndDatePassee(): void
    {
        $manager = new ContratExpirationManager();
        $today = new \DateTimeImmutable('2025-06-01');

        $result = $manager->daysUntilContractEnd('2025-01-01', $today);
        $this->assertNull($result);
    }

    // ✅ TEST 3 : Date future → retourne nb jours
    public function testDaysUntilContractEndDateFuture(): void
    {
        $manager = new ContratExpirationManager();
        $today = new \DateTimeImmutable('2025-06-01');

        $result = $manager->daysUntilContractEnd('2025-06-08', $today);
        $this->assertEquals(7, $result);
    }

    // ✅ TEST 4 : Contrat ACTIF expirant dans 5 jours → true
    public function testIsExpiringSoonTrue(): void
    {
        $today = new \DateTimeImmutable();
        $dateFin = $today->modify('+5 days')->format('Y-m-d');

        $contrat = new Contrat();
        $contrat->setStatut('ACTIF');
        $contrat->setDateFin($dateFin);

        $manager = new ContratExpirationManager();
        $this->assertTrue($manager->isExpiringSoon($contrat, 7));
    }

    // ✅ TEST 5 : Contrat ACTIF expirant dans 30 jours → false
    public function testIsExpiringSoonFalseTropLoin(): void
    {
        $today = new \DateTimeImmutable();
        $dateFin = $today->modify('+30 days')->format('Y-m-d');

        $contrat = new Contrat();
        $contrat->setStatut('ACTIF');
        $contrat->setDateFin($dateFin);

        $manager = new ContratExpirationManager();
        $this->assertFalse($manager->isExpiringSoon($contrat, 7));
    }

    // ✅ TEST 6 : Contrat TERMINE → false même si date proche
    public function testIsExpiringSoonFalseStatutTermine(): void
    {
        $today = new \DateTimeImmutable();
        $dateFin = $today->modify('+3 days')->format('Y-m-d');

        $contrat = new Contrat();
        $contrat->setStatut('TERMINE');
        $contrat->setDateFin($dateFin);

        $manager = new ContratExpirationManager();
        $this->assertFalse($manager->isExpiringSoon($contrat, 7));
    }
}
