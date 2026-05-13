<?php

namespace App\Entity;

use App\Repository\FactureRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FactureRepository::class)]
#[ORM\Table(name: 'facture')]
class Facture
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $typeReference;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $referenceId;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $typeDocument;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $numeroFacture = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $fichier = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $periode = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $montant = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $dateEmission = null;

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

    public function getTypeReference(): string
    {
        return $this->typeReference;
    }

    public function setTypeReference(string $typeReference): static
    {
        $this->typeReference = $typeReference;
        return $this;
    }

    public function getReferenceId(): string
    {
        return $this->referenceId;
    }

    public function setReferenceId(string $referenceId): static
    {
        $this->referenceId = $referenceId;
        return $this;
    }

    public function getTypeDocument(): string
    {
        return $this->typeDocument;
    }

    public function setTypeDocument(string $typeDocument): static
    {
        $this->typeDocument = $typeDocument;
        return $this;
    }

    public function getNumeroFacture(): ?string
    {
        return $this->numeroFacture;
    }

    public function setNumeroFacture(?string $numeroFacture): static
    {
        $this->numeroFacture = $numeroFacture;
        return $this;
    }

    public function getFichier(): ?string
    {
        return $this->fichier;
    }

    public function setFichier(?string $fichier): static
    {
        $this->fichier = $fichier;
        return $this;
    }

    public function getPeriode(): ?string
    {
        return $this->periode;
    }

    public function setPeriode(?string $periode): static
    {
        $this->periode = $periode;
        return $this;
    }

    public function getMontant(): ?string
    {
        return $this->montant;
    }

    public function setMontant(?string $montant): static
    {
        $this->montant = $montant;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getDateEmission(): ?string
    {
        return $this->dateEmission;
    }

    public function setDateEmission(?string $dateEmission): static
    {
        $this->dateEmission = $dateEmission;
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