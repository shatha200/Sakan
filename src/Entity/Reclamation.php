<?php

namespace App\Entity;

use App\Repository\ReclamationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReclamationRepository::class)]
class Reclamation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 255)]
    private ?string $statut = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: "type_id", nullable: false)]
    private ?ReclamationType $typeId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $type_autre = null;

    #[ORM\Column]
    private ?int $locataire_id = null;

    #[ORM\ManyToOne(targetEntity: Contrat::class)]
    #[ORM\JoinColumn(name: 'contrat_id', referencedColumnName: 'id', nullable: true)]
    private ?Contrat $contrat = null;

    /** @var Collection<int, ReclamationImage> */
    #[ORM\OneToMany(
        mappedBy: 'reclamationId',
        targetEntity: ReclamationImage::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $images;

    #[ORM\OneToOne(mappedBy: 'reclamationId', targetEntity: Traitement::class, cascade: ['persist', 'remove'])]
    private ?Traitement $traitement = null;

   

    public function __construct()
    {
        $this->images = new ArrayCollection();
        $this->date = new \DateTimeImmutable();
        $this->statut = 'EN_ATTENTE';
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

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

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date instanceof \DateTimeImmutable
            ? $date
            : \DateTimeImmutable::createFromInterface($date);

        return $this;
    }

    public function getTypeId(): ?ReclamationType
    {
        return $this->typeId;
    }

    public function setTypeId(?ReclamationType $typeId): static
    {
        $this->typeId = $typeId;

        return $this;
    }

    public function getTypeAutre(): ?string
    {
        return $this->type_autre;
    }

    public function setTypeAutre(?string $type_autre): static
    {
        $this->type_autre = $type_autre;

        return $this;
    }

    public function getLocataireId(): ?int
    {
        return $this->locataire_id;
    }

    public function setLocataireId(int $locataire_id): static
    {
        $this->locataire_id = $locataire_id;

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

    public function getContratId(): ?int
    {
        return $this->contrat ? $this->contrat->getId() : null;
    }

    public function setContratId(int $contrat_id): static
    {
        return $this;
    }

    /**
     * @return Collection<int, ReclamationImage>
     */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(ReclamationImage $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setReclamationId($this);
        }

        return $this;
    }

    public function removeImage(ReclamationImage $image): static
    {
        if ($this->images->removeElement($image)) {
            // set the owning side to null (unless already changed)
            if ($image->getReclamationId() === $this) {
                $image->setReclamationId(null);
            }
        }

        return $this;
    }

    public function getTraitement(): ?Traitement
    {
        return $this->traitement;
    }

    public function setTraitement(?Traitement $traitement): static
    {
        // unset the owning side of the relation if necessary
        if ($traitement === null && $this->traitement !== null) {
            $this->traitement->setReclamationId(null);
        }

        // set the owning side of the relation if necessary
        if ($traitement !== null && $traitement->getReclamationId() !== $this) {
            $traitement->setReclamationId($this);
        }

        $this->traitement = $traitement;

        return $this;
    }

   
  
}
