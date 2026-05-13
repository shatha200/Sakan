<?php

namespace App\Dto;

class PaymentHistoryDto
{
    public string $type;
    public int $paiementId;
    public ?int $chargeId = null;
    public ?\DateTimeInterface $periode = null;
    public float $montant = 0;
    public float $penalite = 0;
    public float $montantTotal = 0;
    public ?\DateTimeInterface $datePaiement = null;
    public ?string $methode = null;
    public ?string $reference = null;
    public string $statut = '';
    public string $description = '';
}
