<?php

namespace App\Entity;

use App\Repository\RecuRemboursementCautionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecuRemboursementCautionRepository::class)]
#[ORM\Table(name: 'recu_remboursement_caution')]
class RecuRemboursementCaution
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $cautionId;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $contratId;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $numeroRecu;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $montantInitial;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $montantRetenu = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $montantRembourse;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $descriptionRetenue = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $photosReferences = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $urlFichier = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $transactionReference = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $locataireNom = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $locataireEmail = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $proprietaireNom = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string $dateRemboursement;

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

    public function getCautionId(): string
    {
        return $this->cautionId;
    }

    public function setCautionId(string $cautionId): static
    {
        $this->cautionId = $cautionId;
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

    public function getNumeroRecu(): string
    {
        return $this->numeroRecu;
    }

    public function setNumeroRecu(string $numeroRecu): static
    {
        $this->numeroRecu = $numeroRecu;
        return $this;
    }

    public function getMontantInitial(): string
    {
        return $this->montantInitial;
    }

    public function setMontantInitial(string $montantInitial): static
    {
        $this->montantInitial = $montantInitial;
        return $this;
    }

    public function getMontantRetenu(): ?string
    {
        return $this->montantRetenu;
    }

    public function setMontantRetenu(?string $montantRetenu): static
    {
        $this->montantRetenu = $montantRetenu;
        return $this;
    }

    public function getMontantRembourse(): string
    {
        return $this->montantRembourse;
    }

    public function setMontantRembourse(string $montantRembourse): static
    {
        $this->montantRembourse = $montantRembourse;
        return $this;
    }

    public function getDescriptionRetenue(): ?string
    {
        return $this->descriptionRetenue;
    }

    public function setDescriptionRetenue(?string $descriptionRetenue): static
    {
        $this->descriptionRetenue = $descriptionRetenue;
        return $this;
    }

    public function getPhotosReferences(): ?string
    {
        return $this->photosReferences;
    }

    public function setPhotosReferences(?string $photosReferences): static
    {
        $this->photosReferences = $photosReferences;
        return $this;
    }

    public function getUrlFichier(): ?string
    {
        return $this->urlFichier;
    }

    public function setUrlFichier(?string $urlFichier): static
    {
        $this->urlFichier = $urlFichier;
        return $this;
    }

    public function getTransactionReference(): ?string
    {
        return $this->transactionReference;
    }

    public function setTransactionReference(?string $transactionReference): static
    {
        $this->transactionReference = $transactionReference;
        return $this;
    }

    public function getLocataireNom(): ?string
    {
        return $this->locataireNom;
    }

    public function setLocataireNom(?string $locataireNom): static
    {
        $this->locataireNom = $locataireNom;
        return $this;
    }

    public function getLocataireEmail(): ?string
    {
        return $this->locataireEmail;
    }

    public function setLocataireEmail(?string $locataireEmail): static
    {
        $this->locataireEmail = $locataireEmail;
        return $this;
    }

    public function getProprietaireNom(): ?string
    {
        return $this->proprietaireNom;
    }

    public function setProprietaireNom(?string $proprietaireNom): static
    {
        $this->proprietaireNom = $proprietaireNom;
        return $this;
    }

    public function getDateRemboursement(): string
    {
        return $this->dateRemboursement;
    }

    public function setDateRemboursement(string $dateRemboursement): static
    {
        $this->dateRemboursement = $dateRemboursement;
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