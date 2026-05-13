<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\FaceDescriptor;
use App\Entity\Utilisateur;
use App\Repository\FaceDescriptorRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[Route('/face-auth', name: 'face_auth_')]
class FaceAuthController extends AbstractController
{
    private const MATCH_THRESHOLD = 0.48;
    private const SK_CHALLENGE    = 'face_login_challenge';
    private const CHALLENGE_TTL   = 120;

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly UtilisateurRepository   $utilisateurRepo,
        private readonly FaceDescriptorRepository $descriptorRepo,
        private readonly TokenStorageInterface   $tokenStorage,
    ) {}

    #[Route('/enroll', name: 'enroll', methods: ['POST'])]
    public function enroll(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié.'], 401);
        }
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON invalide.'], 400);
        }
        $descriptor = $body['descriptor'] ?? null;
        if (!is_array($descriptor) || count($descriptor) !== 128) {
            return $this->json(['error' => 'Descripteur facial invalide (128 valeurs attendues).'], 400);
        }
        foreach ($descriptor as $v) {
            if (!is_numeric($v)) {
                return $this->json(['error' => 'Descripteur contient des valeurs non numériques.'], 400);
            }
        }
        $existing = $this->descriptorRepo->findOneBy(['utilisateur' => $user]);
        if ($existing) {
            $this->em->remove($existing);
            $this->em->flush();
        }
        $fd = new FaceDescriptor();
        $fd->setDescriptor(array_map('floatval', $descriptor));
        $fd->setUtilisateur($user);
        $this->em->persist($fd);
        $this->em->flush();
        return $this->json(['success' => true, 'message' => 'Reconnaissance faciale configurée.']);
    }

    #[Route('/descriptor', name: 'delete_descriptor', methods: ['DELETE'])]
    public function deleteDescriptor(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié.'], 401);
        }
        $fd = $this->descriptorRepo->findOneBy(['utilisateur' => $user]);
        if (!$fd) {
            return $this->json(['error' => 'Aucun descripteur enregistré.'], 404);
        }
        $this->em->remove($fd);
        $this->em->flush();
        return $this->json(['success' => true]);
    }

    #[Route('/has-face', name: 'has_face', methods: ['GET'])]
    public function hasFace(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié.'], 401);
        }
        $fd = $this->descriptorRepo->findOneBy(['utilisateur' => $user]);
        return $this->json([
            'enrolled'   => $fd !== null,
            'enrolledAt' => $fd ? $fd->getCreatedAt()->format('d/m/Y') : null,
        ]);
    }

    #[Route('/login/check', name: 'login_check', methods: ['POST'])]
    public function loginCheck(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON invalide.'], 400);
        }
        $email = trim((string) ($body['email'] ?? ''));
        if ($email === '') {
            return $this->json(['error' => 'Email requis.'], 400);
        }
        $user = $this->utilisateurRepo->findOneByIdentifier($email);
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Aucune reconnaissance faciale enregistrée pour ce compte.'], 404);
        }
        if (strtoupper((string) $user->getStatut()) === 'SUSPENDU') {
            return $this->json(['error' => 'Compte suspendu.'], 403);
        }
        $fd = $this->descriptorRepo->findOneBy(['utilisateur' => $user]);
        if (!$fd) {
            return $this->json(['error' => 'Aucune reconnaissance faciale enregistrée pour ce compte.'], 404);
        }
        $request->getSession()->set(self::SK_CHALLENGE, [
            'email'   => strtolower($email),
            'expires' => time() + self::CHALLENGE_TTL,
        ]);
        return $this->json(['ready' => true, 'displayName' => $user->getNom() ?? $email]);
    }

    #[Route('/login/verify', name: 'login_verify', methods: ['POST'])]
    public function loginVerify(Request $request): JsonResponse
    {
        $session   = $request->getSession();
        $challenge = $session->get(self::SK_CHALLENGE);
        if (!$challenge || $challenge['expires'] < time()) {
            return $this->json(['error' => 'Session expirée, recommencez.'], 400);
        }
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON invalide.'], 400);
        }
        $session->remove(self::SK_CHALLENGE);
        $descriptor = $body['descriptor'] ?? null;
        if (!is_array($descriptor) || count($descriptor) !== 128) {
            return $this->json(['error' => 'Descripteur invalide.'], 400);
        }
        $user = $this->utilisateurRepo->findOneByIdentifier($challenge['email']);
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Utilisateur introuvable.'], 404);
        }
        if (strtoupper((string) $user->getStatut()) === 'SUSPENDU') {
            return $this->json(['error' => 'Compte suspendu.'], 403);
        }
        $fd = $this->descriptorRepo->findOneBy(['utilisateur' => $user]);
        if (!$fd) {
            return $this->json(['error' => 'Aucune reconnaissance faciale pour ce compte.'], 404);
        }
        $distance = $this->euclideanDistance($fd->getDescriptor(), array_map('floatval', $descriptor));
        if ($distance > self::MATCH_THRESHOLD) {
            return $this->json(['error' => 'Visage non reconnu. Essayez un meilleur éclairage.'], 401);
        }
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($token);
        $session->set('_security_main', serialize($token));
        $session->set('auth_2fa_verified', true);
        $redirect = match ($user->getRoleName()) {
            'ADMIN'        => $this->generateUrl('admin_dashboard'),
            'PROPRIETAIRE' => $this->generateUrl('owner_dashboard'),
            default        => $this->generateUrl('tenant_catalogue'),
        };
        return $this->json(['success' => true, 'redirect' => $redirect]);
    }

    /**
     * @param array<float> $a
     * @param array<float> $b
     */
    private function euclideanDistance(array $a, array $b): float
    {
        $sum = 0.0;
        $n   = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $diff = (float) $a[$i] - (float) $b[$i];
            $sum += $diff * $diff;
        }
        return sqrt($sum);
    }
}
