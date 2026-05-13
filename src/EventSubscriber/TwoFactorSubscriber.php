<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class TwoFactorSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onKernelRequest'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');

        if (str_starts_with($route, '_profiler') || str_starts_with($route, '_wdt')) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof Utilisateur || !$user->isTwoFactorEnabled()) {
            return;
        }

        if (in_array($route, ['app_login', 'app_2fa', 'app_logout'], true) || str_starts_with($route, 'api_auth_')) {
            return;
        }

        $session = $request->getSession();
        $verified = (bool) $session->get('auth_2fa_verified', false);
        if ($verified) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->router->generate('app_2fa')));
    }
}
