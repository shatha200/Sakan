<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Security\LoginFormAuthenticator;
use App\Service\AuthOtpService;
use App\Service\AuthValidationService;
use App\Service\GoogleOAuthService;
use App\Service\LoginSecurityService;
use App\Service\SecurityNotificationService;
use App\Service\UserSecurityStateService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(
        Request $request,
        AuthenticationUtils $authenticationUtils,
        TokenStorageInterface $tokenStorage,
        LoginSecurityService $loginSecurityService,
    ): Response
    {
        $user = $this->getUser();
        if ($user instanceof Utilisateur) {
            if (strtoupper((string) $user->getStatut()) === 'SUSPENDU') {
                $tokenStorage->setToken(null);
                $request->getSession()->invalidate();
                $this->addFlash('auth_error', $loginSecurityService->getLockMessage());

                $response = $this->redirectToRoute('app_login');
                $response->headers->clearCookie('REMEMBERME');
                $response->headers->clearCookie('remember_me');

                return $response;
            }

            if ($user->getRoleName() === 'ADMIN') {
                return $this->redirectToRoute('admin_dashboard');
            }
            if ($user->getRoleName() === 'PROPRIETAIRE') {
                return $this->redirectToRoute('owner_dashboard');
            }

            return $this->redirectToRoute('tenant_catalogue');
        }

        return $this->render('auth/login.html.twig', [
            'last_identifier' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UtilisateurRepository $utilisateurRepository,
        AuthValidationService $validationService,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        AuthOtpService $otpService,
        UserSecurityStateService $userSecurityStateService,
    ): Response {
        $form = [
            'prenom' => '',
            'nom' => '',
            'email' => '',
            'telephone' => '',
            'role' => 'LOCATAIRE',
            'accept_terms' => false,
        ];

        $renderWithError = function (string $message) use (&$form): Response {
            return $this->render('auth/register.html.twig', [
                'form' => $form,
                'register_error' => $message,
            ]);
        };

        if ($request->isMethod('POST')) {
            $prenom = trim((string) $request->request->get('prenom', ''));
            $nom = trim((string) $request->request->get('nom', ''));
            $email = strtolower(trim((string) $request->request->get('email', '')));
            $telephone = trim((string) $request->request->get('telephone', ''));
            $password = (string) $request->request->get('password', '');
            $passwordConfirm = (string) $request->request->get('password_confirm', '');
            $role = strtoupper(trim((string) $request->request->get('role', 'LOCATAIRE')));
            $acceptTerms = (bool) $request->request->get('accept_terms');

            $form = [
                'prenom' => $prenom,
                'nom' => $nom,
                'email' => $email,
                'telephone' => $telephone,
                'role' => in_array($role, ['LOCATAIRE', 'PROPRIETAIRE'], true) ? $role : 'LOCATAIRE',
                'accept_terms' => $acceptTerms,
            ];

            if ($prenom === '' || $nom === '' || $email === '' || $telephone === '' || $password === '') {
                return $renderWithError('Veuillez remplir tous les champs obligatoires (prénom, nom, email, téléphone, mot de passe).');
            }

            if (!$validationService->isValidEmail($email)) {
                return $renderWithError('Entrez une adresse email valide.');
            }

            if (!$validationService->isValidPassword($password)) {
                return $renderWithError('Le mot de passe ne respecte pas les criteres (6 caracteres min, minuscule, majuscule, chiffre).');
            }

            if ($password !== $passwordConfirm) {
                return $renderWithError('Les deux mots de passe ne correspondent pas.');
            }

            if (!$acceptTerms) {
                return $renderWithError('Vous devez accepter les conditions pour créer un compte.');
            }

            if (!in_array($role, ['LOCATAIRE', 'PROPRIETAIRE'], true)) {
                return $renderWithError('Choisissez un seul rôle : locataire ou propriétaire.');
            }

            if (!$validationService->isValidTunisiaPhone($telephone)) {
                return $renderWithError('Entrez un numéro de téléphone tunisien valide (8 chiffres).');
            }

            $telephoneE164 = $validationService->toTunisiaE164($telephone);

            if ($utilisateurRepository->emailExists($email)) {
                return $renderWithError('Un compte existe déjà avec cette adresse email. Utilisez une autre adresse ou connectez-vous.');
            }

            if ($utilisateurRepository->phoneExists($telephone, $telephoneE164)) {
                return $renderWithError('Ce numéro de téléphone existe déjà.');
            }

            $utilisateur = new Utilisateur();
            $utilisateur->setNom($prenom . ' ' . $nom);
            $utilisateur->setEmail($email);
            $utilisateur->setRoleName($role);
            $utilisateur->setStatut('ACTIF');
            $utilisateur->setTelephone($telephone);
            $utilisateur->setTelephoneE164($telephoneE164);
            $utilisateur->setDateInscription(new \DateTime('today'));
            $utilisateur->setMotDePasse($passwordHasher->hashPassword($utilisateur, $password));

            try {
                $entityManager->persist($utilisateur);
                $entityManager->flush();
            } catch (UniqueConstraintViolationException $exception) {
                $errorMessage = strtolower($exception->getMessage());
                if (str_contains($errorMessage, 'telephone') || str_contains($errorMessage, 'phone')) {
                    return $renderWithError('Ce numéro de téléphone existe déjà.');
                } elseif (str_contains($errorMessage, 'email')) {
                    return $renderWithError('Un compte existe déjà avec cette adresse email.');
                } else {
                    return $renderWithError('Ces informations existent déjà dans la base de données.');
                }
            }

            $userSecurityStateService->bootstrapUser($utilisateur, false);

            if (!$otpService->createRegistrationVerificationOtp($utilisateur)) {
                $entityManager->remove($utilisateur);
                $entityManager->flush();

                return $renderWithError('Impossible d envoyer le code de verification. Reessayez plus tard.');
            }

            $request->getSession()->set('registration_pending_user_id', (int) $utilisateur->getId());
            $this->addFlash('auth_success', 'Un code OTP a ete envoye sur votre adresse email pour activer votre compte.');

            return $this->redirectToRoute('app_register_verify');
        }

        return $this->render('auth/register.html.twig', [
            'form' => $form,
            'register_error' => null,
        ]);
    }

    #[Route('/auth/google', name: 'app_google_start', methods: ['GET'])]
    public function startGoogleAuth(Request $request, GoogleOAuthService $googleOAuthService): Response
    {
        $flow = strtolower(trim((string) $request->query->get('flow', 'login')));
        $routeOnError = $flow === 'register' ? 'app_register' : 'app_login';
        $request->getSession()->set('google_oauth_flow', $flow);

        try {
            $authorizationUrl = $googleOAuthService->buildAuthorizationUrl($request);
        } catch (\Throwable $exception) {
            $this->addFlash('auth_error', $exception->getMessage());

            return $this->redirectToRoute($routeOnError);
        }

        return new RedirectResponse($authorizationUrl);
    }

    #[Route('/auth/google/callback', name: 'app_google_callback', methods: ['GET'])]
    public function googleCallback(
        Request $request,
        GoogleOAuthService $googleOAuthService,
        UtilisateurRepository $utilisateurRepository,
        EntityManagerInterface $entityManager,
        UserAuthenticatorInterface $userAuthenticator,
        LoginFormAuthenticator $authenticator,
        LoginSecurityService $loginSecurityService,
        UserSecurityStateService $userSecurityStateService,
    ): Response {
        $flow = strtolower((string) $request->getSession()->get('google_oauth_flow', 'login'));
        $routeOnError = $flow === 'register' ? 'app_register' : 'app_login';
        $request->getSession()->remove('google_oauth_flow');

        try {
            $profile = $googleOAuthService->fetchUserProfile($request);
        } catch (\Throwable $exception) {
            $this->addFlash('auth_error', $exception->getMessage());

            return $this->redirectToRoute($routeOnError);
        }

        if (!$profile['email_verified']) {
            $this->addFlash('auth_error', "Votre email Google n'est pas verifie.");

            return $this->redirectToRoute($routeOnError);
        }

        $email = $profile['email'];
        $sub = $profile['sub'];

        $utilisateur = $utilisateurRepository->findOneByGoogleSub($sub);
        if (!$utilisateur instanceof Utilisateur) {
            $utilisateur = $utilisateurRepository->findOneByIdentifier($email);
            if ($utilisateur instanceof Utilisateur && trim((string) $utilisateur->getGoogleSub()) === '') {
                $utilisateur->setGoogleSub($sub);
                $entityManager->flush();
                $userSecurityStateService->bootstrapUser($utilisateur, true);
            }
        }

        if (!$utilisateur instanceof Utilisateur) {
            $request->getSession()->set('google_oauth_pending_profile', [
                'sub' => $sub,
                'email' => $email,
                'name' => trim((string) ($profile['name'] ?? '')),
                'flow' => $flow,
            ]);

            return $this->redirectToRoute('app_google_complete_profile');
        }

        if (strtoupper((string) $utilisateur->getStatut()) === 'SUSPENDU') {
            $this->addFlash('auth_error', $loginSecurityService->getLockMessage());

            return $this->redirectToRoute('app_login');
        }

        $userSecurityStateService->bootstrapUser($utilisateur, true);

        return $userAuthenticator->authenticateUser($utilisateur, $authenticator, $request)
            ?? $this->redirectToRoute('app_login');
    }

    #[Route('/auth/google/completer-profil', name: 'app_google_complete_profile', methods: ['GET', 'POST'])]
    public function completeGoogleProfile(
        Request $request,
        UtilisateurRepository $utilisateurRepository,
        AuthValidationService $validationService,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        UserAuthenticatorInterface $userAuthenticator,
        LoginFormAuthenticator $authenticator,
        UserSecurityStateService $userSecurityStateService,
    ): Response {
        $session = $request->getSession();
        /** @var array{sub?:string,email?:string,name?:string,flow?:string}|mixed $pendingProfile */
        $pendingProfile = $session->get('google_oauth_pending_profile');

        if (!is_array($pendingProfile)) {
            $this->addFlash('auth_error', 'La session Google a expire. Recommencez la connexion.');

            return $this->redirectToRoute('app_login');
        }

        $googleSub = trim((string) ($pendingProfile['sub'] ?? ''));
        $googleEmail = strtolower(trim((string) ($pendingProfile['email'] ?? '')));
        $googleName = trim((string) ($pendingProfile['name'] ?? ''));
        $flow = strtolower(trim((string) ($pendingProfile['flow'] ?? 'login')));
        $routeOnError = $flow === 'register' ? 'app_register' : 'app_login';

        if ($googleSub === '' || $googleEmail === '') {
            $session->remove('google_oauth_pending_profile');
            $this->addFlash('auth_error', 'Les informations Google sont inexploitables. Recommencez.');

            return $this->redirectToRoute($routeOnError);
        }

        $nameParts = $this->splitDisplayName($googleName);
        $form = [
            'prenom' => $nameParts['prenom'],
            'nom' => $nameParts['nom'],
            'email' => $googleEmail,
            'telephone' => '',
        ];

        $renderWithError = function (string $message) use (&$form, $googleEmail, $routeOnError): Response {
            return $this->render('auth/google_complete_profile.html.twig', [
                'form' => $form,
                'register_error' => $message,
                'google_email' => $googleEmail,
                'return_route' => $routeOnError,
            ]);
        };

        if ($request->isMethod('POST')) {
            $prenom = trim((string) $request->request->get('prenom', ''));
            $nom = trim((string) $request->request->get('nom', ''));
            $email = strtolower(trim((string) $request->request->get('email', '')));
            $telephone = trim((string) $request->request->get('telephone', ''));

            $form = [
                'prenom' => $prenom,
                'nom' => $nom,
                'email' => $email,
                'telephone' => $telephone,
            ];

            if ($prenom === '' || $nom === '' || $email === '' || $telephone === '') {
                return $renderWithError('Veuillez remplir le prenom, le nom, le mail et le numero de telephone.');
            }

            if (!$validationService->isValidEmail($email)) {
                return $renderWithError('Entrez une adresse email valide.');
            }

            if ($email !== $googleEmail) {
                return $renderWithError("L'adresse email doit correspondre au compte Google utilise.");
            }

            if (!$validationService->isValidTunisiaPhone($telephone)) {
                return $renderWithError('Entrez un numero de telephone tunisien valide (8 chiffres).');
            }

            $telephoneE164 = $validationService->toTunisiaE164($telephone);

            if ($utilisateurRepository->emailExists($email)) {
                return $renderWithError('Un compte existe deja avec cette adresse email.');
            }

            if ($utilisateurRepository->phoneExists($telephone, $telephoneE164)) {
                return $renderWithError('Ce numero de telephone existe deja.');
            }

            $utilisateur = new Utilisateur();
            $utilisateur->setNom(trim($prenom . ' ' . $nom));
            $utilisateur->setEmail($email);
            $utilisateur->setRoleName('LOCATAIRE');
            $utilisateur->setStatut('ACTIF');
            $utilisateur->setTelephone($telephone);
            $utilisateur->setTelephoneE164($telephoneE164);
            $utilisateur->setDateInscription(new \DateTime('today'));
            $utilisateur->setGoogleSub($googleSub);
            $utilisateur->setMotDePasse($passwordHasher->hashPassword($utilisateur, 'GOOGLE_OAUTH_PLACEHOLDER'));

            try {
                $entityManager->persist($utilisateur);
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                return $renderWithError('Ces informations existent deja dans la base de donnees.');
            }

            $session->remove('google_oauth_pending_profile');
            $userSecurityStateService->bootstrapUser($utilisateur, true);

            return $userAuthenticator->authenticateUser($utilisateur, $authenticator, $request)
                ?? $this->redirectToRoute('app_login');
        }

        return $this->render('auth/google_complete_profile.html.twig', [
            'form' => $form,
            'register_error' => null,
            'google_email' => $googleEmail,
            'return_route' => $routeOnError,
        ]);
    }

    #[Route('/inscription/verification', name: 'app_register_verify', methods: ['GET', 'POST'])]
    public function verifyRegistration(
        Request $request,
        UtilisateurRepository $utilisateurRepository,
        AuthOtpService $otpService,
        UserSecurityStateService $userSecurityStateService,
        UserAuthenticatorInterface $userAuthenticator,
        LoginFormAuthenticator $authenticator,
    ): Response {
        $session = $request->getSession();
        $pendingUserId = (int) $session->get('registration_pending_user_id', 0);
        $user = $pendingUserId > 0 ? $utilisateurRepository->find($pendingUserId) : null;

        if (!$user instanceof Utilisateur) {
            $session->remove('registration_pending_user_id');
            $this->addFlash('auth_error', 'Aucune verification d inscription en attente.');

            return $this->redirectToRoute('app_register');
        }

        if ($userSecurityStateService->isEmailVerified($user)) {
            $session->remove('registration_pending_user_id');
            $this->addFlash('auth_success', 'Votre compte est deja verifie. Vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'verify');

            if ($action === 'resend') {
                $sent = $otpService->createRegistrationVerificationOtp($user);
                $this->addFlash($sent ? 'auth_success' : 'auth_error', $sent ? 'Un nouveau code a ete envoye.' : 'Impossible de renvoyer le code pour le moment.');

                return $this->redirectToRoute('app_register_verify');
            }

            $code = (string) $request->request->get('otp_code', '');
            if (!$otpService->verifyRegistrationVerificationOtp($user, $code)) {
                $this->addFlash('auth_error', 'Code invalide ou expire.');

                return $this->redirectToRoute('app_register_verify');
            }

            $userSecurityStateService->markEmailVerified($user);
            $session->remove('registration_pending_user_id');
            $this->addFlash('auth_success', 'Votre inscription est verifiee. Bienvenue sur Sakan.');

            return $userAuthenticator->authenticateUser($user, $authenticator, $request)
                ?? $this->redirectToRoute('app_login');
        }

        return $this->render('auth/register_verify.html.twig', [
            'pending_email' => (string) $user->getEmail(),
        ]);
    }

    #[Route('/verification-2fa', name: 'app_2fa', methods: ['GET', 'POST'])]
    public function verify2fa(Request $request, AuthOtpService $otpService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        if (!$user->isTwoFactorEnabled()) {
            $request->getSession()->set('auth_2fa_verified', true);
            return $this->redirectToRoleHome($user);
        }

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'verify');

            if ($action === 'resend') {
                $sent = $otpService->resendLoginTwoFactorCode($user);
                $this->addFlash($sent ? 'auth_success' : 'auth_error', $sent ? 'Un nouveau code a été envoyé.' : 'Impossible de renvoyer le code pour le moment.');
                return $this->redirectToRoute('app_2fa');
            }

            $code = (string) $request->request->get('otp_code', '');
            $ok = $otpService->verifyLoginTwoFactorCode($user, $code);
            if ($ok) {
                $request->getSession()->set('auth_2fa_verified', true);
                return $this->redirectToRoleHome($user);
            }
            $this->addFlash('auth_error', 'Code invalide ou expire.');
            return $this->redirectToRoute('app_2fa');
        }

        $otpStatus = $otpService->getLoginTwoFactorOtpStatus($user);

        return $this->render('auth/two_factor.html.twig', [
            'user_email' => $user->getEmail(),
            'otp_status' => $otpStatus,
        ]);
    }

    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        AuthOtpService $otpService,
        UtilisateurRepository $utilisateurRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        AuthValidationService $validationService,
        SecurityNotificationService $securityNotificationService,
        UserSecurityStateService $userSecurityStateService,
    ): Response {
        if ($request->isMethod('POST')) {
            $step = (string) $request->request->get('step', 'request');
            if ($step === 'request') {
                $email = strtolower(trim((string) $request->request->get('email', '')));
                if ($email === '') {
                    $this->addFlash('auth_error', 'Email non renseigne.');
                    return $this->redirectToRoute('app_forgot_password');
                }
                $sent = $otpService->createPasswordResetOtp($email);
                if (!$sent) {
                    $this->addFlash('auth_error', 'Aucun compte associe a cette adresse email, ou envoi email echoue.');
                    return $this->redirectToRoute('app_forgot_password');
                }
                $request->getSession()->set('password_reset_email', $email);
                $this->addFlash('auth_success', 'Un code à 6 chiffres a été envoyé à votre adresse email.');
                return $this->redirectToRoute('app_forgot_password');
            }

            if ($step === 'resend') {
                $email = strtolower(trim((string) $request->getSession()->get('password_reset_email', '')));
                if ($email !== '') {
                    $sent = $otpService->resendPasswordResetOtp($email);
                    $this->addFlash($sent ? 'auth_success' : 'auth_error', $sent ? 'Un nouveau code a été envoyé.' : 'Impossible de renvoyer le code pour le moment.');
                }
                return $this->redirectToRoute('app_forgot_password');
            }

            if ($step === 'verify') {
                $email = strtolower(trim((string) $request->getSession()->get('password_reset_email', '')));
                $code = (string) $request->request->get('otp_code', '');
                $newPassword = (string) $request->request->get('new_password', '');
                $confirm = (string) $request->request->get('confirm_password', '');

                if ($email === '') {
                    $this->addFlash('auth_error', 'Session expiree. Recommencez.');
                    return $this->redirectToRoute('app_forgot_password');
                }
                if ($newPassword !== $confirm) {
                    $this->addFlash('auth_error', 'Les deux mots de passe ne correspondent pas.');
                    return $this->redirectToRoute('app_forgot_password');
                }

                if (!$validationService->isValidPassword($newPassword)) {
                    $this->addFlash('auth_error', 'Le mot de passe doit contenir au moins 6 caracteres, une minuscule, une majuscule et un chiffre.');
                    return $this->redirectToRoute('app_forgot_password');
                }

                $ok = $otpService->verifyPasswordResetOtp($email, $code);
                if (!$ok) {
                    $this->addFlash('auth_error', 'Code invalide ou expire.');
                    return $this->redirectToRoute('app_forgot_password');
                }

                $user = $utilisateurRepository->findOneByIdentifier($email);
                if (!$user instanceof Utilisateur) {
                    $this->addFlash('auth_error', 'Compte introuvable.');
                    return $this->redirectToRoute('app_forgot_password');
                }

                $user->setMotDePasse($passwordHasher->hashPassword($user, $newPassword));
                $entityManager->flush();
                $userSecurityStateService->notePasswordChanged($user);
                $securityNotificationService->sendPasswordChanged($user);
                $request->getSession()->remove('password_reset_email');

                $this->addFlash('auth_success', 'Mot de passe mis à jour. Vous pouvez vous connecter.');
                return $this->redirectToRoute('app_login');
            }
        }

        $pendingEmail = (string) $request->getSession()->get('password_reset_email', '');
        $otpStatus = $pendingEmail !== '' ? $otpService->getPasswordResetOtpStatus($pendingEmail) : null;

        return $this->render('auth/forgot_password.html.twig', [
            'pending_email' => $pendingEmail,
            'otp_status' => $otpStatus,
        ]);
    }

    #[Route('/deconnexion', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Logout is handled by the firewall.');
    }

    private function redirectToRoleHome(Utilisateur $user): RedirectResponse
    {
        if ($user->getRoleName() === 'ADMIN') {
            return $this->redirectToRoute('admin_dashboard');
        }
        if ($user->getRoleName() === 'PROPRIETAIRE') {
            return $this->redirectToRoute('owner_dashboard');
        }

        return $this->redirectToRoute('tenant_catalogue');
    }

    /**
     * @return array{prenom:string,nom:string}
     */
    private function splitDisplayName(string $displayName): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($displayName)) ?: []));
        if ($parts === []) {
            return [
                'prenom' => '',
                'nom' => '',
            ];
        }

        if (count($parts) === 1) {
            return [
                'prenom' => $parts[0],
                'nom' => '',
            ];
        }

        $prenom = array_shift($parts);

        return [
            'prenom' => (string) $prenom,
            'nom' => implode(' ', $parts),
        ];
    }
}
