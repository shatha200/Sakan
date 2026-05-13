<?php

namespace App\Service;

/**
 * Comparaison de signatures « intelligente » (projet académique) :
 * recadrage sur l'encre + coefficient de Dice sur masques binaires.
 * L'ancienne méthode (MSE sur tous les pixels) était faussée par le fond blanc.
 */
class SignatureService
{
    private const COMPARE_W = 150;
    private const COMPARE_H = 75;
    /** Pixels plus sombres que ce seuil = encre (fond blanc ~255). */
    private const INK_THRESHOLD = 200;
    /** Seuil abaissé : deux tracés manuscrits ne sont jamais identiques pixel à pixel. */
    private const SIMILARITY_THRESHOLD = 50.0;
    private const MAX_ATTEMPTS = 3;
    /** Marge autour de la boîte englobante (fraction de la largeur/hauteur du trace). */
    private const BBOX_PADDING_RATIO = 0.08;

    private function ensureGdExtension(): void
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD extension is required for signature comparison functionality. Please enable the GD extension in your php.ini file.');
        }
    }

    /**
     * Compare deux signatures et retourne un score de similarité (0–100).
     * Basé sur le coefficient de Dice entre masques d'encre normalisés (recadrage + redimensionnement).
     *
     * @param string $base64A Première image (data URL ou base64)
     * @param string $base64B Deuxième image (data URL ou base64)
     */
    public function compare(string $base64A, string $base64B): float
    {
        $this->ensureGdExtension();

        if ($base64A === '' || $base64B === '') {
            return 0.0;
        }

        $imgA = $this->loadFromBase64($base64A);
        $imgB = $this->loadFromBase64($base64B);

        if ($imgA === null || $imgB === null) {
            throw new \RuntimeException('Failed to decode one or both signatures. Images may be corrupted.');
        }

        try {
            $flatA = $this->flattenOnWhite($imgA);
            $flatB = $this->flattenOnWhite($imgB);

            $maskA = $this->buildNormalizedInkMask($flatA);
            $maskB = $this->buildNormalizedInkMask($flatB);

            imagedestroy($flatA);
            imagedestroy($flatB);

            if ($maskA === null || $maskB === null) {
                return 0.0;
            }

            $score = $this->diceCoefficient($maskA, $maskB);

            return round(max(0.0, min(100.0, $score)), 1);
        } catch (\Exception $e) {
            throw new \RuntimeException('Error during signature comparison: ' . $e->getMessage(), 0, $e);
        }
    }

    public function isValid(float $score): bool
    {
        return $score >= self::SIMILARITY_THRESHOLD;
    }

    public function getThreshold(): float
    {
        return self::SIMILARITY_THRESHOLD;
    }

    public function getMaxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /**
     * Aplatit sur fond blanc (PNG transparent / canal alpha).
     */
    private function flattenOnWhite(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);

        $dst = imagecreatetruecolor($w, $h);
        if ($dst === false) {
            imagedestroy($src);
            throw new \RuntimeException('Failed to create image buffer.');
        }

        $white = imagecolorallocate($dst, 255, 255, 255);
        if ($white !== false) {
            imagefill($dst, 0, 0, $white);
        }
        imagealphablending($dst, true);
        imagesavealpha($dst, false);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        return $dst;
    }

    /**
     * Recadre sur l'encre, redimensionne, retourne matrice binaire COMPARE_W × COMPARE_H.
     *
     * @return array<int, array<int, int>>|null
     */
    private function buildNormalizedInkMask(\GdImage $img): ?array
    {
        $bbox = $this->findInkBoundingBox($img);
        if ($bbox === null) {
            imagedestroy($img);

            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        $padX = (int) max(2, ceil($bbox['w'] * self::BBOX_PADDING_RATIO));
        $padY = (int) max(2, ceil($bbox['h'] * self::BBOX_PADDING_RATIO));

        $x0 = max(0, $bbox['x'] - $padX);
        $y0 = max(0, $bbox['y'] - $padY);
        $x1 = min($w - 1, $bbox['x'] + $bbox['w'] + $padX - 1);
        $y1 = min($h - 1, $bbox['y'] + $bbox['h'] + $padY - 1);

        $cw = $x1 - $x0 + 1;
        $ch = $y1 - $y0 + 1;
        if ($cw < 1 || $ch < 1) {
            imagedestroy($img);

            return null;
        }

        $cropped = imagecrop($img, ['x' => $x0, 'y' => $y0, 'width' => $cw, 'height' => $ch]);
        imagedestroy($img);

        if ($cropped === false) {
            return null;
        }

        $dst = imagecreatetruecolor(self::COMPARE_W, self::COMPARE_H);
        if ($dst === false) {
            imagedestroy($cropped);

            return null;
        }

        $white = imagecolorallocate($dst, 255, 255, 255);
        if ($white !== false) {
            imagefill($dst, 0, 0, $white);
        }
        imagecopyresampled(
            $dst,
            $cropped,
            0,
            0,
            0,
            0,
            self::COMPARE_W,
            self::COMPARE_H,
            imagesx($cropped),
            imagesy($cropped)
        );
        imagedestroy($cropped);

        $mask = [];
        for ($y = 0; $y < self::COMPARE_H; $y++) {
            $mask[$y] = [];
            for ($x = 0; $x < self::COMPARE_W; $x++) {
                $c = imagecolorat($dst, $x, $y);
                if ($c === false) {
                    $mask[$y][$x] = 0;
                } else {
                    $mask[$y][$x] = $this->toGray($c) < self::INK_THRESHOLD ? 1 : 0;
                }
            }
        }
        imagedestroy($dst);

        return $mask;
    }

    /**
     * @return array{x:int,y:int,w:int,h:int}|null
     */
    private function findInkBoundingBox(\GdImage $img): ?array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                if ($c === false) {
                    continue;
                }
                if ($this->toGray($c) < self::INK_THRESHOLD) {
                    if ($x < $minX) {
                        $minX = $x;
                    }
                    if ($y < $minY) {
                        $minY = $y;
                    }
                    if ($x > $maxX) {
                        $maxX = $x;
                    }
                    if ($y > $maxY) {
                        $maxY = $y;
                    }
                }
            }
        }

        if ($maxX < 0) {
            return null;
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'w' => $maxX - $minX + 1,
            'h' => $maxY - $minY + 1,
        ];
    }

    /**
     * Coefficient de Dice sur masques binaires alignés (0–100).
     * Formule : 2 × |A ∩ B| / (|A| + |B|)
     *
     * @param array<int, array<int, int>> $maskA
     * @param array<int, array<int, int>> $maskB
     */
    private function diceCoefficient(array $maskA, array $maskB): float
    {
        $inter = 0;
        $sumA = 0;
        $sumB = 0;

        for ($y = 0; $y < self::COMPARE_H; $y++) {
            for ($x = 0; $x < self::COMPARE_W; $x++) {
                $a = $maskA[$y][$x] ?? 0;
                $b = $maskB[$y][$x] ?? 0;
                if ($a) {
                    ++$sumA;
                }
                if ($b) {
                    ++$sumB;
                }
                if ($a && $b) {
                    ++$inter;
                }
            }
        }

        $den = $sumA + $sumB;
        if ($den === 0) {
            return 0.0;
        }

        return (200.0 * $inter) / $den;
    }

    private function toGray(int $color): int
    {
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;

        return (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
    }

    private function loadFromBase64(string $base64): ?\GdImage
    {
        if ($base64 === '') {
            return null;
        }

        if (str_contains($base64, ',')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $data = base64_decode($base64, true);
        if ($data === false) {
            return null;
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('PHP function imagecreatefromstring is not available. GD library may not be properly installed.');
        }

        try {
            $img = imagecreatefromstring($data);

            return $img instanceof \GdImage ? $img : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
