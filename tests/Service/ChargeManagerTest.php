<?php

namespace App\Tests\Service;

use App\Entity\ChargesMensuelles;
use App\Service\ChargeManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour ChargeManager.
 *
 * Couvre les règles métier :
 *   - montant > 0
 *   - type de charge dans la liste autorisée
 *   - partageColoc = '1' → nombreColocataires >= 2
 *
 * 100 % isolé : pas de base de données, pas de Kernel.
 */
class ChargeManagerTest extends TestCase
{
    // ────────────────────────────────────────────────────────────
    // TEST 1 : Charge valide (cas nominal)
    // ────────────────────────────────────────────────────────────

    public function testChargeValide(): void
    {
        $charge = new ChargesMensuelles();
        $charge->setMontant('150.00');
        $charge->setTypeCharge('ELECTRICITE');
        $charge->setPartageColoc('0');
        $charge->setNombreColocataires('1');

        $manager = new ChargeManager();

        $this->assertTrue($manager->validate($charge));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 2 : Montant = 0  →  InvalidArgumentException
    // ────────────────────────────────────────────────────────────

    public function testMontantZeroInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le montant de la charge doit être supérieur à zéro'
        );

        $charge = new ChargesMensuelles();
        $charge->setMontant('0');
        $charge->setTypeCharge('EAU');
        $charge->setPartageColoc('0');
        $charge->setNombreColocataires('1');

        $manager = new ChargeManager();
        $manager->validate($charge);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 3 : Type de charge invalide  →  InvalidArgumentException
    // ────────────────────────────────────────────────────────────

    public function testTypeChargeInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le type de charge est invalide'
        );

        $charge = new ChargesMensuelles();
        $charge->setMontant('100.00');
        $charge->setTypeCharge('TYPE_INEXISTANT');
        $charge->setPartageColoc('0');
        $charge->setNombreColocataires('1');

        $manager = new ChargeManager();
        $manager->validate($charge);
    }

}
