<?php

namespace App\Entity;

use App\Repository\PaiementLoyerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaiementLoyerRepository::class)]
#[ORM\Table(name: 'paiement_loyer')]
class PaiementLoyer
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contrat::class)]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id')]
    private ?Contrat $contrat = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $periode;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $montant;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $penalite;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $dateEcheance;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $datePaiement = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $statut;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $methodePaiement = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $referenceTransaction = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $dateCreation;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $dateModification = null;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getContrat(): ?Contrat
    {
        return $this->contrat;
    }

    public function setContrat(?Contrat $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }

    public function getPeriode(): string
    {
        return $this->periode;
    }

    public function setPeriode(string $periode): static
    {
        $this->periode = $periode;
        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): static
    {
        $this->montant = $montant;
        return $this;
    }

    public function getPenalite(): string
    {
        return $this->penalite;
    }

    public function setPenalite(string $penalite): static
    {
        $this->penalite = $penalite;
        return $this;
    }

    public function getDateEcheance(): string
    {
        return $this->dateEcheance;
    }

    public function setDateEcheance(string $dateEcheance): static
    {
        $this->dateEcheance = $dateEcheance;
        return $this;
    }

    public function getDatePaiement(): ?string
    {
        return $this->datePaiement;
    }

    public function setDatePaiement(?string $datePaiement): static
    {
        $this->datePaiement = $datePaiement;
        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getMethodePaiement(): ?string
    {
        return $this->methodePaiement;
    }

    public function setMethodePaiement(?string $methodePaiement): static
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

    public function getDateCreation(): string
    {
        return $this->dateCreation;
    }

    public function setDateCreation(string $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getDateModification(): ?string
    {
        return $this->dateModification;
    }

    public function setDateModification(?string $dateModification): static
    {
        $this->dateModification = $dateModification;
        return $this;
    }

}