<?php

namespace App\Controller;

use App\Service\ChatbotService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller pour l'interface du Chatbot (IA Sakan).
 */
class ChatbotController extends AbstractController
{
    public function __construct(
        private ChatbotService $chatbotService
    ) {}

    #[Route('/api/chatbot/ask', name: 'api_chatbot_ask', methods: ['POST'])]
    public function ask(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $message = $data['message'] ?? '';

        $response = $this->chatbotService->getResponse($message);

        return new JsonResponse([
            'response' => $response
        ]);
    }

    #[Route('/api/chatbot/welcome', name: 'api_chatbot_welcome', methods: ['GET'])]
    public function welcome(): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->chatbotService->getWelcomeMessage()
        ]);
    }
}
