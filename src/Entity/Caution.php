<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'caution')]
class Caution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'contrat_id', type: Types::INTEGER)]
    private ?int $contratId = null;

    #[ORM\Column(name: 'montant_initial', type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $montantInitial = null;

    #[ORM\Column(name: 'montant_retention', type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $montantRetention = '0.00';

    #[ORM\Column(name: 'montant_rembourse', type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $montantRembourse = '0.00';

    #[ORM\Column(name: 'statut', type: Types::STRING, columnDefinition: "ENUM('DETENU', 'TOTALEMENT_REMBOURSE', 'PARTIELLEMENT_REMBOURSE', 'RETENU') NOT NULL")]
    private ?string $statut = null;

    #[ORM\Column(name: 'date_remboursement', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateRemboursement = null;

    #[ORM\Column(name: 'description_gemini', type: Types::TEXT, nullable: true)]
    private ?string $descriptionGemini = null;

    #[ORM\Column(name: 'description_retenue', type: Types::TEXT, nullable: true)]
    private ?string $descriptionRetenue = null;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'date_creation', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dateCreation = null;

    #[ORM\Column(name: 'date_modification', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateModification = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getContratId(): ?int
    {
        return $this->contratId;
    }

    public function setContratId(int $contratId): static
    {
        $this->contratId = $contratId;

        return $this;
    }

    public function getMontantInitial(): ?string
    {
        return $this->montantInitial;
    }

    public function setMontantInitial(string $montantInitial): static
    {
        $this->montantInitial = $montantInitial;

        return $this;
    }

    public function getMontantRetention(): ?string
    {
        return $this->montantRetention;
    }

    public function setMontantRetention(string $montantRetention): static
    {
        $this->montantRetention = $montantRetention;

        return $this;
    }

    public function getMontantRembourse(): ?string
    {
        return $this->montantRembourse;
    }

    public function setMontantRembourse(string $montantRembourse): static
    {
        $this->montantRembourse = $montantRembourse;

        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateRemboursement(): ?\DateTimeInterface
    {
        return $this->dateRemboursement;
    }

    public function setDateRemboursement(?\DateTimeInterface $dateRemboursement): static
    {
        $this->dateRemboursement = $dateRemboursement;

        return $this;
    }

    public function getDescriptionGemini(): ?string
    {
        return $this->descriptionGemini;
    }

    public function setDescriptionGemini(?string $descriptionGemini): static
    {
        $this->descriptionGemini = $descriptionGemini;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeInterface
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeInterface $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getDateModification(): ?\DateTimeInterface
    {
        return $this->dateModification;
    }

    public function setDateModification(?\DateTimeInterface $dateModification): static
    {
        $this->dateModification = $dateModification;

        return $this;
    }

    // --- Utilitaires demandés ---

    public function getMontantDisponible(): string
    {
        $initial = (float) $this->montantInitial;
        $retention = (float) $this->montantRetention;
        $rembourse = (float) $this->montantRembourse;

        return number_format($initial - $retention - $rembourse, 2, '.', '');
    }

    public function getMontantARembourser(): string
    {
        return $this->getMontantDisponible();
    }

    public function getJoursRestants(\DateTimeInterface $dateFin): int
    {
        // jours avant dateFin + 2 mois
        $dateFinCloned = \DateTime::createFromInterface($dateFin);
        $dateLimite = $dateFinCloned->modify('+2 months');
        $maintenant = new \DateTime();
        
        $diff = $maintenant->diff($dateLimite);
        
        if ($maintenant > $dateLimite) {
            return -((int) $diff->days);
        }
        
        return (int) $diff->days;
    }

    public function isEnRetard(\DateTimeInterface $dateFin): bool
    {
        // dateFin < today
        $maintenant = new \DateTime();
        // Optionnel : ne comparer que les dates (sans l'heure)
        $maintenant->setTime(0, 0, 0);
        $dateFinMutable = \DateTime::createFromInterface($dateFin);
        $dateFinClone = $dateFinMutable->setTime(0, 0, 0);

        return $dateFinClone < $maintenant;
    }
}