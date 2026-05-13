<?php

namespace App\Entity;

use App\Repository\ChargesMensuellesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChargesMensuellesRepository::class)]
#[ORM\Table(name: 'charges_mensuelles')]
class ChargesMensuelles
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $contratId;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $typeCharge;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $periode;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $montant;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $partageColoc;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $nombreColocataires;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $partLocataire = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $statutPaiement;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $fichierFacture = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $dateAjout;

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

    public function getContratId(): string
    {
        return $this->contratId;
    }

    public function setContratId(string $contratId): static
    {
        $this->contratId = $contratId;
        return $this;
    }

    public function getTypeCharge(): string
    {
        return $this->typeCharge;
    }

    public function setTypeCharge(string $typeCharge): static
    {
        $this->typeCharge = $typeCharge;
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

    public function getPartageColoc(): string
    {
        return $this->partageColoc;
    }

    public function setPartageColoc(string $partageColoc): static
    {
        $this->partageColoc = $partageColoc;
        return $this;
    }

    public function getNombreColocataires(): string
    {
        return $this->nombreColocataires;
    }

    public function setNombreColocataires(string $nombreColocataires): static
    {
        $this->nombreColocataires = $nombreColocataires;
        return $this;
    }

    public function getPartLocataire(): ?string
    {
        return $this->partLocataire;
    }

    public function setPartLocataire(?string $partLocataire): static
    {
        $this->partLocataire = $partLocataire;
        return $this;
    }

    public function getStatutPaiement(): string
    {
        return $this->statutPaiement;
    }

    public function setStatutPaiement(string $statutPaiement): static
    {
        $this->statutPaiement = $statutPaiement;
        return $this;
    }

    public function getFichierFacture(): ?string
    {
        return $this->fichierFacture;
    }

    public function setFichierFacture(?string $fichierFacture): static
    {
        $this->fichierFacture = $fichierFacture;
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

    public function getDateAjout(): string
    {
        return $this->dateAjout;
    }

    public function setDateAjout(string $dateAjout): static
    {
        $this->dateAjout = $dateAjout;
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