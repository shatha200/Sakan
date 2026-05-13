<?php

namespace App\Tests\Service;

use App\Entity\Caution;
use App\Service\CautionManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour CautionManager.
 *
 * Couvre les règles métier :
 *   - montant initial > 0
 *   - montant rétention ≤ montant initial
 *   - description obligatoire si rétention > 0
 *
 * 100 % isolé : pas de base de données, pas de Kernel.
 */
class CautionManagerTest extends TestCase
{
    // ────────────────────────────────────────────────────────────
    // TEST 1 : Caution valide sans rétention (cas nominal)
    // ────────────────────────────────────────────────────────────

    public function testCautionValide(): void
    {
        $caution = new Caution();
        $caution->setMontantInitial('1000.00');
        $caution->setMontantRetention('0.00');
        $caution->setDescriptionRetenue(null);

        $manager = new CautionManager();

        $this->assertTrue($manager->validate($caution));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 2 : Caution valide avec rétention + description
    // ────────────────────────────────────────────────────────────

    public function testCautionValideAvecRetention(): void
    {
        $caution = new Caution();
        $caution->setMontantInitial('1000.00');
        $caution->setMontantRetention('200.00');
        $caution->setDescriptionRetenue('Dégâts sur le mur');

        $manager = new CautionManager();

        $this->assertTrue($manager->validate($caution));
    }

    // ────────────────────────────────────────────────────────────
    // TEST 3 : Montant initial = 0  →  InvalidArgumentException
    // ────────────────────────────────────────────────────────────

    public function testMontantInitialZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le montant initial de la caution doit être supérieur à zéro'
        );

        $caution = new Caution();
        $caution->setMontantInitial('0');
        $caution->setMontantRetention('0.00');

        $manager = new CautionManager();
        $manager->validate($caution);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 4 : Rétention > initial  →  InvalidArgumentException
    // ────────────────────────────────────────────────────────────

    public function testRetentionSuperieurInitial(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le montant de rétention ne peut pas dépasser le montant initial'
        );

        $caution = new Caution();
        $caution->setMontantInitial('500.00');
        $caution->setMontantRetention('600.00');
        $caution->setDescriptionRetenue('Dégâts');

        $manager = new CautionManager();
        $manager->validate($caution);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 5 : Rétention > 0 sans description  →  InvalidArgumentException
    // ────────────────────────────────────────────────────────────

    public function testRetentionSansDescription(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'La description est obligatoire quand il y a une rétention'
        );

        $caution = new Caution();
        $caution->setMontantInitial('1000.00');
        $caution->setMontantRetention('200.00');
        $caution->setDescriptionRetenue('');

        $manager = new CautionManager();
        $manager->validate($caution);
    }
}
