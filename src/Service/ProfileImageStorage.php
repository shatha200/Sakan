<?php

namespace App\Service;

use App\Entity\Utilisateur;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

class ProfileImageStorage
{
    private const RELATIVE_DIRECTORY = 'public/uploads/profile';

    public function __construct(
        KernelInterface $kernel,
    ) {
        $this->projectDir = $kernel->getProjectDir();
    }

    private readonly string $projectDir;

    public function getConfiguredDirectory(): string
    {
        return self::RELATIVE_DIRECTORY;
    }

    public function storeUploadedProfileImage(Utilisateur $user, UploadedFile $uploadedFile, int $maxBytes): void
    {
        if (!$uploadedFile->isValid()) {
            throw new \RuntimeException('Veuillez choisir une image valide.');
        }

        $size = (int) $uploadedFile->getSize();
        if ($size <= 0) {
            throw new \RuntimeException('Le fichier image est vide.');
        }

        if ($size > $maxBytes) {
            throw new \RuntimeException('Image trop grande (max 5 Mo).');
        }

        $bytes = @file_get_contents($uploadedFile->getPathname());
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('Impossible de lire le fichier image.');
        }

        $imageInfo = @getimagesizefromstring($bytes);
        if ($imageInfo === false) {
            throw new \RuntimeException('Fichier image invalide.');
        }

        $mime = strtolower((string) $imageInfo['mime']);
        $extension = $this->extensionFromMime($mime);
        if ($extension === null) {
            throw new \RuntimeException('Format image non supporte. Utilisez PNG, JPG, JPEG, GIF, BMP ou WEBP.');
        }

        $userId = $user->getId();
        if (!is_int($userId) || $userId <= 0) {
            throw new \RuntimeException('Utilisateur invalide pour l enregistrement de la photo.');
        }

        $directory = $this->absoluteDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de creer le repertoire des photos de profil.');
        }

        $this->deleteProfileImage($user);

        $targetPath = $this->buildAbsolutePath($userId, $extension);
        if (!@copy($uploadedFile->getPathname(), $targetPath)) {
            throw new \RuntimeException('Impossible de copier l image dans le repertoire local.');
        }
    }

    public function resolveProfileImagePath(Utilisateur $user): ?string
    {
        $userId = $user->getId();
        if (!is_int($userId) || $userId <= 0) {
            return null;
        }

        $matches = glob($this->absoluteDirectory() . DIRECTORY_SEPARATOR . 'user-' . $userId . '.*');
        if ($matches === false) {
            return null;
        }

        foreach ($matches as $match) {
            if (is_file($match)) {
                return $match;
            }
        }

        return null;
    }

    public function deleteProfileImage(Utilisateur $user): void
    {
        $userId = $user->getId();
        if (!is_int($userId) || $userId <= 0) {
            return;
        }

        $matches = glob($this->absoluteDirectory() . DIRECTORY_SEPARATOR . 'user-' . $userId . '.*');
        if ($matches === false) {
            return;
        }

        foreach ($matches as $match) {
            if (is_file($match)) {
                @unlink($match);
            }
        }
    }

    private function absoluteDirectory(): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_DIRECTORY);
    }

    private function buildAbsolutePath(int $userId, string $extension): string
    {
        return $this->absoluteDirectory() . DIRECTORY_SEPARATOR . 'user-' . $userId . '.' . $extension;
    }

    private function extensionFromMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/webp' => 'webp',
            default => null,
        };
    }
}
