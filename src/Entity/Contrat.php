<?php

namespace App\Entity;

use App\Repository\ContratRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContratRepository::class)]
#[ORM\Table(name: 'contrat')]
class Contrat
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Annonce::class)]
    #[ORM\JoinColumn(name: 'annonceId', referencedColumnName: 'id')]
    private ?Annonce $annonce = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'locataireId', referencedColumnName: 'id')]
    private ?Utilisateur $locataire = null;

    /**
     * Lien optionnel vers la réservation d'origine.
     * Permet de retrouver le contrat depuis une réservation approuvée.
     */
    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation = null;

    #[ORM\Column(name: 'date_debut', type: 'string', nullable: true)]
    private ?string $dateDebut = null;

    #[ORM\Column(name: 'date_fin', type: 'string', nullable: true)]
    private ?string $dateFin = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $montant = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $statut = null;

    #[ORM\Column(type: 'boolean')]
    private bool $signeLocataire = false;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $dateSignatureLocataire = null;

    #[ORM\Column(type: 'boolean')]
    private bool $signeProprietaire = false;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $dateSignatureProprietaire = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $locataireSignatureImage = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $proprietaireSignatureImage = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $cinImage = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $cinImageProprietaire = null;

    public function __construct()
    {
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

    public function getAnnonce(): ?Annonce
    {
        return $this->annonce;
    }

    public function setAnnonce(?Annonce $annonce): static
    {
        $this->annonce = $annonce;
        return $this;
    }

    public function getLocataire(): ?Utilisateur
    {
        return $this->locataire;
    }

    public function setLocataire(?Utilisateur $locataire): static
    {
        $this->locataire = $locataire;
        return $this;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): static
    {
        $this->reservation = $reservation;
        return $this;
    }

    public function getDateDebut(): ?string
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?string $dateDebut): static
    {
        $this->dateDebut = $dateDebut;
        return $this;
    }

    public function getDateFin(): ?string
    {
        return $this->dateFin;
    }

    public function setDateFin(?string $dateFin): static
    {
        $this->dateFin = $dateFin;
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

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MÉTHODES UTILITAIRES
    // ═══════════════════════════════════════════════════════════════════════════

    public function getDureeMois(): ?int
    {
        if (!$this->dateDebut || !$this->dateFin) {
            return null;
        }
        try {
            $debut = new \DateTime($this->dateDebut);
            $fin = new \DateTime($this->dateFin);
            $interval = $debut->diff($fin);
            return ($interval->y * 12) + $interval->m;
        } catch (\Exception $e) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GESTION DES SIGNATURES
    // ═══════════════════════════════════════════════════════════════════════════

    public function isSigneLocataire(): bool
    {
        return $this->signeLocataire;
    }

    public function setSigneLocataire(bool $signeLocataire): static
    {
        $this->signeLocataire = $signeLocataire;
        return $this;
    }

    public function getDateSignatureLocataire(): ?string
    {
        return $this->dateSignatureLocataire;
    }

    public function setDateSignatureLocataire(?string $dateSignatureLocataire): static
    {
        $this->dateSignatureLocataire = $dateSignatureLocataire;
        return $this;
    }

    public function isSigneProprietaire(): bool
    {
        return $this->signeProprietaire;
    }

    public function setSigneProprietaire(bool $signeProprietaire): static
    {
        $this->signeProprietaire = $signeProprietaire;
        return $this;
    }

    public function getDateSignatureProprietaire(): ?string
    {
        return $this->dateSignatureProprietaire;
    }

    public function setDateSignatureProprietaire(?string $dateSignatureProprietaire): static
    {
        $this->dateSignatureProprietaire = $dateSignatureProprietaire;
        return $this;
    }

    public function getLocataireSignatureImage(): ?string
    {
        return $this->locataireSignatureImage;
    }

    public function setLocataireSignatureImage(?string $locataireSignatureImage): static
    {
        $this->locataireSignatureImage = $locataireSignatureImage;
        return $this;
    }

    public function getProprietaireSignatureImage(): ?string
    {
        return $this->proprietaireSignatureImage;
    }

    public function setProprietaireSignatureImage(?string $proprietaireSignatureImage): static
    {
        $this->proprietaireSignatureImage = $proprietaireSignatureImage;
        return $this;
    }

    public function getCinImage(): ?string
    {
        return $this->cinImage;
    }

    public function setCinImage(?string $cinImage): static
    {
        $this->cinImage = $cinImage;
        return $this;
    }

    public function getCinImageProprietaire(): ?string
    {
        return $this->cinImageProprietaire;
    }

    public function setCinImageProprietaire(?string $cinImageProprietaire): static
    {
        $this->cinImageProprietaire = $cinImageProprietaire;
        return $this;
    }

    // Vérifie si les DEUX signataires ont apposé leur signature
    public function isFullySigned(): bool
    {
        return $this->signeLocataire && $this->signeProprietaire;
    }
}