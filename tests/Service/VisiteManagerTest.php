<?php

namespace App\Tests\Service;

use App\Entity\Visite;
use App\Service\VisiteManager;
use PHPUnit\Framework\TestCase;

class VisiteManagerTest extends TestCase
{
    // ✅ TEST 1 : Visite valide
    public function testVisiteValide(): void
    {
        $visite = new Visite();
        $visite->setDateHeure(new \DateTime('+2 days'));
        $visite->setStatut('En attente');

        $manager = new VisiteManager();
        $this->assertTrue($manager->validate($visite));
    }

    // ✅ TEST 2 : Date dans le passé → exception
    public function testDateDansLePasse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'La date de visite doit être dans le futur'
        );

        $visite = new Visite();
        $visite->setDateHeure(new \DateTime('-1 day'));
        $visite->setStatut('En attente');

        $manager = new VisiteManager();
        $manager->validate($visite);
    }

    // ✅ TEST 3 : Statut invalide → exception
    public function testStatutInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le statut de la visite est invalide'
        );

        $visite = new Visite();
        $visite->setDateHeure(new \DateTime('+2 days'));
        $visite->setStatut('INVALIDE');

        $manager = new VisiteManager();
        $manager->validate($visite);
    }

    // ✅ TEST 4 : Date manquante → exception
    public function testDateManquante(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'La date et heure de la visite sont obligatoires'
        );

        $visite = new Visite();
        $visite->setStatut('En attente');

        $manager = new VisiteManager();
        $manager->validate($visite);
    }

    // ✅ TEST 5 : Conflit intervalle < 120 min → true
    public function testConflitIntervalleInferieur120Min(): void
    {
        $manager = new VisiteManager();

        $visite1 = new \DateTime('2025-06-01 10:00:00');
        $visite2 = new \DateTime('2025-06-01 11:00:00');

        $this->assertTrue($manager->hasConflitIntervalle($visite1, $visite2));
    }

    // ✅ TEST 6 : Pas de conflit intervalle >= 120 min → false
    public function testPasDeConflitIntervalle120Min(): void
    {
        $manager = new VisiteManager();

        $visite1 = new \DateTime('2025-06-01 10:00:00');
        $visite2 = new \DateTime('2025-06-01 12:00:00');

        $this->assertFalse($manager->hasConflitIntervalle($visite1, $visite2));
    }

    // ✅ TEST 7 : QR pass → visite confirmée → true
    public function testPeutGenererQrPassConfirmee(): void
    {
        $visite = new Visite();
        $visite->setStatut('Confirmée');

        $manager = new VisiteManager();
        $this->assertTrue($manager->peutGenererQrPass($visite));
    }

    // ✅ TEST 8 : QR pass → visite en attente → exception
    public function testPeutGenererQrPassEnAttente(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le QR pass ne peut être généré que pour une visite confirmée ou acceptée'
        );

        $visite = new Visite();
        $visite->setStatut('En attente');

        $manager = new VisiteManager();
        $manager->peutGenererQrPass($visite);
    }
}
