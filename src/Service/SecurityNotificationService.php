<?php

namespace App\Service;

use App\Entity\Utilisateur;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class SecurityNotificationService
{
    public const SUPPORT_EMAIL = 'sakan.admin@gmail.com';

    public function __construct(private readonly MailerInterface $mailer)
    {
    }

    public function sendLoginNotice(Utilisateur $user, ?string $ipAddress): void
    {
        if ($this->shouldSkip($user)) {
            return;
        }

        $ipText = trim((string) $ipAddress) !== '' ? trim((string) $ipAddress) : 'IP inconnue';
        $this->sendTextMail(
            $user,
            'Sakan - Nouvelle connexion a votre compte',
            "Bonjour,\n\nUne connexion vient d'etre detectee sur votre compte Sakan.\nAdresse IP : {$ipText}\n\nSi ce n'etait pas vous, changez votre mot de passe rapidement et contactez ".self::SUPPORT_EMAIL."."
        );
    }

    public function sendFailedAttemptsWarning(Utilisateur $user, int $attemptCount): void
    {
        if ($this->shouldSkip($user)) {
            return;
        }

        $remaining = max(0, LoginSecurityService::MAX_FAILED_ATTEMPTS - $attemptCount);
        $this->sendTextMail(
            $user,
            'Sakan - Alerte de securite sur votre compte',
            "Bonjour,\n\nNous avons detecte {$attemptCount} tentatives de connexion avec un mot de passe incorrect sur votre compte.\nEncore {$remaining} tentative(s) incorrecte(s) et votre compte sera desactive.\n\nSi ce n'etait pas vous, changez votre mot de passe et contactez ".self::SUPPORT_EMAIL.'.'
        );
    }

    public function sendPasswordChanged(Utilisateur $user): void
    {
        if ($this->shouldSkip($user)) {
            return;
        }

        $this->sendTextMail(
            $user,
            'Sakan - Mot de passe modifie',
            "Bonjour,\n\nLe mot de passe de votre compte Sakan vient d'etre modifie.\n\nSi vous n'etes pas a l'origine de ce changement, contactez immediatement ".self::SUPPORT_EMAIL.'.'
        );
    }

    public function sendAccountSuspended(Utilisateur $user): void
    {
        if ($this->shouldSkip($user)) {
            return;
        }

        $this->sendTextMail(
            $user,
            'Sakan - Compte desactive',
            "Bonjour,\n\nVotre compte Sakan a ete desactive.\nMerci de contacter notre support sur ".self::SUPPORT_EMAIL." pour obtenir de l'aide."
        );
    }

    public function sendAccountReactivated(Utilisateur $user): void
    {
        if ($this->shouldSkip($user)) {
            return;
        }

        $this->sendTextMail(
            $user,
            'Sakan - Compte reactive',
            "Bonjour,\n\nVotre compte Sakan a ete reactive. Vous pouvez a nouveau vous connecter normalement."
        );
    }

    private function shouldSkip(Utilisateur $user): bool
    {
        if (strtoupper((string) $user->getRoleName()) === 'ADMIN') {
            return true;
        }

        return trim((string) $user->getEmail()) === '';
    }

    private function sendTextMail(Utilisateur $user, string $subject, string $body): void
    {
        try {
            $email = (new Email())
                ->from('noreply@sakan.local')
                ->to((string) $user->getEmail())
                ->subject($subject)
                ->text($body);

            $this->mailer->send($email);
        } catch (\Throwable) {
            // Notifications should never block the main user flow.
        }
    }
}
