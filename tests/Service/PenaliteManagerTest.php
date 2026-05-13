<?php

namespace App\Tests\Service;

use App\Service\PenaliteManager;
use PHPUnit\Framework\TestCase;

class PenaliteManagerTest extends TestCase
{
    // ✅ TEST 1 : Dans le délai de grâce → pénalité = 0
    public function testDansDelaiGracePenaliteZero(): void
    {
        $manager = new PenaliteManager();
        $result = $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: 3,
            delaiGraceJours: 5,
            penaliteFixe: 10.0,
            penalitePourcentage: 5.0,
            plafondPourcentage: 10.0
        );
        $this->assertEquals(0.0, $result);
    }

    // ✅ TEST 2 : Exactement au délai de grâce → pénalité = 0
    public function testExactementDelaiGracePenaliteZero(): void
    {
        $manager = new PenaliteManager();
        $result = $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: 5,
            delaiGraceJours: 5,
            penaliteFixe: 10.0,
            penalitePourcentage: 5.0,
            plafondPourcentage: 10.0
        );
        $this->assertEquals(0.0, $result);
    }

    // ✅ TEST 3 : Pénalité calculée sans atteindre le plafond
    public function testPenaliteSansPlafond(): void
    {
        $manager = new PenaliteManager();
        // penalite = 10 + (1000 × 5/100) = 60
        // plafond = 1000 × 10/100 = 100
        // min(60, 100) = 60
        $result = $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: 10,
            delaiGraceJours: 5,
            penaliteFixe: 10.0,
            penalitePourcentage: 5.0,
            plafondPourcentage: 10.0
        );
        $this->assertEquals(60.0, $result);
    }

    // ✅ TEST 4 : Pénalité plafonnée
    public function testPenalitePlafonnee(): void
    {
        $manager = new PenaliteManager();
        // penalite = 50 + (1000 × 15/100) = 200
        // plafond = 1000 × 10/100 = 100
        // min(200, 100) = 100
        $result = $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: 10,
            delaiGraceJours: 5,
            penaliteFixe: 50.0,
            penalitePourcentage: 15.0,
            plafondPourcentage: 10.0
        );
        $this->assertEquals(100.0, $result);
    }

    // ✅ TEST 5 : Loyer négatif → exception
    public function testLoyerNegatifException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le loyer doit être supérieur à zéro'
        );

        $manager = new PenaliteManager();
        $manager->calculerPenalite(
            loyer: -500.0,
            joursRetard: 10,
            delaiGraceJours: 5,
            penaliteFixe: 10.0,
            penalitePourcentage: 5.0,
            plafondPourcentage: 10.0
        );
    }

    // ✅ TEST 6 : Jours retard négatif → exception
    public function testJoursRetardNegatifException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le nombre de jours de retard ne peut pas être négatif'
        );

        $manager = new PenaliteManager();
        $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: -3,
            delaiGraceJours: 5,
            penaliteFixe: 10.0,
            penalitePourcentage: 5.0,
            plafondPourcentage: 10.0
        );
    }

    // ✅ TEST 7 : isEnRetard → vrai
    public function testIsEnRetardTrue(): void
    {
        $manager = new PenaliteManager();
        $this->assertTrue(
            $manager->isEnRetard(10, 5)
        );
    }

    // ✅ TEST 8 : isEnRetard → faux
    public function testIsEnRetardFalse(): void
    {
        $manager = new PenaliteManager();
        $this->assertFalse(
            $manager->isEnRetard(3, 5)
        );
    }

    // ✅ TEST 9 : Plafond négatif → exception
    public function testPlafondNegatifException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le plafond ne peut pas être négatif'
        );

        $manager = new PenaliteManager();
        $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: 10,
            delaiGraceJours: 5,
            penaliteFixe: 10.0,
            penalitePourcentage: 5.0,
            plafondPourcentage: -10.0
        );
    }

    // ✅ TEST 10 : Loyer zéro → exception
    public function testLoyerZeroException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le loyer doit être supérieur à zéro'
        );

        $manager = new PenaliteManager();
        $manager->calculerPenalite(
            loyer: 0.0,
            joursRetard: 10,
            delaiGraceJours: 5,
            penaliteFixe: 10.0,
            penalitePourcentage: 5.0,
            plafondPourcentage: 10.0
        );
    }

    // ✅ TEST 11 : Pénalité fixe à zéro
    public function testPenaliteFixeZero(): void
    {
        $manager = new PenaliteManager();
        // penalite = 0 + (1000 × 5/100) = 50
        // plafond = 1000 × 10/100 = 100
        // min(50, 100) = 50
        $result = $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: 10,
            delaiGraceJours: 5,
            penaliteFixe: 0.0,
            penalitePourcentage: 5.0,
            plafondPourcentage: 10.0
        );
        $this->assertEquals(50.0, $result);
    }

    // ✅ TEST 12 : Pourcentage pénalité à zéro
    public function testPenalitePourcentageZero(): void
    {
        $manager = new PenaliteManager();
        // penalite = 10 + 0 = 10
        // plafond = 1000 × 10/100 = 100
        // min(10, 100) = 10
        $result = $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: 10,
            delaiGraceJours: 5,
            penaliteFixe: 10.0,
            penalitePourcentage: 0.0,
            plafondPourcentage: 10.0
        );
        $this->assertEquals(10.0, $result);
    }

    // ✅ TEST 13 : Exactement au plafond
    public function testExactementAuPlafond(): void
    {
        $manager = new PenaliteManager();
        // penalite = 10 + (1000 × 9/100) = 100
        // plafond = 1000 × 10/100 = 100
        // min(100, 100) = 100
        $result = $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: 10,
            delaiGraceJours: 5,
            penaliteFixe: 10.0,
            penalitePourcentage: 9.0,
            plafondPourcentage: 10.0
        );
        $this->assertEquals(100.0, $result);
    }

    // ✅ TEST 14 : isEnRetard exactement égal
    public function testIsEnRetardExactementEgal(): void
    {
        $manager = new PenaliteManager();
        // 5 jours de retard, délai de grâce 5 jours → pas en retard
        $this->assertFalse(
            $manager->isEnRetard(5, 5)
        );
    }

    // ✅ TEST 15 : Délai de grâce zéro
    public function testDelaiGraceZero(): void
    {
        $manager = new PenaliteManager();
        // Jour 1 de retard avec délai de grâce 0 → pénalité
        $result = $manager->calculerPenalite(
            loyer: 1000.0,
            joursRetard: 1,
            delaiGraceJours: 0,
            penaliteFixe: 10.0,
            penalitePourcentage: 5.0,
            plafondPourcentage: 10.0
        );
        $this->assertGreaterThan(0.0, $result);
    }
}
