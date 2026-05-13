<?php

namespace App\Tests\Service;

use App\Service\AnnoncePhotoStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tests unitaires pour AnnoncePhotoStorage.
 *
 * Couvre les règles métier de validation d'image :
 *   - Format accepté : JPEG, PNG, GIF, WebP
 *   - Taille maximale : 5 Mo
 *
 * 100 % isolé : pas de base de données, pas de Kernel, pas de fichiers réels.
 */
class AnnoncePhotoStorageTest extends TestCase
{
    private function makeStorage(): AnnoncePhotoStorage
    {
        $params = $this->createMock(ParameterBagInterface::class);
        $params->method('get')
               ->with('kernel.project_dir')
               ->willReturn(sys_get_temp_dir());

        return new AnnoncePhotoStorage($params);
    }

    private function callValidateImage(AnnoncePhotoStorage $storage, UploadedFile $file): void
    {
        $ref = new \ReflectionMethod($storage, 'validateImage');
        $ref->setAccessible(true);
        $ref->invoke($storage, $file);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 1 : Image JPEG valide de 1 Mo (cas nominal)
    // ────────────────────────────────────────────────────────────

    public function testImageValideJpeg(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(1 * 1024 * 1024); // 1 Mo

        $storage = $this->makeStorage();
        $this->callValidateImage($storage, $file);

        // Pas d'exception levée = test réussi
        $this->assertTrue(true);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 2 : Image PNG valide de 4 Mo (limite haute acceptée)
    // ────────────────────────────────────────────────────────────

    public function testImageValidePngLimite(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getSize')->willReturn(4 * 1024 * 1024); // 4 Mo

        $storage = $this->makeStorage();
        $this->callValidateImage($storage, $file);

        $this->assertTrue(true);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 3 : Format invalide (PDF)  →  RuntimeException
    // ────────────────────────────────────────────────────────────

    public function testFormatInvalide(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Format d'image non valide");

        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('application/pdf');
        $file->method('getSize')->willReturn(1000);

        $this->callValidateImage($this->makeStorage(), $file);
    }

    // ────────────────────────────────────────────────────────────
    // TEST 4 : Taille trop grande (6 Mo)  →  RuntimeException
    // ────────────────────────────────────────────────────────────

    public function testTailleTropGrande(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("ne doit pas dépasser 5 Mo");

        $file = $this->createMock(UploadedFile::class);
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getSize')->willReturn(6 * 1024 * 1024); // 6 Mo

        $this->callValidateImage($this->makeStorage(), $file);
    }
}
