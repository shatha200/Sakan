<?php

namespace App\Twig;

use App\Entity\Utilisateur;
use App\Service\NotificationService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('owner_notif_count', [$this, 'count']),
            new TwigFunction('owner_notif_recent', [$this, 'recent']),
        ];
    }

    public function count(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return 0;
        }
        return $this->notificationService->getUnreadCount((int) $user->getId());
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 8): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            return [];
        }
        return $this->notificationService->getRecent((int) $user->getId(), $limit);
    }
}
