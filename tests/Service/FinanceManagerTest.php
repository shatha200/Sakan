<?php

namespace App\Tests\Service;

use App\Service\FinanceManager;
use PHPUnit\Framework\TestCase;

class FinanceManagerTest extends TestCase
{
    

    // ✅ TEST  : Caution disponible → calcul correct
    public function testCalculerMontantDisponibleCaution(): void
    {
        $manager = new FinanceManager();
        // 1000 - 200 - 300 = 500
        $result = $manager->calculerMontantDisponibleCaution(
            montantInitial: 1000.0,
            montantRetention: 200.0,
            montantRembourse: 300.0
        );
        $this->assertEquals(500.0, $result);
    }

    // ✅ TEST  : Caution initiale zéro → exception
    public function testCautionInitialeZeroException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le montant initial de la caution doit être positif'
        );

        $manager = new FinanceManager();
        $manager->calculerMontantDisponibleCaution(
            montantInitial: 0.0,
            montantRetention: 0.0,
            montantRembourse: 0.0
        );
    }

    // ✅ TEST  : Caution initiale négative → exception
    public function testCautionInitialeNegativeException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Le montant initial de la caution doit être positif'
        );

        $manager = new FinanceManager();
        $manager->calculerMontantDisponibleCaution(
            montantInitial: -100.0,
            montantRetention: 0.0,
            montantRembourse: 0.0
        );
    }

    
    // ✅ TEST  : Statut charge → PAYE (exact)
    public function testStatutPaiementChargePayeExact(): void
    {
        $manager = new FinanceManager();
        $result = $manager->determinerStatutPaiementCharge(
            montantTotal: 200.0,
            totalPaye: 200.0
        );
        $this->assertEquals('PAYE', $result);
    }

    // ✅ TEST  : Statut charge → PAYE (surpayé)
    public function testStatutPaiementChargePayeSurpaye(): void
    {
        $manager = new FinanceManager();
        $result = $manager->determinerStatutPaiementCharge(
            montantTotal: 200.0,
            totalPaye: 250.0
        );
        $this->assertEquals('PAYE', $result);
    }

    // ✅ TEST  : Statut charge → PARTIEL
    public function testStatutPaiementChargePartiel(): void
    {
        $manager = new FinanceManager();
        $result = $manager->determinerStatutPaiementCharge(
            montantTotal: 200.0,
            totalPaye: 100.0
        );
        $this->assertEquals('PARTIEL', $result);
    }

    // ✅ TEST 14 : Statut charge → NON_PAYE
    public function testStatutPaiementChargeNonPaye(): void
    {
        $manager = new FinanceManager();
        $result = $manager->determinerStatutPaiementCharge(
            montantTotal: 200.0,
            totalPaye: 0.0
        );
        $this->assertEquals('NON_PAYE', $result);
    }

    

    // ✅ TEST  : Référence transaction valide
    public function testReferenceTransactionValide(): void
    {
        $manager = new FinanceManager();
        $this->assertTrue(
            $manager->validerReferenceTransaction('TXN-2025-001')
        );
    }

    // ✅ TEST  : Référence transaction vide → exception
    public function testReferenceTransactionVideException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'La référence de transaction est obligatoire'
        );

        $manager = new FinanceManager();
        $manager->validerReferenceTransaction('');
    }

    // ✅ TEST  : Référence transaction null → exception
    public function testReferenceTransactionNullException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'La référence de transaction est obligatoire'
        );

        $manager = new FinanceManager();
        $manager->validerReferenceTransaction(null);
    }

    // ✅ TEST  : Référence transaction espaces → exception
    public function testReferenceTransactionEspacesException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $manager = new FinanceManager();
        $manager->validerReferenceTransaction('   ');
    }
}
