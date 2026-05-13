<?php

namespace App\Controller;

use App\Service\OcrCINService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class OcrTestController extends AbstractController
{
    #[Route('/test-ocr', name: 'api_test_ocr', methods: ['GET', 'POST'])]
    public function testOcr(Request $request, OcrCINService $ocrService): JsonResponse
    {
        // Test simple sans fichier pour vérifier que le service fonctionne
        $testText = "CIN: 12345678";
        $cin = $this->extractCINFromText($testText);
        
        return $this->json([
            'success' => true,
            'message' => 'OCR Test endpoint working',
            'test_cin_extracted' => $cin,
            'service_available' => true
        ]);
    }
    
    private function extractCINFromText(string $text): ?string
    {
        if (preg_match('/\b(\d{8})\b/', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    #[Route('/extract-cin-public', name: 'api_extract_cin_public', methods: ['POST'])]
    public function extractCINPublic(Request $request, OcrCINService $ocrService): JsonResponse
    {
        try {
            $file = $request->files->get('cin_file');
            
            if (!$file) {
                return $this->json([
                    'success' => false,
                    'error' => 'Aucun fichier fourni',
                    'cin' => null
                ], 400);
            }

            $expected = $request->request->get('expected_cin');
            $expected = is_string($expected) ? $expected : null;

            $result = $ocrService->extractCINFromImage($file, $expected);
            
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Erreur serveur: ' . $e->getMessage(),
                'cin' => null
            ], 500);
        }
    }
}
