<?php

namespace App\Controller;

use App\Service\OcrCINService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/ocr')]
class OcrController extends AbstractController
{
    #[Route('/extract-cin', name: 'api_ocr_extract_cin', methods: ['POST'])]
    public function extractCIN(Request $request, OcrCINService $ocrService): JsonResponse
    {
        $file = $request->files->get('cin_file');
        
        if (!$file) {
            return $this->json([
                'success' => false,
                'error' => 'Aucun fichier fourni',
                'cin' => null
            ], 400);
        }

        $expected = $request->request->get('expected_cin');
        $result = $ocrService->extractCINFromImage($file, is_string($expected) ? $expected : null);
        
        return $this->json($result);
    }
}
