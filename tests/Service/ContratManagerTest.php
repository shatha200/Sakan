<?php

namespace App\Tests\Service;

use App\Entity\Contrat;
use App\Service\ContratManager;
use PHPUnit\Framework\TestCase;

class ContratManagerTest extends TestCase
{
    // ✅ TEST 1 : Contrat valide
    public function testContratValide(): void
    {
        $contrat = new Contrat();
        $contrat->setDateDebut('2025-01-01');
        $contrat->setDateFin('2025-12-31');
        $contrat->setMontant('800.00');
        $contrat->setStatut('PROPOSE');

        $manager = new ContratManager();
        $this->assertTrue($manager->validate($contrat));
    }

    // ✅ TEST 2 : Montant zéro → exception
    public function testMontantZeroInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le montant du loyer doit être supérieur à zéro'
        );

        $contrat = new Contrat();
        $contrat->setDateDebut('2025-01-01');
        $contrat->setDateFin('2025-12-31');
        $contrat->setMontant('0');
        $contrat->setStatut('PROPOSE');

        $manager = new ContratManager();
        $manager->validate($contrat);
    }

    // ✅ TEST 3 : Date fin avant date début → exception
    public function testDateFinAvantDateDebut(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'La date de fin doit être postérieure à la date de début'
        );

        $contrat = new Contrat();
        $contrat->setDateDebut('2025-12-31');
        $contrat->setDateFin('2025-01-01');
        $contrat->setMontant('800.00');
        $contrat->setStatut('PROPOSE');

        $manager = new ContratManager();
        $manager->validate($contrat);
    }

    // ✅ TEST 4 : Statut invalide → exception
    public function testStatutInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le statut du contrat est invalide'
        );

        $contrat = new Contrat();
        $contrat->setDateDebut('2025-01-01');
        $contrat->setDateFin('2025-12-31');
        $contrat->setMontant('800.00');
        $contrat->setStatut('STATUT_INVALIDE');

        $manager = new ContratManager();
        $manager->validate($contrat);
    }

    // ✅ TEST 5 : isFullySigned → les deux ont signé
    public function testIsFullySignedTrue(): void
    {
        $contrat = new Contrat();
        $contrat->setSigneLocataire(true);
        $contrat->setSigneProprietaire(true);

        $manager = new ContratManager();
        $this->assertTrue($manager->isFullySigned($contrat));
    }

    // ✅ TEST 6 : isFullySigned → seul locataire a signé
    public function testIsFullySignedFalseLocataireSeulement(): void
    {
        $contrat = new Contrat();
        $contrat->setSigneLocataire(true);
        $contrat->setSigneProprietaire(false);

        $manager = new ContratManager();
        $this->assertFalse($manager->isFullySigned($contrat));
    }

    // ✅ TEST 7 : isFullySigned → personne n'a signé
    public function testIsFullySignedFalsePersonne(): void
    {
        $contrat = new Contrat();
        $contrat->setSigneLocataire(false);
        $contrat->setSigneProprietaire(false);

        $manager = new ContratManager();
        $this->assertFalse($manager->isFullySigned($contrat));
    }

    // ✅ TEST 8 : getDureeMois → 6 mois
    public function testGetDureeMoisSixMois(): void
    {
        $contrat = new Contrat();
        $contrat->setDateDebut('2025-01-01');
        $contrat->setDateFin('2025-07-01');

        $manager = new ContratManager();
        $this->assertEquals(6, $manager->getDureeMois($contrat));
    }

    // ✅ TEST 9 : getDureeMois → 12 mois
    public function testGetDureeMoisUnAn(): void
    {
        $contrat = new Contrat();
        $contrat->setDateDebut('2025-01-01');
        $contrat->setDateFin('2026-01-01');

        $manager = new ContratManager();
        $this->assertEquals(12, $manager->getDureeMois($contrat));
    }
}
