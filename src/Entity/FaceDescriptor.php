<?php
declare(strict_types=1);
namespace App\Entity;

use App\Repository\FaceDescriptorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FaceDescriptorRepository::class)]
#[ORM\Table(name: 'face_descriptor')]
class FaceDescriptor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'faceDescriptors')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $utilisateur;

    /** @var array<float> */
    #[ORM\Column(type: 'json')]
    private array $descriptor = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function setId(int $id): static { $this->id = $id; return $this; }
    public function getUtilisateur(): Utilisateur { return $this->utilisateur; }
    public function setUtilisateur(Utilisateur $v): self { $this->utilisateur = $v; return $this; }
    /** @return array<float> */
    public function getDescriptor(): array { return $this->descriptor; }
    /** @param array<float> $v */
    public function setDescriptor(array $v): self { $this->descriptor = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
