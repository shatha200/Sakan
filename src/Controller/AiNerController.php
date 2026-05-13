<?php
namespace App\Controller;

use App\Entity\Reclamation;
use App\Service\NerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class AiNerController extends AbstractController
{
    #[Route('/api/ai/analyze-reclamation/{id}', name: 'api_ai_analyze_reclamation', methods: ['POST'])]
    public function analyze(Reclamation $reclamation, NerService $nerService): JsonResponse
    {
        // On analyse le texte brut de la description
        $results = $nerService->extractEntities((string)$reclamation->getDescription());

        // On ne stocke RIEN en base de données, on renvoie juste les infos extraites
        return $this->json($results);
    }
}
