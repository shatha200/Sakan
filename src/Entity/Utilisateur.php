<?php

namespace App\Entity;

use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(type: 'string', length: 100, unique: true, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'motDePasse', type: 'string', length: 255, nullable: true)]
    private ?string $motDePasse = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $statut = null;

    #[ORM\Column(name: 'dateInscription', type: 'date', nullable: true)]
    private ?\DateTimeInterface $dateInscription = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $google_sub = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $telephone_e164 = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $photo_profil = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $two_factor_enabled = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $telephone_verified = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $signature = null;

    /** @var Collection<int, WebAuthnCredential> */
    #[ORM\OneToMany(
        mappedBy: 'utilisateur',
        targetEntity: WebAuthnCredential::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $webAuthnCredentials;

    /** @var Collection<int, FaceDescriptor> */
    #[ORM\OneToMany(
        mappedBy: 'utilisateur',
        targetEntity: FaceDescriptor::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $faceDescriptors;

    public function __construct()
    {
        $this->webAuthnCredentials = new ArrayCollection();
        $this->faceDescriptors     = new ArrayCollection();
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

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): self
    {
        $this->signature = $signature;
        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getMotDePasse(): ?string
    {
        return $this->motDePasse;
    }

    public function setMotDePasse(?string $motDePasse): self
    {
        $this->motDePasse = $motDePasse;

        return $this;
    }

    public function getRoleName(): string
    {
        return strtoupper(trim((string) $this->role));
    }

    public function setRoleName(string $role): self
    {
        $this->role = strtoupper(trim($role));

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): self
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getStatut(): ?string
    {
        return $this->statut;
    }

    public function setStatut(?string $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateInscription(): ?\DateTimeInterface
    {
        return $this->dateInscription;
    }

    public function setDateInscription(?\DateTimeInterface $dateInscription): self
    {
        $this->dateInscription = $dateInscription;

        return $this;
    }

    public function getGoogleSub(): ?string
    {
        return $this->google_sub;
    }

    public function setGoogleSub(?string $googleSub): self
    {
        $this->google_sub = $googleSub;

        return $this;
    }

    public function getTelephoneE164(): ?string
    {
        return $this->telephone_e164;
    }

    public function setTelephoneE164(?string $telephoneE164): self
    {
        $this->telephone_e164 = $telephoneE164;

        return $this;
    }

    public function isTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled;
    }

    public function setTwoFactorEnabled(bool $enabled): self
    {
        $this->two_factor_enabled = $enabled;

        return $this;
    }

    public function isTelephoneVerified(): bool
    {
        return $this->telephone_verified;
    }

    public function setTelephoneVerified(bool $verified): self
    {
        $this->telephone_verified = $verified;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return strtolower(trim((string) $this->email)) ?: 'anonymous';
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        $domainRole = $this->getRoleName();
        if ($domainRole === 'ADMIN') {
            $roles[] = 'ROLE_ADMIN';
        } elseif ($domainRole === 'PROPRIETAIRE') {
            $roles[] = 'ROLE_PROPRIETAIRE';
        } elseif ($domainRole === 'LOCATAIRE') {
            $roles[] = 'ROLE_LOCATAIRE';
        }

        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void
    {
    }

    public function getPassword(): ?string
    {
        return $this->motDePasse;
    }

    public function getPhotoProfil(): ?string
    {
        if (is_resource($this->photo_profil)) {
            $this->photo_profil = stream_get_contents($this->photo_profil);
        }
        return $this->photo_profil;
    }

    public function setPhotoProfil(?string $photoProfil): self
    {
        $this->photo_profil = $photoProfil;

        return $this;
    }

    /** @return Collection<int, WebAuthnCredential> */
    public function getWebAuthnCredentials(): Collection
    {
        return $this->webAuthnCredentials;
    }

    public function hasWebAuthn(): bool
    {
        return !$this->webAuthnCredentials->isEmpty();
    }

    /** @return Collection<int, FaceDescriptor> */
    public function getFaceDescriptors(): Collection
    {
        return $this->faceDescriptors;
    }

    public function hasFaceDescriptor(): bool
    {
        return !$this->faceDescriptors->isEmpty();
    }
}
