<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\AuthOtpService;
use App\Service\AuthValidationService;
use App\Service\OcrCINService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class ApiAuthController extends AbstractController
{
    #[Route('/auth/check-email', name: 'api_auth_check_email', methods: ['POST'])]
    public function checkEmail(Request $request, UtilisateurRepository $utilisateurRepository): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true) ?: [];
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        return $this->json([
            'email' => $email,
            'exists' => $email !== '' && $utilisateurRepository->emailExists($email),
        ]);
    }

    #[Route('/auth/password-reset/request', name: 'api_auth_password_reset_request', methods: ['POST'])]
    public function requestPasswordReset(Request $request, AuthOtpService $otpService): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true) ?: [];
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        if ($email === '') {
            return $this->json(['ok' => false, 'message' => 'Email requis.'], 400);
        }

        $sent = $otpService->createPasswordResetOtp($email);
        if (!$sent) {
            return $this->json(['ok' => false, 'message' => 'Compte introuvable.'], 404);
        }

        return $this->json(['ok' => true, 'message' => 'OTP envoye.']);
    }

    #[Route('/auth/password-reset/confirm', name: 'api_auth_password_reset_confirm', methods: ['POST'])]
    public function confirmPasswordReset(
        Request $request,
        AuthOtpService $otpService,
        UtilisateurRepository $utilisateurRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        AuthValidationService $validationService,
    ): JsonResponse {
        $data = json_decode((string) $request->getContent(), true) ?: [];

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $otpCode = (string) ($data['otp'] ?? '');
        $newPassword = (string) ($data['newPassword'] ?? '');

        if ($email === '' || $otpCode === '' || $newPassword === '') {
            return $this->json(['ok' => false, 'message' => 'Parametres manquants.'], 400);
        }
        if (!$validationService->isValidPassword($newPassword)) {
            return $this->json(['ok' => false, 'message' => 'Mot de passe invalide.'], 400);
        }
        if (!$otpService->verifyPasswordResetOtp($email, $otpCode)) {
            return $this->json(['ok' => false, 'message' => 'OTP invalide ou expire.'], 400);
        }

        $user = $utilisateurRepository->findOneByIdentifier($email);
        if (!$user instanceof Utilisateur) {
            return $this->json(['ok' => false, 'message' => 'Compte introuvable.'], 404);
        }

        $user->setMotDePasse($passwordHasher->hashPassword($user, $newPassword));
        $entityManager->flush();

        return $this->json(['ok' => true, 'message' => 'Mot de passe mis a jour.']);
    }

    // Route définie dans config/routes.yaml
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

