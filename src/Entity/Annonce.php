<?php

namespace App\Entity;

use App\Repository\AnnonceRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: AnnonceRepository::class)]
#[ORM\Table(name: 'annonce')]
class Annonce
{
    public const TYPES = [
        'appartement' => 'Appartement',
        'maison' => 'Maison / Villa',
        'studio' => 'Studio',
        'chambre' => 'Chambre',
        'bureau' => 'Bureau',
        'commercial' => 'Local Commercial'
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'proprietaireId', referencedColumnName: 'id', nullable: true)]
    private ?Utilisateur $proprietaire = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $titre = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $prix = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $margeNegociation = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $statut = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $dateDisponibilite = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $isFavorite = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $surface = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $chambres = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sallesDeBain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPrincipale = null;

    #[Gedmo\Slug(fields: ['titre'])]
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $slug = null;

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre;
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

    public function getPrix(): ?string
    {
        return $this->prix;
    }

    /**
     * Retourne le prix en float pour les calculs
     */
    public function getPrixFloat(): ?float
    {
        return $this->prix !== null ? (float) $this->prix : null;
    }

    public function setPrix(?string $prix): static
    {
        $this->prix = $prix;
        return $this;
    }

    public function getMargeNegociation(): ?string
    {
        return $this->margeNegociation;
    }

    /**
     * Retourne la marge en float pour les calculs
     */
    public function getMargeNegociationFloat(): ?float
    {
        return $this->margeNegociation !== null ? (float) $this->margeNegociation : null;
    }

    public function setMargeNegociation(?string $margeNegociation): static
    {
        $this->margeNegociation = $margeNegociation;
        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getDateDisponibilite(): ?string
    {
        return $this->dateDisponibilite;
    }

    public function setDateDisponibilite(?string $dateDisponibilite): static
    {
        $this->dateDisponibilite = $dateDisponibilite;
        return $this;
    }

    public function getProprietaire(): ?Utilisateur
    {
        return $this->proprietaire;
    }

    public function setProprietaire(?Utilisateur $proprietaire): static
    {
        $this->proprietaire = $proprietaire;
        return $this;
    }

    /**
     * Retourne l'ID propriétaire (compatibilité avec ancien code)
     */
    public function getProprietaireId(): ?string
    {
        $pid = $this->proprietaire?->getId();
        return $pid !== null ? (string)$pid : null;
    }

    /**
     * Retourne l'ID propriétaire en int pour les comparaisons
     */
    public function getProprietaireIdInt(): ?int
    {
        return $this->proprietaire?->getId() ?? null;
    }

    public function getIsFavorite(): ?string
    {
        return $this->isFavorite;
    }

    public function setIsFavorite(?string $isFavorite): static
    {
        $this->isFavorite = $isFavorite;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? 'Non défini';
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;
        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;
        return $this;
    }

    public function getSurface(): ?int
    {
        return $this->surface;
    }

    public function setSurface(?int $surface): static
    {
        $this->surface = $surface;
        return $this;
    }

    public function getChambres(): ?int
    {
        return $this->chambres;
    }

    public function setChambres(?int $chambres): static
    {
        $this->chambres = $chambres;
        return $this;
    }

    public function getSallesDeBain(): ?int
    {
        return $this->sallesDeBain;
    }

    public function setSallesDeBain(?int $sallesDeBain): static
    {
        $this->sallesDeBain = $sallesDeBain;
        return $this;
    }

    public function getPhotoPrincipale(): ?string
    {
        return $this->photoPrincipale;
    }

    public function setPhotoPrincipale(?string $photoPrincipale): static
    {
        $this->photoPrincipale = $photoPrincipale;
        return $this;
    }
}