<?php

namespace App\Controller;

use App\Service\AiProService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/ai', name: 'api_ai_')]
class AiApiController extends AbstractController
{
    #[Route('/generate-description', name: 'description', methods: ['POST'])]
    public function generateDescription(Request $request, AiProService $aiService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $type = $data['type'] ?? 'problème général';
        $currentText = $data['currentText'] ?? '';
        
        $description = $aiService->generateDescription($type, $currentText);
        
        return $this->json(['text' => $description]);
    }

    #[Route('/generate-response', name: 'response', methods: ['POST'])]
    public function generateResponse(Request $request, AiProService $aiService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $description = $data['description'] ?? '';
        $type = $data['type'] ?? 'réclamation';
        $currentText = $data['currentText'] ?? '';
        
        $response = $aiService->generateResponse($description, $type, $currentText);
        
        return $this->json(['text' => $response]);
    }
}
