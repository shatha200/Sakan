<?php

namespace App\Entity;

use App\Repository\ReglePenaliteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReglePenaliteRepository::class)]
#[ORM\Table(name: 'regle_penalite')]
#[ORM\HasLifecycleCallbacks]
class ReglePenalite
{

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contrat::class)]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Contrat $contrat = null;

    #[ORM\Column(length: 50)]
    private string $typeRegle = 'RETARD_LOYER';

    #[ORM\Column]
    private int $delaiGraceJours = 5;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $penaliteFixe = '0.00';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $penalitePourcentage = '0.00';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $plafondPourcentage = '10.00';

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateModification = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
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

    public function getContrat(): ?Contrat
    {
        return $this->contrat;
    }

    public function setContrat(?Contrat $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
    }

    public function getTypeRegle(): string
    {
        return $this->typeRegle;
    }

    public function setTypeRegle(string $typeRegle): static
    {
        $this->typeRegle = $typeRegle;
        return $this;
    }

    public function getDelaiGraceJours(): int
    {
        return $this->delaiGraceJours;
    }

    public function setDelaiGraceJours(int $delaiGraceJours): static
    {
        $this->delaiGraceJours = $delaiGraceJours;
        return $this;
    }

    public function getPenaliteFixe(): float
    {
        return (float) $this->penaliteFixe;
    }

    public function setPenaliteFixe(float $penaliteFixe): static
    {
        $this->penaliteFixe = number_format($penaliteFixe, 2, '.', '');
        return $this;
    }

    public function getPenalitePourcentage(): float
    {
        return (float) $this->penalitePourcentage;
    }

    public function setPenalitePourcentage(float $penalitePourcentage): static
    {
        $this->penalitePourcentage = number_format($penalitePourcentage, 2, '.', '');
        return $this;
    }

    public function getPlafondPourcentage(): float
    {
        return (float) $this->plafondPourcentage;
    }

    public function setPlafondPourcentage(float $plafondPourcentage): static
    {
        $this->plafondPourcentage = number_format($plafondPourcentage, 2, '.', '');
        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;
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

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(?\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getDateModification(): ?\DateTimeImmutable
    {
        return $this->dateModification;
    }

    public function setDateModification(?\DateTimeImmutable $dateModification): static
    {
        $this->dateModification = $dateModification;
        return $this;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->dateCreation === null) {
            $this->dateCreation = new \DateTimeImmutable();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->dateModification = new \DateTimeImmutable();
    }

}