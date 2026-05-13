<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'caution_retenue_photo')]
class CautionRetenuePhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'caution_id', type: Types::INTEGER)]
    private ?int $cautionId = null;

    #[ORM\Column(name: 'fichier_url', type: Types::STRING, length: 500)]
    private ?string $fichierUrl = null;

    #[ORM\Column(name: 'nom_fichier', type: Types::STRING, length: 255)]
    private ?string $nomFichier = null;

    #[ORM\Column(name: 'type_dommage', type: Types::STRING, columnDefinition: "ENUM('AUTRE', 'PEINTURE', 'MENUISERIE', 'PLOMBERIE', 'ELECTRICITE', 'SOL', 'NETTOYAGE')", options: ['default' => 'AUTRE'])]
    private string $typeDommage = 'AUTRE';

    #[ORM\Column(name: 'mots_cles_gemini', type: Types::TEXT, nullable: true)]
    private ?string $motsClesGemini = null;

    #[ORM\Column(name: 'analyse_gemini', type: Types::TEXT, nullable: true)]
    private ?string $analyseGemini = null;

    #[ORM\Column(name: 'gravite_gemini', type: Types::STRING, columnDefinition: "ENUM('AUCUN', 'MINEUR', 'MODERE', 'IMPORTANT', 'CRITIQUE')", nullable: true)]
    private ?string $graviteGemini = null;

    #[ORM\Column(name: 'mots_cles_valides', type: Types::TEXT, nullable: true)]
    private ?string $motsClesValides = null;

    #[ORM\Column(name: 'description', type: Types::STRING, length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'montant_estime', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantEstime = null;

    #[ORM\Column(name: 'date_ajout', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $dateAjout = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getCautionId(): ?int
    {
        return $this->cautionId;
    }

    public function setCautionId(int $cautionId): static
    {
        $this->cautionId = $cautionId;

        return $this;
    }

    public function getFichierUrl(): ?string
    {
        return $this->fichierUrl;
    }

    public function setFichierUrl(string $fichierUrl): static
    {
        $this->fichierUrl = $fichierUrl;

        return $this;
    }

    public function getNomFichier(): ?string
    {
        return $this->nomFichier;
    }

    public function setNomFichier(string $nomFichier): static
    {
        $this->nomFichier = $nomFichier;

        return $this;
    }

    public function getTypeDommage(): ?string
    {
        return $this->typeDommage;
    }

    public function setTypeDommage(string $typeDommage): static
    {
        $this->typeDommage = $typeDommage;

        return $this;
    }

    public function getMotsClesGemini(): ?string
    {
        return $this->motsClesGemini;
    }

    public function setMotsClesGemini(?string $motsClesGemini): static
    {
        $this->motsClesGemini = $motsClesGemini;

        return $this;
    }

    public function getAnalyseGemini(): ?string
    {
        return $this->analyseGemini;
    }

    public function setAnalyseGemini(?string $analyseGemini): static
    {
        $this->analyseGemini = $analyseGemini;

        return $this;
    }

    public function getGraviteGemini(): ?string
    {
        return $this->graviteGemini;
    }

    public function setGraviteGemini(?string $graviteGemini): static
    {
        $this->graviteGemini = $graviteGemini;

        return $this;
    }

    public function getMotsClesValides(): ?string
    {
        return $this->motsClesValides;
    }

    public function setMotsClesValides(?string $motsClesValides): static
    {
        $this->motsClesValides = $motsClesValides;

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

    public function getMontantEstime(): ?string
    {
        return $this->montantEstime;
    }

    public function setMontantEstime(?string $montantEstime): static
    {
        $this->montantEstime = $montantEstime;

        return $this;
    }

    public function getDateAjout(): ?\DateTimeInterface
    {
        return $this->dateAjout;
    }

    public function setDateAjout(\DateTimeInterface $dateAjout): static
    {
        $this->dateAjout = $dateAjout;

        return $this;
    }
}