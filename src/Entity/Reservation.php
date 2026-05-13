<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(name: 'annonceId', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotBlank]
    private ?Annonce $annonce = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'locataireId', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotBlank]
    private ?Utilisateur $locataire = null;

    #[ORM\Column(name: 'dateDebut', type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank]
    private ?\DateTimeInterface $dateDebut = null;

    #[ORM\Column(name: 'dateFin', type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank]
    private ?\DateTimeInterface $dateFin = null;

    #[ORM\Column(length: 50)]
    private string $statut = 'En attente';

    /** @var Collection<int, Visite> */
    #[ORM\OneToMany(mappedBy: 'reservation', targetEntity: Visite::class)]
    private Collection $visites;

    public function __construct()
    {
        $this->visites = new ArrayCollection();
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

    public function getDateDebut(): ?\DateTimeInterface
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeInterface $dateDebut): static
    {
        $this->dateDebut = $dateDebut;
        return $this;
    }

    public function getDateFin(): ?\DateTimeInterface
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeInterface $dateFin): static
    {
        $this->dateFin = $dateFin;
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

    /**
     * @return Collection<int, Visite>
     */
    public function getVisites(): Collection
    {
        return $this->visites;
    }

    public function __toString(): string
    {
        return 'Réservation #' . $this->id;
    }

    #[Assert\Callback]
    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->dateDebut && $this->dateFin) {
            if ($this->dateFin <= $this->dateDebut) {
                $context->buildViolation('La date de fin doit être postérieure à la date de début.')
                    ->atPath('dateFin')
                    ->addViolation();
            }

            $now = new \DateTime('today');
            if ($this->dateDebut < $now) {
                // Optionnel : avertissement si la date est dans le passé
                // $context->buildViolation('La date de début ne peut pas être dans le passé.')
                //     ->atPath('dateDebut')
                //     ->addViolation();
            }
        }
    }
}
