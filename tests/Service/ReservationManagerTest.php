<?php

namespace App\Tests\Service;

use App\Entity\Reservation;
use App\Service\ReservationManager;
use PHPUnit\Framework\TestCase;

class ReservationManagerTest extends TestCase
{
    // ✅ TEST 1 : Réservation valide
    public function testReservationValide(): void
    {
        $reservation = new Reservation();
        $reservation->setDateDebut(new \DateTime('+1 day'));
        $reservation->setDateFin(new \DateTime('+30 days'));
        $reservation->setStatut('En attente');

        $manager = new ReservationManager();
        $this->assertTrue($manager->validate($reservation));
    }

    // ✅ TEST 2 : Date début dans le passé → exception
    public function testDateDebutDansLePasse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'La date de début ne peut pas être dans le passé'
        );

        $reservation = new Reservation();
        $reservation->setDateDebut(new \DateTime('-1 day'));
        $reservation->setDateFin(new \DateTime('+30 days'));
        $reservation->setStatut('En attente');

        $manager = new ReservationManager();
        $manager->validate($reservation);
    }

    // ✅ TEST 3 : Date fin avant date début → exception
    public function testDateFinAvantDateDebut(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'La date de fin doit être postérieure à la date de début'
        );

        $reservation = new Reservation();
        $reservation->setDateDebut(new \DateTime('+10 days'));
        $reservation->setDateFin(new \DateTime('+5 days'));
        $reservation->setStatut('En attente');

        $manager = new ReservationManager();
        $manager->validate($reservation);
    }

    // ✅ TEST 4 : Statut invalide → exception
    public function testStatutInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le statut de la réservation est invalide'
        );

        $reservation = new Reservation();
        $reservation->setDateDebut(new \DateTime('+1 day'));
        $reservation->setDateFin(new \DateTime('+30 days'));
        $reservation->setStatut('STATUT_INVALIDE');

        $manager = new ReservationManager();
        $manager->validate($reservation);
    }

    // ✅ TEST 5 : Date début manquante → exception
    public function testDateDebutManquante(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'La date de début est obligatoire'
        );

        $reservation = new Reservation();
        $reservation->setDateFin(new \DateTime('+30 days'));
        $reservation->setStatut('En attente');

        $manager = new ReservationManager();
        $manager->validate($reservation);
    }

    // ✅ TEST 6 : peutCreerContrat → réservation approuvée
    public function testPeutCreerContratApprouvee(): void
    {
        $reservation = new Reservation();
        $reservation->setStatut('Approuvée');

        $manager = new ReservationManager();
        $this->assertTrue($manager->peutCreerContrat($reservation));
    }

    // ✅ TEST 7 : peutCreerContrat → réservation en attente → exception
    public function testPeutCreerContratEnAttente(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Un contrat ne peut être créé que depuis une réservation approuvée'
        );

        $reservation = new Reservation();
        $reservation->setStatut('En attente');

        $manager = new ReservationManager();
        $manager->peutCreerContrat($reservation);
    }
}
