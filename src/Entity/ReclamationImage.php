<?php

namespace App\Entity;

use App\Repository\ReclamationImageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReclamationImageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ReclamationImage
{
    /** @var int|null */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore property.unusedType */
    private ?int $id = null;

    /** @var string|resource|null Doctrine BLOB column — PHP resource after fetch, string after base64 */
    #[ORM\Column(name: "imageData", type: Types::BLOB)]
    private mixed $imageData = null;

    #[ORM\Column(name: "fileName", length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(name: "createdAt", type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: "fakeDetectionResult", length: 50, nullable: true)]
    private ?string $fakeDetectionResult = null;

    #[ORM\Column(name: "fakeDetectionDate", type: "datetime_immutable", nullable: true)]
    private ?\DateTimeImmutable $fakeDetectionDate = null;



    #[ORM\ManyToOne(inversedBy: 'images')]
    #[ORM\JoinColumn(name: "reclamationId", nullable: false)]
    private ?Reclamation $reclamationId = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PostLoad]
    public function onPostLoad(): void
    {
        if (is_resource($this->imageData)) {
            $this->imageData = stream_get_contents($this->imageData) ?: null;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string|resource|null
     */
    public function getImageData(): mixed
    {
        return $this->imageData;
    }

    /**
     * @param string|resource|null $imageData
     */
    public function setImageData(mixed $imageData): static
    {
        $this->imageData = $imageData;

        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getFakeDetectionResult(): ?string
    {
        return $this->fakeDetectionResult;
    }

    public function setFakeDetectionResult(?string $fakeDetectionResult): static
    {
        $this->fakeDetectionResult = $fakeDetectionResult;

        return $this;
    }

    public function getFakeDetectionDate(): ?\DateTimeImmutable
    {
        return $this->fakeDetectionDate;
    }

    public function setFakeDetectionDate(?\DateTimeImmutable $fakeDetectionDate): static
    {
        $this->fakeDetectionDate = $fakeDetectionDate;

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

    public function getImageDataUri(): ?string
    {
        if (!$this->imageData) {
            return null;
        }

        $data = $this->imageData;
        if (is_resource($this->imageData)) {
            rewind($this->imageData);
            $data = stream_get_contents($this->imageData);
        }

        if (!is_string($data) || empty($data)) {
            return null;
        }

        // AI Optimization: Resize large images to prevent memory crashes when converting to Base64
        if (function_exists('imagecreatefromstring')) {
            try {
                $img = @imagecreatefromstring($data);
                if ($img) {
                    $width = imagesx($img);
                    $height = imagesy($img);
                    $maxDim = 800;

                    if ($width > $maxDim || $height > $maxDim) {
                        $newImg = imagescale($img, $maxDim);
                        if ($newImg) {
                            ob_start();
                            imagejpeg($newImg, null, 75); // 75% quality JPEG
                            $result = ob_get_clean();
                            if (is_string($result)) {
                                $data = $result;
                            }
                            imagedestroy($newImg);
                        }
                    }
                    imagedestroy($img);
                }
            } catch (\Throwable $t) {
                // Silently fallback to original data if GD fails
            }
        }

        return 'data:image/jpeg;base64,' . base64_encode($data);
    }
}
