<?php
declare(strict_types=1);
namespace App\Entity;

use App\Repository\WebAuthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebAuthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credential')]
class WebAuthnCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 512, unique: true)]
    private string $credentialId = '';

    #[ORM\Column(type: 'text')]
    private string $publicKeyData = '';

    #[ORM\Column(type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $signCount = 0;

    /** @var array<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $transports = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $deviceName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'webAuthnCredentials')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Utilisateur $utilisateur;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function setId(int $id): static { $this->id = $id; return $this; }
    public function getCredentialId(): string { return $this->credentialId; }
    public function setCredentialId(string $v): self { $this->credentialId = $v; return $this; }
    public function getPublicKeyData(): string { return $this->publicKeyData; }
    public function setPublicKeyData(string $v): self { $this->publicKeyData = $v; return $this; }
    public function getSignCount(): int { return $this->signCount; }
    public function setSignCount(int $v): self { $this->signCount = $v; return $this; }
    /** @return array<string>|null */
    public function getTransports(): ?array { return $this->transports; }
    /** @param array<string>|null $v */
    public function setTransports(?array $v): self { $this->transports = $v; return $this; }
    public function getDeviceName(): ?string { return $this->deviceName; }
    public function setDeviceName(?string $v): self { $this->deviceName = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUtilisateur(): Utilisateur { return $this->utilisateur; }
    public function setUtilisateur(Utilisateur $v): self { $this->utilisateur = $v; return $this; }
}
