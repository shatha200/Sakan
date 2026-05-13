<?php

namespace App\Security;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\AuthOtpService;
use App\Service\LoginSecurityService;
use App\Service\UserSecurityStateService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UtilisateurRepository $utilisateurRepository,
        private readonly AuthOtpService $authOtpService,
        private readonly LoginSecurityService $loginSecurityService,
        private readonly UserSecurityStateService $userSecurityStateService,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $identifier = trim((string) $request->request->get('identifier', ''));
        $plainPassword = (string) $request->request->get('password', '');

        if ($identifier === '' || trim($plainPassword) === '') {
            throw new CustomUserMessageAuthenticationException('Veuillez renseigner email et mot de passe.');
        }

        $request->getSession()->set(Security::LAST_USERNAME, $identifier);

        return new Passport(
            new UserBadge($identifier, function (string $userIdentifier) use ($request): Utilisateur {
                $user = $this->utilisateurRepository->findOneByIdentifier($userIdentifier);
                if (!$user instanceof Utilisateur) {
                    throw new CustomUserMessageAuthenticationException('Compte inexistant.');
                }

                if (strtoupper((string) $user->getStatut()) === 'SUSPENDU') {
                    throw new CustomUserMessageAuthenticationException($this->loginSecurityService->getLockMessage());
                }

                 if (!$this->userSecurityStateService->isEmailVerified($user)) {
                    $request->getSession()->set('registration_pending_user_id', (int) $user->getId());
                    throw new CustomUserMessageAuthenticationException('Verification email requise avant la connexion.');
                }

                return $user;
            }),
            new CustomCredentials(
                function (mixed $credentials, \Symfony\Component\Security\Core\User\UserInterface $user): bool {
                    if (!$user instanceof Utilisateur) return false;
                    $password = (string)$credentials;
                    $stored = (string) $user->getPassword();
                    if ($stored === '') {
                        throw new CustomUserMessageAuthenticationException('Mot de passe incorrect.');
                    }

                    // Compatibility with Java data: BCrypt hashes + legacy plain passwords.
                    if (preg_match('/^\$2[aby]\$/', $stored)) {
                        if (!password_verify($password, $stored)) {
                            throw new CustomUserMessageAuthenticationException('Mot de passe incorrect.');
                        }

                        return true;
                    }

                    if (!hash_equals($stored, $password)) {
                        throw new CustomUserMessageAuthenticationException('Mot de passe incorrect.');
                    }

                    return true;
                },
                $plainPassword
            ),
            [
                new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if ($user instanceof Utilisateur) {
            $this->loginSecurityService->registerSuccessfulLogin($user, $request->getClientIp());
        }

        if ($user instanceof Utilisateur && $user->isTwoFactorEnabled()) {
            $this->authOtpService->sendLoginTwoFactorCode($user);
            $request->getSession()->set('auth_2fa_verified', false);

            return new RedirectResponse($this->urlGenerator->generate('app_2fa'));
        }

        $request->getSession()->set('auth_2fa_verified', true);

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        if ($user instanceof Utilisateur) {
            $role = $user->getRoleName();
            if ($role === 'ADMIN') {
                return new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
            }
            if ($role === 'PROPRIETAIRE') {
                return new RedirectResponse($this->urlGenerator->generate('owner_dashboard'));
            }
        }

        return new RedirectResponse($this->urlGenerator->generate('tenant_catalogue'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $message = strtolower(trim((string) $exception->getMessageKey()));
        $identifier = trim((string) $request->request->get('identifier', ''));

        $session = $request->getSession();
        if (str_contains($message, 'verification email requise')) {
            if ($session instanceof \Symfony\Component\HttpFoundation\Session\Session) {
                $session->getFlashBag()->add('auth_error', 'Votre inscription n est pas encore verifiee. Saisissez le code recu par email pour activer votre compte.');
            }

            return new RedirectResponse($this->urlGenerator->generate('app_register_verify'));
        }

        if (
            $identifier !== ''
            && !str_contains($message, 'veuillez renseigner')
            && !str_contains($message, 'desactive')
            && !str_contains($message, 'csrf')
            && !str_contains($message, 'verification')
        ) {
            $locked = $this->loginSecurityService->registerFailedAttempt($identifier, $request->getClientIp());
            if ($locked && $session instanceof \Symfony\Component\HttpFoundation\Session\Session) {
                $session->getFlashBag()->add('auth_error', $this->loginSecurityService->getLockMessage());
            }
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
