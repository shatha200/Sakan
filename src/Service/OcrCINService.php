<?php

namespace App\Service;

use Symfony\Component\Process\Process;

/**
 * CIN tunisien : 8 chiffres — lecture prioritaire des 8 premiers chiffres extraits du texte OCR,
 * puis vérification avec le champ saisi (le numéro saisi doit apparaître tel quel dans la chaîne de chiffres lus).
 */
class OcrCINService
{
    private string $uploadDir;

    public function __construct(
        private string $projectDir,
        private string $googleVisionApiKey = '',
    ) {
        $this->uploadDir = $this->projectDir . '/public/uploads/cin';

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function extractCINFromImage(\Symfony\Component\HttpFoundation\File\UploadedFile $file, ?string $expectedCin = null): array
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
        $ext = strtolower($file->guessExtension() ?? '');
        $mimeType = $file->getMimeType() ?? '';

        if (!in_array($mimeType, $allowedMimes, true) || !in_array($ext, $allowedExts, true)) {
            return $this->err('Format non supporté (JPG, PNG, WEBP, GIF, PDF)');
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->err('Fichier trop lourd (max 5 MB)');
        }

        $filename = uniqid('cin_') . '.' . $ext;
        $filepath = $this->uploadDir . '/' . $filename;
        $file->move($this->uploadDir, $filename);

        try {
            $text = $this->performOCR($filepath, $ext);
            $expectedDigits = $expectedCin !== null && $expectedCin !== ''
                ? (string) preg_replace('/\D/', '', $expectedCin)
                : '';

            $digitsVariants = $this->buildDigitStreams($text);
            $candidates = $this->buildCandidates($digitsVariants);

            $digitsPrimary = $digitsVariants[0] ?? '';
            $firstEight = strlen($digitsPrimary) >= 8 ? substr($digitsPrimary, 0, 8) : null;

            $cin = null;
            $matchesExpected = null;

            // 1) Vérification stricte : les 8 chiffres saisis apparaissent tels quels dans le flux OCR (méthode la plus fiable)
            if (strlen($expectedDigits) === 8 && $digitsPrimary !== '') {
                if (str_contains($digitsPrimary, $expectedDigits)) {
                    $cin = $expectedDigits;
                    $matchesExpected = true;
                }
            }

            // 2) Sinon : accepter si présent dans une variante (OCR corrigé)
            if ($cin === null && strlen($expectedDigits) === 8) {
                foreach ($digitsVariants as $stream) {
                    if ($stream !== '' && str_contains($stream, $expectedDigits)) {
                        $cin = $expectedDigits;
                        $matchesExpected = true;
                        break;
                    }
                }
            }

            // 3) Sinon : prendre les 8 premiers chiffres lus (règle demandée pour la CIN)
            if ($cin === null && $firstEight !== null && preg_match('/^\d{8}$/', $firstEight)) {
                $cin = $firstEight;
                if (strlen($expectedDigits) === 8) {
                    $matchesExpected = ($expectedDigits === $cin);
                }
            }

            // 4) Dernier recours : meilleur candidat fenêtre glissante
            if ($cin === null && $candidates !== []) {
                $cin = $candidates[0];
                if (strlen($expectedDigits) === 8) {
                    $matchesExpected = ($expectedDigits === $cin);
                }
            }

            @unlink($filepath);

            return [
                'success' => $cin !== null,
                'error' => $cin === null ? 'Aucune suite de 8 chiffres lisible. Photo plus nette du recto, bonne lumière, ou vérifiez la clé Google Vision / Tesseract.' : null,
                'cin' => $cin,
                'candidates' => $candidates,
                'raw_text' => $text !== '' ? $text : null,
                'matches_expected' => $matchesExpected,
                'digits_read' => $digitsPrimary !== '' ? $digitsPrimary : null,
            ];
        } catch (\Exception $e) {
            @unlink($filepath);

            return [
                'success' => false,
                'error' => 'Erreur OCR: ' . $e->getMessage(),
                'cin' => null,
                'candidates' => [],
                'raw_text' => null,
                'matches_expected' => null,
                'digits_read' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function err(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'cin' => null,
            'candidates' => [],
            'raw_text' => null,
            'matches_expected' => null,
            'digits_read' => null,
        ];
    }

    private function performOCR(string $filepath, string $ext): string
    {
        $parts = [];
        $key = trim($this->googleVisionApiKey);

        if ($key !== '') {
            try {
                $parts[] = $this->performGoogleVisionOCR($filepath, $key);
            } catch (\Throwable $e) {
                // Tesseract en secours
            }
        }

        if ($ext !== 'pdf') {
            $tesseractText = $this->performTesseractOCR($filepath);
            if ($tesseractText !== '') {
                $parts[] = $tesseractText;
            }
        }

        return trim(implode("\n", array_filter($parts)));
    }

    /**
     * DOCUMENT_TEXT_DETECTION + fallback TEXT_DETECTION pour cartes d'identité.
     */
    private function performGoogleVisionOCR(string $imagePath, string $apiKey): string
    {
        $imageData = base64_encode((string) file_get_contents($imagePath));

        foreach (['DOCUMENT_TEXT_DETECTION', 'TEXT_DETECTION'] as $featureType) {
            $requestBody = [
                'requests' => [
                    [
                        'image' => ['content' => $imageData],
                        'features' => [
                            ['type' => $featureType, 'maxResults' => 50],
                        ],
                    ],
                ],
            ];

            $ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key=' . rawurlencode($apiKey));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => (string)(json_encode($requestBody) ?: '{}'),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 45,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                continue;
            }

            $data = json_decode(is_string($response) ? $response : '', true);
            if (!is_array($data)) {
                continue;
            }

            if (!empty($data['responses'][0]['error'])) {
                $msg = $data['responses'][0]['error']['message'] ?? 'Erreur Vision API';
                throw new \RuntimeException($msg);
            }

            $resp = $data['responses'][0] ?? [];

            if (!empty($resp['fullTextAnnotation']['text'])) {
                return (string) $resp['fullTextAnnotation']['text'];
            }

            if (!empty($resp['textAnnotations'][0]['description'])) {
                return (string) $resp['textAnnotations'][0]['description'];
            }
        }

        return '';
    }

    private function performTesseractOCR(string $filepath): string
    {
        $binary = $this->findTesseractBinary();
        if ($binary === null) {
            return '';
        }

        $chunks = [];
        foreach ([6, 11, 3] as $psm) {
            try {
                $process = new Process([
                    $binary,
                    $filepath,
                    'stdout',
                    '-l',
                    'fra+eng',
                    '--oem',
                    '3',
                    '--psm',
                    (string) $psm,
                ]);
                $process->setTimeout(90);
                $process->run();
                if ($process->isSuccessful()) {
                    $out = trim($process->getOutput());
                    if ($out !== '') {
                        $chunks[] = $out;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return trim(implode("\n", $chunks));
    }

    private function findTesseractBinary(): ?string
    {
        foreach (['tesseract', 'tesseract.exe'] as $name) {
            try {
                $p = new Process([$name, '--version']);
                $p->setTimeout(5);
                $p->run();
                if ($p->isSuccessful()) {
                    return $name;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Chiffres arabes → latins + variantes OCR courantes.
     *
     * @return string[]
     */
    private function buildDigitStreams(string $text): array
    {
        $text = $this->arabicIndicToWestern($text);
        $streams = [];

        $streams[] = preg_replace('/\D/', '', $text);

        $fixed = str_replace(['O', 'o', 'Q', 'Ø', 'D', 'Ο', 'ο'], ['0', '0', '0', '0', '0', '0', '0'], $text);
        $fixed = str_replace(['l', 'I', '|', 'i', '!', 'ı', 'Ι'], ['1', '1', '1', '1', '1', '1', '1'], $fixed);
        $streams[] = preg_replace('/\D/', '', $fixed);

        return array_values(array_unique(array_filter($streams)));
    }

    private function arabicIndicToWestern(string $s): string
    {
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($arabic, $western, $s);
    }

    /**
     * Candidats : d'abord les 8 premiers chiffres de chaque flux, puis fenêtre glissante (sans filtre date agressif).
     *
     * @param string[] $digitsVariants
     *
     * @return string[]
     */
    private function buildCandidates(array $digitsVariants): array
    {
        $ordered = [];

        foreach ($digitsVariants as $digitsOnly) {
            if (strlen($digitsOnly) >= 8) {
                $ordered[] = substr($digitsOnly, 0, 8);
            }
        }

        foreach ($digitsVariants as $digitsOnly) {
            $len = strlen($digitsOnly);
            for ($i = 0; $i <= $len - 8; $i++) {
                $chunk = substr($digitsOnly, $i, 8);
                if (ctype_digit($chunk)) {
                    $ordered[] = $chunk;
                }
            }
        }

        return array_values(array_unique($ordered));
    }

    public function validateCIN(string $cin): bool
    {
        return preg_match('/^\d{8}$/', $cin) === 1;
    }
}
