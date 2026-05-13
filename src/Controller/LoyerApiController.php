<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\PaiementLoyerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/loyers')]
class LoyerApiController extends AbstractController
{
    #[Route('/{id}/payer', name: 'api_loyer_payer', methods: ['POST'])]
    public function marquerPaye(int $id, Request $request, PaiementLoyerRepository $repo): JsonResponse
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $methode = $data['methode'] ?? 'MANUEL';
        $reference = $data['reference'] ?? '';

        $ok = $repo->marquerPaye($id, $methode, $reference);

        return new JsonResponse([
            'success' => $ok,
            'message' => $ok ? 'Paiement enregistré avec succès' : 'Erreur lors de l\'enregistrement du paiement'
        ]);
    }
    
    #[Route('/relancer', name: 'api_loyer_relancer', methods: ['POST'])]
    public function relancer(Request $request): JsonResponse
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }
        
        $data = json_decode($request->getContent(), true) ?? [];
        $paiementId = $data['paiementId'] ?? null;
        $locataireNom = $data['locataire'] ?? 'le locataire';
        
        // Simuler l'envoi d'un email de rappel
        // TODO: Implémenter l'envoi d'email via MailerInterface
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Relance envoyée avec succès à ' . $locataireNom
        ]);
    }
}
