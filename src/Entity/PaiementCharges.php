<?php

namespace App\Entity;

use App\Repository\PaiementChargesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaiementChargesRepository::class)]
#[ORM\Table(name: 'paiement_charges')]
class PaiementCharges
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $chargeId;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $montantPaye;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $datePaiement;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $methodePaiement;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $referenceTransaction = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $dateCreation;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getChargeId(): string
    {
        return $this->chargeId;
    }

    public function setChargeId(string $chargeId): static
    {
        $this->chargeId = $chargeId;
        return $this;
    }

    public function getMontantPaye(): string
    {
        return $this->montantPaye;
    }

    public function setMontantPaye(string $montantPaye): static
    {
        $this->montantPaye = $montantPaye;
        return $this;
    }

    public function getDatePaiement(): string
    {
        return $this->datePaiement;
    }

    public function setDatePaiement(string $datePaiement): static
    {
        $this->datePaiement = $datePaiement;
        return $this;
    }

    public function getMethodePaiement(): string
    {
        return $this->methodePaiement;
    }

    public function setMethodePaiement(string $methodePaiement): static
    {
        $this->methodePaiement = $methodePaiement;
        return $this;
    }

    public function getReferenceTransaction(): ?string
    {
        return $this->referenceTransaction;
    }

    public function setReferenceTransaction(?string $referenceTransaction): static
    {
        $this->referenceTransaction = $referenceTransaction;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getDateCreation(): string
    {
        return $this->dateCreation;
    }

    public function setDateCreation(string $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

}