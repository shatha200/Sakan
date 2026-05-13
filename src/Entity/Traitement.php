<?php

namespace App\Entity;

use App\Repository\TraitementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TraitementRepository::class)]
class Traitement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $reponse = null;

    #[ORM\Column(name: "dateTraitement", type: Types::DATE_MUTABLE)]
    private ?\DateTime $dateTraitement = null;

    #[ORM\OneToOne(targetEntity: Reclamation::class, inversedBy: 'traitement')]
    #[ORM\JoinColumn(name: "reclamationId", referencedColumnName: "id", nullable: false)]
    private ?Reclamation $reclamationId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getReponse(): ?string
    {
        return $this->reponse;
    }

    public function setReponse(string $reponse): static
    {
        $this->reponse = $reponse;

        return $this;
    }

    public function getDateTraitement(): ?\DateTime
    {
        return $this->dateTraitement;
    }

    public function setDateTraitement(\DateTime $dateTraitement): static
    {
        $this->dateTraitement = $dateTraitement;

        return $this;
    }

    public function getReclamationId(): ?Reclamation
    {
        return $this->reclamationId;
    }

    public function setReclamationId(?Reclamation $reclamationId): static
    {
        $this->reclamationId = $reclamationId;

        return $this;
    }
}
