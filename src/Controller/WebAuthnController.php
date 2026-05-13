<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\WebAuthnCredential;
use App\Repository\UtilisateurRepository;
use App\Repository\WebAuthnCredentialRepository;
use CBOR\Decoder;
use CBOR\StringStream;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[Route('/webauthn', name: 'webauthn_')]
class WebAuthnController extends AbstractController
{
    private const CHALLENGE_TTL = 120;
    private const SK_REG  = 'wa_reg_challenge';
    private const SK_AUTH = 'wa_auth_challenge';

    public function __construct(
        private readonly EntityManagerInterface        $em,
        private readonly UtilisateurRepository         $utilisateurRepo,
        private readonly WebAuthnCredentialRepository  $credentialRepo,
        private readonly TokenStorageInterface         $tokenStorage,
    ) {}

    #[Route('/register/options', name: 'register_options', methods: ['POST'])]
    public function registerOptions(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Vous devez être connecté.'], 401);
        }
        $rpId      = $request->getHost();
        $challenge = random_bytes(32);
        $request->getSession()->set(self::SK_REG, [
            'challenge' => base64_encode($challenge),
            'expires'   => time() + self::CHALLENGE_TTL,
            'userId'    => $user->getId(),
        ]);
        $excludeCredentials = array_map(
            fn(WebAuthnCredential $c) => ['type' => 'public-key', 'id' => $c->getCredentialId()],
            $this->credentialRepo->findBy(['utilisateur' => $user])
        );
        return $this->json([
            'rp' => ['name' => 'Sakan', 'id' => $rpId],
            'user' => [
                'id'          => $this->b64uEncode(hash('sha256', (string) $user->getId(), true)),
                'name'        => (string) $user->getEmail(),
                'displayName' => $user->getNom() ?? $user->getEmail() ?? 'Utilisateur',
            ],
            'challenge'      => $this->b64uEncode($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout'       => 60000,
            'attestation'   => 'none',
            'excludeCredentials' => $excludeCredentials,
            'authenticatorSelection' => [
                'userVerification' => 'preferred',
                'residentKey'      => 'preferred',
            ],
        ]);
    }

    #[Route('/register/verify', name: 'register_verify', methods: ['POST'])]
    public function registerVerify(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié.'], 401);
        }
        $session = $request->getSession();
        $stored  = $session->get(self::SK_REG);
        if (!$stored || $stored['expires'] < time() || $stored['userId'] !== $user->getId()) {
            return $this->json(['error' => 'Session expirée, recommencez.'], 400);
        }
        $session->remove(self::SK_REG);
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON invalide.'], 400);
        }
        try {
            $rpId              = $request->getHost();
            $expectedChallenge = base64_decode($stored['challenge']);
            $clientDataJSON    = base64_decode($this->b64uToB64($body['response']['clientDataJSON'] ?? ''));
            $clientData        = json_decode($clientDataJSON, true, 512, JSON_THROW_ON_ERROR);
            if (($clientData['type'] ?? '') !== 'webauthn.create') {
                return $this->json(['error' => 'clientDataJSON.type invalide.'], 400);
            }
            $receivedChallenge = base64_decode($this->b64uToB64($clientData['challenge'] ?? ''));
            if (!hash_equals($expectedChallenge, $receivedChallenge)) {
                return $this->json(['error' => 'Challenge invalide.'], 400);
            }
            if (!str_contains($clientData['origin'] ?? '', $rpId)) {
                return $this->json(['error' => 'Origine invalide : ' . ($clientData['origin'] ?? '')], 400);
            }
            $attObjBytes       = base64_decode($this->b64uToB64($body['response']['attestationObject'] ?? ''));
            $attObj            = $this->cborDecode($attObjBytes);
            if (!isset($attObj['authData'])) {
                return $this->json(['error' => 'authData absent.'], 400);
            }
            $authDataParsed    = $this->parseAuthData($attObj['authData']);
            if (!hash_equals(hash('sha256', $rpId, true), $authDataParsed['rpIdHash'])) {
                return $this->json(['error' => 'RP ID hash invalide.'], 400);
            }
            if (($authDataParsed['flags'] & 0x01) === 0) {
                return $this->json(['error' => 'Présence utilisateur non confirmée.'], 400);
            }
            if (($authDataParsed['flags'] & 0x40) === 0) {
                return $this->json(['error' => 'Données credential absentes.'], 400);
            }
            $credData     = $authDataParsed['attestedCredentialData'];
            $credentialId = $body['id'] ?? '';
            if (!hash_equals($this->b64uEncode($credData['credentialId']), $credentialId)) {
                return $this->json(['error' => 'Credential ID incohérent.'], 400);
            }
            if ($this->credentialRepo->findOneBy(['credentialId' => $credentialId])) {
                return $this->json(['error' => 'Credential déjà enregistré.'], 409);
            }
            $transports = is_array($body['response']['transports'] ?? null)
                ? $body['response']['transports'] : null;
            $credential = new WebAuthnCredential();
            $credential->setCredentialId($credentialId);
            $credential->setPublicKeyData(
                json_encode($this->prepareCoseKeyForStorage($credData['cosePublicKey'])) ?: '{}'
            );
            $credential->setSignCount($authDataParsed['signCount']);
            $credential->setTransports($transports);
            $ua = $request->headers->get('User-Agent') ?? '';
            /** @var list<string> $transportList */
            $transportList = array_values(array_filter($transports ?? [], 'is_string'));
            $credential->setDeviceName(
                $this->detectDeviceName($ua, $transportList)
            );
            $credential->setUtilisateur($user);
            $this->em->persist($credential);
            $this->em->flush();
            return $this->json(['success' => true, 'message' => 'Biométrie activée.', 'device' => $credential->getDeviceName()]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur enregistrement : ' . $e->getMessage()], 400);
        }
    }

    #[Route('/auth/options', name: 'auth_options', methods: ['POST'])]
    public function authOptions(Request $request): JsonResponse
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
            return $this->json(['error' => 'Aucun credential biométrique trouvé.'], 404);
        }
        if (strtoupper((string) $user->getStatut()) === 'SUSPENDU') {
            return $this->json(['error' => 'Compte suspendu.'], 403);
        }
        $credentials = $this->credentialRepo->findBy(['utilisateur' => $user]);
        if (empty($credentials)) {
            return $this->json(['error' => 'Aucun appareil biométrique enregistré.'], 404);
        }
        $rpId      = $request->getHost();
        $challenge = random_bytes(32);
        $request->getSession()->set(self::SK_AUTH, [
            'challenge' => base64_encode($challenge),
            'expires'   => time() + self::CHALLENGE_TTL,
            'email'     => strtolower($email),
        ]);
        return $this->json([
            'challenge'        => $this->b64uEncode($challenge),
            'rpId'             => $rpId,
            'allowCredentials' => array_map(
                fn(WebAuthnCredential $c) => ['type' => 'public-key', 'id' => $c->getCredentialId(), 'transports' => $c->getTransports() ?? []],
                $credentials
            ),
            'userVerification' => 'preferred',
            'timeout'          => 60000,
        ]);
    }

    #[Route('/auth/verify', name: 'auth_verify', methods: ['POST'])]
    public function authVerify(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $stored  = $session->get(self::SK_AUTH);
        if (!$stored || $stored['expires'] < time()) {
            return $this->json(['error' => 'Session expirée.'], 400);
        }
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'JSON invalide.'], 400);
        }
        $session->remove(self::SK_AUTH);
        try {
            $rpId              = $request->getHost();
            $expectedChallenge = base64_decode($stored['challenge']);
            $credentialId      = $body['id'] ?? '';
            $storedCred = $this->credentialRepo->findOneBy(['credentialId' => $credentialId]);
            if (!$storedCred) {
                return $this->json(['error' => 'Credential non reconnu.'], 400);
            }
            $user = $storedCred->getUtilisateur();
            if (strtolower((string) $user->getEmail()) !== $stored['email']) {
                return $this->json(['error' => 'Credential ne correspond pas au compte.'], 403);
            }
            if (strtoupper((string) $user->getStatut()) === 'SUSPENDU') {
                return $this->json(['error' => 'Compte suspendu.'], 403);
            }
            $clientDataJSON    = base64_decode($this->b64uToB64($body['response']['clientDataJSON'] ?? ''));
            $authenticatorData = base64_decode($this->b64uToB64($body['response']['authenticatorData'] ?? ''));
            $signature         = base64_decode($this->b64uToB64($body['response']['signature'] ?? ''));
            $clientData = json_decode($clientDataJSON, true, 512, JSON_THROW_ON_ERROR);
            if (($clientData['type'] ?? '') !== 'webauthn.get') {
                return $this->json(['error' => 'clientDataJSON.type invalide.'], 400);
            }
            $receivedChallenge = base64_decode($this->b64uToB64($clientData['challenge'] ?? ''));
            if (!hash_equals($expectedChallenge, $receivedChallenge)) {
                return $this->json(['error' => 'Challenge invalide.'], 400);
            }
            if (!str_contains($clientData['origin'] ?? '', $rpId)) {
                return $this->json(['error' => 'Origine invalide.'], 400);
            }
            if (strlen($authenticatorData) < 37) {
                return $this->json(['error' => 'authenticatorData trop court.'], 400);
            }
            $rpIdHash     = substr($authenticatorData, 0, 32);
            $flags        = ord($authenticatorData[32]);
            $newSignCount = (int) (unpack('N', substr($authenticatorData, 33, 4)) ?: [1 => 0])[1];
            if (!hash_equals(hash('sha256', $rpId, true), $rpIdHash)) {
                return $this->json(['error' => 'RP ID hash invalide.'], 400);
            }
            if (($flags & 0x01) === 0) {
                return $this->json(['error' => 'Présence utilisateur non confirmée.'], 400);
            }
            $coseKey      = $this->restoreCoseKeyFromStorage(
                json_decode($storedCred->getPublicKeyData(), true)
            );
            $dataToVerify = $authenticatorData . hash('sha256', $clientDataJSON, true);
            if (!$this->verifySignature($dataToVerify, $signature, $coseKey)) {
                return $this->json(['error' => 'Signature invalide.'], 401);
            }
            $oldCount = $storedCred->getSignCount();
            if ($oldCount > 0 && $newSignCount <= $oldCount) {
                return $this->json(['error' => 'Compteur de signatures suspect.'], 403);
            }
            $storedCred->setSignCount($newSignCount);
            $this->em->flush();
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
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur : ' . $e->getMessage()], 500);
        }
    }

    #[Route('/credentials', name: 'credentials_list', methods: ['GET'])]
    public function listCredentials(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié.'], 401);
        }
        return $this->json(array_map(
            fn(WebAuthnCredential $c) => ['id' => $c->getId(), 'device' => $c->getDeviceName() ?? 'Inconnu', 'createdAt' => $c->getCreatedAt()->format('d/m/Y')],
            $this->credentialRepo->findBy(['utilisateur' => $user])
        ));
    }

    #[Route('/credentials/{id}', name: 'credential_delete', methods: ['DELETE'])]
    public function deleteCredential(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié.'], 401);
        }
        $cred = $this->credentialRepo->find($id);
        if (!$cred || $cred->getUtilisateur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Non trouvé.'], 404);
        }
        $this->em->remove($cred);
        $this->em->flush();
        return $this->json(['success' => true]);
    }

    // ── Crypto ────────────────────────────────────────────────────────

    private function cborDecode(string $data): mixed
    {
        $decoded = Decoder::create()->decode(StringStream::create($data));
        if ($decoded instanceof \CBOR\Normalizable) {
            return $decoded->normalize();
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function parseAuthData(string $authData): array
    {
        if (strlen($authData) < 37) throw new \RuntimeException('authData trop court.');
        $offset    = 0;
        $rpIdHash  = substr($authData, $offset, 32);  $offset += 32;
        $flags     = ord($authData[$offset]);          $offset += 1;
        $signCount = (int) (unpack('N', substr($authData, $offset, 4)) ?: [1 => 0])[1]; $offset += 4;
        $result = ['rpIdHash' => $rpIdHash, 'flags' => $flags, 'signCount' => $signCount, 'attestedCredentialData' => null];
        if (($flags & 0x40) !== 0 && $offset < strlen($authData)) {
            $offset += 16; // AAGUID
            $credIdLen    = (int) (unpack('n', substr($authData, $offset, 2)) ?: [1 => 0])[1]; $offset += 2;
            $credentialId = substr($authData, $offset, $credIdLen); $offset += $credIdLen;
            $result['attestedCredentialData'] = [
                'credentialId'  => $credentialId,
                'cosePublicKey' => $this->cborDecode(substr($authData, $offset)),
            ];
        }
        return $result;
    }

    /** @param array<string, mixed> $key */
    private function verifySignature(string $data, string $sig, array $key): bool
    {
        return match ($key['3'] ?? null) {
            '-7'   => $this->verifyES256($data, $sig, $key),
            '-257' => $this->verifyRS256($data, $sig, $key),
            default => throw new \RuntimeException('Algorithme COSE non supporté : ' . ($key['3'] ?? 'null')),
        };
    }

    /** @param array<string, mixed> $key */
    private function verifyES256(string $data, string $sig, array $key): bool
    {
        $x = $key['-2'] ?? null;
        $y = $key['-3'] ?? null;
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new \RuntimeException('Clé ES256 invalide.');
        }
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----";
        $pub = openssl_pkey_get_public($pem);
        if (!$pub) throw new \RuntimeException('Clé ES256 non chargeable.');
        return openssl_verify($data, $sig, $pub, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @param array<string, mixed> $key */
    private function verifyRS256(string $data, string $sig, array $key): bool
    {
        $n = $key['-1'] ?? null;
        $e = $key['-2'] ?? null;
        if (!is_string($n) || !is_string($e)) throw new \RuntimeException('Clé RS256 invalide.');
        $enc = static function(string $b): string {
            $b = ltrim($b, "\x00");
            if ($b !== '' && (ord($b[0]) & 0x80)) $b = "\x00" . $b;
            $l = strlen($b);
            return "\x02" . ($l < 0x80 ? chr($l) : ($l < 0x100 ? chr(0x81).chr($l) : chr(0x82).chr($l>>8).chr($l&0xFF))) . $b;
        };
        $encL = static function(int $l): string {
            return $l < 0x80 ? chr($l) : ($l < 0x100 ? chr(0x81).chr($l) : chr(0x82).chr($l>>8).chr($l&0xFF));
        };
        $seq = $enc($n) . $enc($e);
        $rsaKey = "\x30" . $encL(strlen($seq)) . $seq;
        $bs = "\x03" . $encL(strlen($rsaKey)+1) . "\x00" . $rsaKey;
        $spkiC = hex2bin('300d06092a864886f70d0101010500') . $bs;
        $spki = "\x30" . $encL(strlen($spkiC)) . $spkiC;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----";
        $pub = openssl_pkey_get_public($pem);
        if (!$pub) throw new \RuntimeException('Clé RS256 non chargeable.');
        return openssl_verify($data, $sig, $pub, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @param array<string, mixed> $key
     * @return array<string, mixed>
     */
    private function prepareCoseKeyForStorage(array $key): array
    {
        $r = [];
        foreach ($key as $k => $v) {
            $r[$k] = (is_string($v) && !mb_check_encoding($v, 'UTF-8'))
                ? ['__b64__' => base64_encode($v)]
                : $v;
        }
        return $r;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function restoreCoseKeyFromStorage(array $stored): array
    {
        $r = [];
        foreach ($stored as $k => $v) {
            $r[$k] = (is_array($v) && isset($v['__b64__']))
                ? base64_decode($v['__b64__'])
                : $v;
        }
        return $r;
    }

    private function b64uEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64uToB64(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $mod  = strlen($data) % 4;
        return $mod ? $data . str_repeat('=', 4 - $mod) : $data;
    }

    /** @param list<string> $transports */
    private function detectDeviceName(string $ua, array $transports): string
    {
        if (in_array('internal', $transports, true)) {
            if (str_contains($ua, 'Windows')) return 'Windows Hello';
            if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) return 'Face ID / Touch ID (iOS)';
            if (str_contains($ua, 'Mac'))    return 'Touch ID (Mac)';
            if (str_contains($ua, 'Android')) return 'Biométrie Android';
            return 'Authenticateur plateforme';
        }
        if (in_array('usb', $transports, true)) return 'Clé USB';
        if (in_array('nfc', $transports, true)) return 'Clé NFC';
        return 'Authenticateur inconnu';
    }
}
