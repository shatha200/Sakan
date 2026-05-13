<?php

namespace App\Tests\Service;

use App\Entity\PaiementLoyer;
use App\Service\PaiementManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour PaiementManager.
 *
 * Couvre les règles métier :
 *   - montant > 0
 *   - statut dans la liste autorisée
 *   - date d'échéance obligatoire
 *
 * 100 % isolé : pas de base de données, pas de Kernel.
 */
class PaiementManagerTest extends TestCase
{
    // ────────────────────────────────────────────────────────────
    // TEST 1 : Paiement valide (cas nominal)
    // ────────────────────────────────────────────────────────────

    public function testPaiementValide(): void
    {
        $paiement = new PaiementLoyer();
        $paiement->setMontant('500.00');
        $paiement->setStatut('A_PAYER');
        $paiement->setDateEcheance('2025-06-01');

        $manager = new PaiementManager();

        $this->assertTrue($manager->validate($paiement));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 2 : Montant = 0  →  InvalidArgumentException
    // ────────────────────────────────────────────────────────────

    public function testMontantZeroInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le montant du loyer doit être supérieur à zéro'
        );

        $paiement = new PaiementLoyer();
        $paiement->setMontant('0');
        $paiement->setStatut('A_PAYER');
        $paiement->setDateEcheance('2025-06-01');

        $manager = new PaiementManager();
        $manager->validate($paiement);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 3 : Montant négatif  →  InvalidArgumentException
    // ────────────────────────────────────────────────────────────

    public function testMontantNegatifInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $paiement = new PaiementLoyer();
        $paiement->setMontant('-100');
        $paiement->setStatut('A_PAYER');
        $paiement->setDateEcheance('2025-06-01');

        $manager = new PaiementManager();
        $manager->validate($paiement);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 4 : Statut invalide  →  InvalidArgumentException
    // ────────────────────────────────────────────────────────────

    public function testStatutInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le statut du paiement est invalide'
        );

        $paiement = new PaiementLoyer();
        $paiement->setMontant('500.00');
        $paiement->setStatut('STATUT_INVALIDE');
        $paiement->setDateEcheance('2025-06-01');

        $manager = new PaiementManager();
        $manager->validate($paiement);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 5 : Date d'échéance vide  →  InvalidArgumentException
    // ────────────────────────────────────────────────────────────

    public function testDateEcheanceManquante(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "La date d'échéance est obligatoire"
        );

        $paiement = new PaiementLoyer();
        $paiement->setMontant('500.00');
        $paiement->setStatut('A_PAYER');
        $paiement->setDateEcheance('');

        $manager = new PaiementManager();
        $manager->validate($paiement);
    }
}
