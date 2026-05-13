<?php

namespace App\Dto;

class DashboardLocataireDto
{
    // Header
    public string $nomLocataire = '';
    
    // Prochain Loyer
    public ?float $prochainLoyerMontant = null;
    public ?\DateTimeInterface $prochainLoyerEcheance = null;
    public ?int $joursRetard = null;  // négatif = jours restants
    public string $statutRetard = 'ok'; // ok, warn, err
    
    // Charges
    public float $totalChargesAttente = 0;
    /** @var list<array<string, mixed>> */
    public array $chargesDetail = []; // ['type' => 'Eau', 'montant' => 50.00]
    
    // Caution
    public ?float $cautionMontant = null;
    public ?string $cautionStatut = null;
    public string $cautionStyle = 'neutral'; // ok, warn, accent
    
    // Stats
    public float $totalPayeMois = 0;
    public float $totalPayeAnnee = 0;
    public ?float $pourcentageATemps = null;
    public string $ponctualiteStyle = 'neutral'; // ok, warn, err
    
    // Contrat
    public ?string $bienLoue = null;
    public ?float $loyerMensuel = null;
    public ?string $statutContrat = null;
    public string $statutContratStyle = 'neutral';
    public ?string $periodeContrat = null; // "01/01/2024 → 31/12/2024"
}
