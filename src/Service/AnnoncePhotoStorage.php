<?php

namespace App\Service;

use App\Entity\Annonce;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AnnoncePhotoStorage
{
    private string $uploadDir;
    private string $publicPath;

    public function __construct(ParameterBagInterface $params)
    {
        $projectDir = $params->get('kernel.project_dir');
        $this->uploadDir = (is_string($projectDir) ? $projectDir : '') . '/public/uploads/annonces';
        $this->publicPath = '/uploads/annonces';
        
        // Créer le dossier s'il n'existe pas
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Stocke une photo uploadée pour une annonce
     */
    public function storePhoto(UploadedFile $file, int $annonceId): string
    {
        $this->validateImage($file);
        
        // Générer un nom unique
        $extension = $file->guessExtension() ?: 'jpg';
        $filename = sprintf('annonce_%d_%s.%s', 
            $annonceId, 
            uniqid(), 
            $extension
        );
        
        // Déplacer le fichier
        $file->move($this->uploadDir, $filename);
        
        return $this->publicPath . '/' . $filename;
    }

    /**
     * Supprime une photo existante
     */
    public function deletePhoto(?string $photoPath): void
    {
        if (!$photoPath) {
            return;
        }
        
        $fullPath = $this->getFullPath($photoPath);
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Remplace une photo existante par une nouvelle
     */
    public function replacePhoto(?string $oldPhotoPath, UploadedFile $newFile, int $annonceId): string
    {
        $this->deletePhoto($oldPhotoPath);
        return $this->storePhoto($newFile, $annonceId);
    }

    /**
     * Retourne l'URL complète d'une photo
     */
    public function getPhotoUrl(?string $photoPath): ?string
    {
        if (!$photoPath) {
            return null;
        }
        return $photoPath;
    }

    /**
     * Valide que le fichier est une image acceptable
     */
    private function validateImage(UploadedFile $file): void
    {
        $mimeType = $file->getMimeType();
        $validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        if (!in_array($mimeType, $validTypes)) {
            throw new \RuntimeException('Format d\'image non valide. Formats acceptés: JPEG, PNG, GIF, WebP');
        }
        
        // Limite de 5 Mo
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw new \RuntimeException('L\'image ne doit pas dépasser 5 Mo');
        }
    }

    /**
     * Retourne le chemin complet sur le système de fichiers
     */
    private function getFullPath(string $photoPath): string
    {
        // Enlever le /uploads/annonces/ du début pour obtenir le nom de fichier
        $filename = basename($photoPath);
        return $this->uploadDir . '/' . $filename;
    }
}
