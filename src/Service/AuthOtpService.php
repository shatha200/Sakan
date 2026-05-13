<?php

namespace App\Service;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class AuthOtpService
{
    public const OTP_EXPIRY_MINUTES = 5;
    public const OTP_MAX_ATTEMPTS = 5;
    public const OTP_MAX_RESENDS = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly UtilisateurRepository $utilisateurRepository,
        private readonly MailerInterface $mailer,
    ) {
    }

    public function createPasswordResetOtp(string $email): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $user = $this->utilisateurRepository->findOneByIdentifier($normalized);
        if (!$user instanceof Utilisateur) {
            return false;
        }

        $this->ensurePasswordResetOtpTableExists();

        $otp = $this->generateOtp();
        $hash = password_hash($otp, PASSWORD_BCRYPT);
        $expiresAt = $this->newExpiryDateTime()->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'UPDATE password_reset_otp SET used = 1 WHERE user_id = ?',
            [$user->getId()]
        );

        $this->connection->insert('password_reset_otp', [
            'user_id' => $user->getId(),
            'otp_hash' => $hash,
            'resend_count' => 0,
            'attempts' => 0,
            'used' => 0,
            'expires_at' => $expiresAt,
        ]);

        $this->sendEmail(
            $normalized,
            'Sakan - Code de reinitialisation mot de passe',
            "Votre code de reinitialisation est : {$otp}\n\nValide 5 minutes. Ne partagez pas ce code."
        );

        return true;
    }

    /**
     * @return array{expires_at:?string,seconds_remaining:int,resend_count:int,max_resends:int,can_resend:bool}
     */
    public function getPasswordResetOtpStatus(string $email): array
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return $this->emptyOtpStatus();
        }

        $user = $this->utilisateurRepository->findOneByIdentifier($normalized);
        if (!$user instanceof Utilisateur) {
            return $this->emptyOtpStatus();
        }

        $this->ensurePasswordResetOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, resend_count, expires_at, used FROM password_reset_otp WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$user->getId()]
        );

        return $this->formatOtpStatus($row ?: null);
    }

    public function resendPasswordResetOtp(string $email): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $user = $this->utilisateurRepository->findOneByIdentifier($normalized);
        if (!$user instanceof Utilisateur) {
            return false;
        }

        $this->ensurePasswordResetOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, resend_count, expires_at, used FROM password_reset_otp WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$user->getId()]
        );

        $nextWindow = $this->buildNextResendWindow($row ?: null);
        if ($nextWindow === null) {
            return false;
        }

        $otp = $this->generateOtp();
        $hash = password_hash($otp, PASSWORD_BCRYPT);
        $expiresAt = $nextWindow['expires_at'];
        $resendCount = $nextWindow['resend_count'];

        $this->connection->executeStatement(
            'UPDATE password_reset_otp SET used = 1 WHERE user_id = ? AND used = 0',
            [$user->getId()]
        );

        $this->connection->insert('password_reset_otp', [
            'user_id' => $user->getId(),
            'otp_hash' => $hash,
            'resend_count' => $resendCount,
            'attempts' => 0,
            'used' => 0,
            'expires_at' => $expiresAt,
        ]);

        $this->sendEmail(
            $normalized,
            'Sakan - Code de reinitialisation mot de passe',
            "Votre nouveau code de reinitialisation est : {$otp}\n\nValide 5 minutes. Ne partagez pas ce code."
        );

        return true;
    }

    public function verifyPasswordResetOtp(string $email, string $otp, int $maxAttempts = self::OTP_MAX_ATTEMPTS): bool
    {
        $normalized = strtolower(trim($email));
        $sanitizedOtp = (string) preg_replace('/\D/', '', trim($otp));
        if ($normalized === '' || strlen($sanitizedOtp) !== 6) {
            return false;
        }

        $user = $this->utilisateurRepository->findOneByIdentifier($normalized);
        if (!$user instanceof Utilisateur) {
            return false;
        }

        $this->ensurePasswordResetOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, otp_hash, attempts, expires_at FROM password_reset_otp WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1',
            [$user->getId()]
        );

        if (!$row) {
            return false;
        }

        $rowId = (int) $row['id'];
        $attempts = (int) $row['attempts'];
        $expiresAt = new \DateTimeImmutable((string) $row['expires_at']);
        if ($expiresAt <= new \DateTimeImmutable() || $attempts >= $maxAttempts) {
            $this->markPasswordResetOtpUsed($rowId);

            return false;
        }

        if (!password_verify($sanitizedOtp, (string) $row['otp_hash'])) {
            $this->incrementPasswordResetOtpAttempts($rowId, $maxAttempts);

            return false;
        }

        $this->markPasswordResetOtpUsed($rowId);

        return true;
    }

    public function sendLoginTwoFactorCode(Utilisateur $user): bool
    {
        if (!$user->getId() || !$user->getEmail()) {
            return false;
        }

        $this->ensureLoginTwoFactorOtpTableExists();

        $otp = $this->generateOtp();
        $hash = password_hash($otp, PASSWORD_BCRYPT);
        $expiresAt = $this->newExpiryDateTime()->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'UPDATE login_2fa_otp SET used = 1 WHERE user_id = ?',
            [$user->getId()]
        );

        $this->connection->insert('login_2fa_otp', [
            'user_id' => $user->getId(),
            'otp_hash' => $hash,
            'resend_count' => 0,
            'attempts' => 0,
            'used' => 0,
            'expires_at' => $expiresAt,
        ]);

        $this->sendEmail(
            strtolower(trim((string) $user->getEmail())),
            'Sakan - Verification 2FA',
            "Votre code de verification 2FA est : {$otp}\n\nCe code est valable 5 minutes."
        );

        return true;
    }

    /**
     * @return array{expires_at:?string,seconds_remaining:int,resend_count:int,max_resends:int,can_resend:bool}
     */
    public function getLoginTwoFactorOtpStatus(Utilisateur $user): array
    {
        if (!$user->getId()) {
            return $this->emptyOtpStatus();
        }

        $this->ensureLoginTwoFactorOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, resend_count, expires_at, used FROM login_2fa_otp WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$user->getId()]
        );

        return $this->formatOtpStatus($row ?: null);
    }

    public function resendLoginTwoFactorCode(Utilisateur $user): bool
    {
        if (!$user->getId() || !$user->getEmail()) {
            return false;
        }

        $this->ensureLoginTwoFactorOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, resend_count, expires_at, used FROM login_2fa_otp WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$user->getId()]
        );

        $nextWindow = $this->buildNextResendWindow($row ?: null);
        if ($nextWindow === null) {
            return false;
        }

        $otp = $this->generateOtp();
        $hash = password_hash($otp, PASSWORD_BCRYPT);
        $expiresAt = $nextWindow['expires_at'];
        $resendCount = $nextWindow['resend_count'];

        $this->connection->executeStatement(
            'UPDATE login_2fa_otp SET used = 1 WHERE user_id = ? AND used = 0',
            [$user->getId()]
        );

        $this->connection->insert('login_2fa_otp', [
            'user_id' => $user->getId(),
            'otp_hash' => $hash,
            'resend_count' => $resendCount,
            'attempts' => 0,
            'used' => 0,
            'expires_at' => $expiresAt,
        ]);

        $this->sendEmail(
            strtolower(trim((string) $user->getEmail())),
            'Sakan - Verification 2FA',
            "Votre nouveau code de verification 2FA est : {$otp}\n\nCe code est valable 5 minutes."
        );

        return true;
    }

    public function verifyLoginTwoFactorCode(Utilisateur $user, string $otp, int $maxAttempts = self::OTP_MAX_ATTEMPTS): bool
    {
        if (!$user->getId()) {
            return false;
        }

        $sanitizedOtp = (string) preg_replace('/\D/', '', trim($otp));
        if (strlen($sanitizedOtp) !== 6) {
            return false;
        }

        $this->ensureLoginTwoFactorOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, otp_hash, attempts, expires_at FROM login_2fa_otp WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1',
            [$user->getId()]
        );

        if (!$row) {
            return false;
        }

        $rowId = (int) $row['id'];
        $attempts = (int) $row['attempts'];
        $expiresAt = new \DateTimeImmutable((string) $row['expires_at']);
        if ($expiresAt <= new \DateTimeImmutable() || $attempts >= $maxAttempts) {
            $this->markLoginTwoFactorOtpUsed($rowId);

            return false;
        }

        if (!password_verify($sanitizedOtp, (string) $row['otp_hash'])) {
            $this->incrementLoginTwoFactorOtpAttempts($rowId, $maxAttempts);

            return false;
        }

        $this->markLoginTwoFactorOtpUsed($rowId);

        return true;
    }

    public function createRegistrationVerificationOtp(Utilisateur $user): bool
    {
        if (!$user->getId() || !$user->getEmail()) {
            return false;
        }

        try {
            $this->ensureRegistrationVerificationOtpTableExists();

            $otp = $this->generateOtp();
            $hash = password_hash($otp, PASSWORD_BCRYPT);
            $expiresAt = $this->newExpiryDateTime()->format('Y-m-d H:i:s');

            $this->connection->executeStatement(
                'UPDATE registration_verification_otp SET used = 1 WHERE user_id = ?',
                [$user->getId()]
            );

            $this->connection->insert('registration_verification_otp', [
                'user_id' => $user->getId(),
                'otp_hash' => $hash,
                'resend_count' => 0,
                'attempts' => 0,
                'used' => 0,
                'expires_at' => $expiresAt,
            ]);

            $this->sendEmail(
                strtolower(trim((string) $user->getEmail())),
                'Sakan - Verification de votre inscription',
                "Votre code de verification d'inscription est : {$otp}\n\nValide 5 minutes. Saisissez ce code pour activer votre compte."
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @return array{expires_at:?string,seconds_remaining:int,resend_count:int,max_resends:int,can_resend:bool}
     */
    public function getRegistrationVerificationOtpStatus(Utilisateur $user): array
    {
        if (!$user->getId()) {
            return $this->emptyOtpStatus();
        }

        $this->ensureRegistrationVerificationOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, resend_count, expires_at, used FROM registration_verification_otp WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$user->getId()]
        );

        return $this->formatOtpStatus($row ?: null);
    }

    public function resendRegistrationVerificationOtp(Utilisateur $user): bool
    {
        if (!$user->getId() || !$user->getEmail()) {
            return false;
        }

        $this->ensureRegistrationVerificationOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, resend_count, expires_at, used FROM registration_verification_otp WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$user->getId()]
        );

        $nextWindow = $this->buildNextResendWindow($row ?: null);
        if ($nextWindow === null) {
            return false;
        }

        $otp = $this->generateOtp();
        $hash = password_hash($otp, PASSWORD_BCRYPT);
        $expiresAt = $nextWindow['expires_at'];
        $resendCount = $nextWindow['resend_count'];

        $this->connection->executeStatement(
            'UPDATE registration_verification_otp SET used = 1 WHERE user_id = ? AND used = 0',
            [$user->getId()]
        );

        $this->connection->insert('registration_verification_otp', [
            'user_id' => $user->getId(),
            'otp_hash' => $hash,
            'resend_count' => $resendCount,
            'attempts' => 0,
            'used' => 0,
            'expires_at' => $expiresAt,
        ]);

        $this->sendEmail(
            strtolower(trim((string) $user->getEmail())),
            'Sakan - Verification de votre inscription',
            "Votre nouveau code de verification d'inscription est : {$otp}\n\nValide 5 minutes. Saisissez ce code pour activer votre compte."
        );

        return true;
    }

    public function verifyRegistrationVerificationOtp(Utilisateur $user, string $otp, int $maxAttempts = self::OTP_MAX_ATTEMPTS): bool
    {
        if (!$user->getId()) {
            return false;
        }

        $sanitizedOtp = (string) preg_replace('/\D/', '', trim($otp));
        if (strlen($sanitizedOtp) !== 6) {
            return false;
        }

        try {
            $this->ensureRegistrationVerificationOtpTableExists();

            $row = $this->connection->fetchAssociative(
                'SELECT id, otp_hash, attempts, expires_at FROM registration_verification_otp WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1',
                [$user->getId()]
            );
        } catch (\Throwable) {
            return false;
        }

        if (!$row) {
            return false;
        }

        $rowId = (int) $row['id'];
        $attempts = (int) $row['attempts'];
        $expiresAt = new \DateTimeImmutable((string) $row['expires_at']);
        if ($expiresAt <= new \DateTimeImmutable() || $attempts >= $maxAttempts) {
            $this->markRegistrationVerificationOtpUsed($rowId);

            return false;
        }

        if (!password_verify($sanitizedOtp, (string) $row['otp_hash'])) {
            $this->incrementRegistrationVerificationOtpAttempts($rowId, $maxAttempts);

            return false;
        }

        $this->markRegistrationVerificationOtpUsed($rowId);

        return true;
    }

    public function createEmailChangeOtp(Utilisateur $user, string $newEmail): bool
    {
        if (!$user->getId() || !$user->getEmail()) {
            return false;
        }

        $normalizedNewEmail = strtolower(trim($newEmail));
        if ($normalizedNewEmail === '') {
            return false;
        }

        try {
            $this->ensureEmailChangeOtpTableExists();

            $otp = $this->generateOtp();
            $hash = password_hash($otp, PASSWORD_BCRYPT);
            $expiresAt = $this->newExpiryDateTime()->format('Y-m-d H:i:s');

            $this->connection->executeStatement(
                'UPDATE email_change_otp SET used = 1 WHERE user_id = ?',
                [$user->getId()]
            );

            $this->connection->insert('email_change_otp', [
                'user_id' => $user->getId(),
                'new_email' => $normalizedNewEmail,
                'otp_hash' => $hash,
                'resend_count' => 0,
                'attempts' => 0,
                'used' => 0,
                'expires_at' => $expiresAt,
            ]);

            $oldEmail = strtolower(trim((string) $user->getEmail()));
            $this->sendEmail(
                $oldEmail,
                'Sakan - Verification du changement d adresse email',
                "Votre code OTP pour confirmer le changement de votre adresse email est : {$otp}\n\nNouvelle adresse demandee : {$normalizedNewEmail}\n\nValide 5 minutes. Si vous n'etes pas a l'origine de cette demande, ignorez cet email."
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @return array{expires_at:?string,seconds_remaining:int,resend_count:int,max_resends:int,can_resend:bool}
     */
    public function getEmailChangeOtpStatus(Utilisateur $user, string $newEmail): array
    {
        if (!$user->getId()) {
            return $this->emptyOtpStatus();
        }

        $normalizedNewEmail = strtolower(trim($newEmail));
        if ($normalizedNewEmail === '') {
            return $this->emptyOtpStatus();
        }

        $this->ensureEmailChangeOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, resend_count, expires_at, used FROM email_change_otp WHERE user_id = ? AND new_email = ? ORDER BY id DESC LIMIT 1',
            [$user->getId(), $normalizedNewEmail]
        );

        return $this->formatOtpStatus($row ?: null);
    }

    public function resendEmailChangeOtp(Utilisateur $user, string $newEmail): bool
    {
        if (!$user->getId() || !$user->getEmail()) {
            return false;
        }

        $normalizedNewEmail = strtolower(trim($newEmail));
        if ($normalizedNewEmail === '') {
            return false;
        }

        $this->ensureEmailChangeOtpTableExists();

        $row = $this->connection->fetchAssociative(
            'SELECT id, resend_count, expires_at, used FROM email_change_otp WHERE user_id = ? AND new_email = ? ORDER BY id DESC LIMIT 1',
            [$user->getId(), $normalizedNewEmail]
        );

        $nextWindow = $this->buildNextResendWindow($row ?: null);
        if ($nextWindow === null) {
            return false;
        }

        $otp = $this->generateOtp();
        $hash = password_hash($otp, PASSWORD_BCRYPT);
        $expiresAt = $nextWindow['expires_at'];
        $resendCount = $nextWindow['resend_count'];

        $this->connection->executeStatement(
            'UPDATE email_change_otp SET used = 1 WHERE user_id = ? AND new_email = ? AND used = 0',
            [$user->getId(), $normalizedNewEmail]
        );

        $this->connection->insert('email_change_otp', [
            'user_id' => $user->getId(),
            'new_email' => $normalizedNewEmail,
            'otp_hash' => $hash,
            'resend_count' => $resendCount,
            'attempts' => 0,
            'used' => 0,
            'expires_at' => $expiresAt,
        ]);

        $oldEmail = strtolower(trim((string) $user->getEmail()));
        $this->sendEmail(
            $oldEmail,
            'Sakan - Verification du changement d adresse email',
            "Votre nouveau code OTP pour confirmer le changement de votre adresse email est : {$otp}\n\nNouvelle adresse demandee : {$normalizedNewEmail}\n\nValide 5 minutes. Si vous n'etes pas a l'origine de cette demande, ignorez cet email."
        );

        return true;
    }

    public function verifyEmailChangeOtp(Utilisateur $user, string $newEmail, string $otp, int $maxAttempts = self::OTP_MAX_ATTEMPTS): bool
    {
        if (!$user->getId()) {
            return false;
        }

        $normalizedNewEmail = strtolower(trim($newEmail));
        $sanitizedOtp = (string) preg_replace('/\D/', '', trim($otp));
        if ($normalizedNewEmail === '' || strlen($sanitizedOtp) !== 6) {
            return false;
        }

        try {
            $this->ensureEmailChangeOtpTableExists();

            $row = $this->connection->fetchAssociative(
                'SELECT id, otp_hash, attempts, expires_at FROM email_change_otp WHERE user_id = ? AND new_email = ? AND used = 0 ORDER BY id DESC LIMIT 1',
                [$user->getId(), $normalizedNewEmail]
            );
        } catch (\Throwable) {
            return false;
        }

        if (!$row) {
            return false;
        }

        $rowId = (int) $row['id'];
        $attempts = (int) $row['attempts'];
        $expiresAt = new \DateTimeImmutable((string) $row['expires_at']);
        if ($expiresAt <= new \DateTimeImmutable() || $attempts >= $maxAttempts) {
            $this->markEmailChangeOtpUsed($rowId);

            return false;
        }

        if (!password_verify($sanitizedOtp, (string) $row['otp_hash'])) {
            $this->incrementEmailChangeOtpAttempts($rowId, $maxAttempts);

            return false;
        }

        $this->markEmailChangeOtpUsed($rowId);

        return true;
    }

    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function newExpiryDateTime(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify('+' . self::OTP_EXPIRY_MINUTES . ' minutes');
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{expires_at:string,resend_count:int}|null
     */
    private function buildNextResendWindow(?array $row): ?array
    {
        $status = $this->formatOtpStatus($row);
        if (!$status['can_resend']) {
            return null;
        }

        if ($status['seconds_remaining'] === 0) {
            return [
                'expires_at' => $this->newExpiryDateTime()->format('Y-m-d H:i:s'),
                'resend_count' => 0,
            ];
        }

        return [
            'expires_at' => (string) $status['expires_at'],
            'resend_count' => max(0, (int) ($row['resend_count'] ?? 0)) + 1,
        ];
    }

    /**
     * @return array{expires_at:?string,seconds_remaining:int,resend_count:int,max_resends:int,can_resend:bool}
     */
    private function emptyOtpStatus(): array
    {
        return [
            'expires_at' => null,
            'seconds_remaining' => 0,
            'resend_count' => 0,
            'max_resends' => self::OTP_MAX_RESENDS,
            'can_resend' => false,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{expires_at:?string,seconds_remaining:int,resend_count:int,max_resends:int,can_resend:bool}
     */
    private function formatOtpStatus(?array $row): array
    {
        if ($row === null) {
            return $this->emptyOtpStatus();
        }

        $used = (int) ($row['used'] ?? 0) === 1;
        $resendCount = max(0, (int) ($row['resend_count'] ?? 0));
        $expiresAtValue = isset($row['expires_at']) ? trim((string) $row['expires_at']) : '';
        if ($expiresAtValue === '') {
            return [
                'expires_at' => null,
                'seconds_remaining' => 0,
                'resend_count' => $resendCount,
                'max_resends' => self::OTP_MAX_RESENDS,
                'can_resend' => false,
            ];
        }

        $expiresAt = new \DateTimeImmutable($expiresAtValue);
        $secondsRemaining = max(0, $expiresAt->getTimestamp() - time());

        if ($used) {
            return [
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'seconds_remaining' => $secondsRemaining,
                'resend_count' => $resendCount,
                'max_resends' => self::OTP_MAX_RESENDS,
                'can_resend' => false,
            ];
        }

        return [
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'seconds_remaining' => $secondsRemaining,
            'resend_count' => $resendCount,
            'max_resends' => self::OTP_MAX_RESENDS,
            'can_resend' => $secondsRemaining === 0 || $resendCount < self::OTP_MAX_RESENDS,
        ];
    }

    private function sendEmail(string $to, string $subject, string $body): void
    {
        $email = (new Email())
            ->from('noreply@sakan.local')
            ->to($to)
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }

    private function markPasswordResetOtpUsed(int $rowId): void
    {
        $this->connection->executeStatement('UPDATE password_reset_otp SET used = 1 WHERE id = ?', [$rowId]);
    }

    private function incrementPasswordResetOtpAttempts(int $rowId, int $maxAttempts): void
    {
        $this->connection->executeStatement('UPDATE password_reset_otp SET attempts = attempts + 1 WHERE id = ?', [$rowId]);
        $attempts = (int) $this->connection->fetchOne('SELECT attempts FROM password_reset_otp WHERE id = ?', [$rowId]);
        if ($attempts >= $maxAttempts) {
            $this->markPasswordResetOtpUsed($rowId);
        }
    }

    private function markLoginTwoFactorOtpUsed(int $rowId): void
    {
        $this->connection->executeStatement('UPDATE login_2fa_otp SET used = 1 WHERE id = ?', [$rowId]);
    }

    private function incrementLoginTwoFactorOtpAttempts(int $rowId, int $maxAttempts): void
    {
        $this->connection->executeStatement('UPDATE login_2fa_otp SET attempts = attempts + 1 WHERE id = ?', [$rowId]);
        $attempts = (int) $this->connection->fetchOne('SELECT attempts FROM login_2fa_otp WHERE id = ?', [$rowId]);
        if ($attempts >= $maxAttempts) {
            $this->markLoginTwoFactorOtpUsed($rowId);
        }
    }

    private function markRegistrationVerificationOtpUsed(int $rowId): void
    {
        $this->connection->executeStatement('UPDATE registration_verification_otp SET used = 1 WHERE id = ?', [$rowId]);
    }

    private function incrementRegistrationVerificationOtpAttempts(int $rowId, int $maxAttempts): void
    {
        $this->connection->executeStatement('UPDATE registration_verification_otp SET attempts = attempts + 1 WHERE id = ?', [$rowId]);
        $attempts = (int) $this->connection->fetchOne('SELECT attempts FROM registration_verification_otp WHERE id = ?', [$rowId]);
        if ($attempts >= $maxAttempts) {
            $this->markRegistrationVerificationOtpUsed($rowId);
        }
    }

    private function markEmailChangeOtpUsed(int $rowId): void
    {
        $this->connection->executeStatement('UPDATE email_change_otp SET used = 1 WHERE id = ?', [$rowId]);
    }

    private function incrementEmailChangeOtpAttempts(int $rowId, int $maxAttempts): void
    {
        $this->connection->executeStatement('UPDATE email_change_otp SET attempts = attempts + 1 WHERE id = ?', [$rowId]);
        $attempts = (int) $this->connection->fetchOne('SELECT attempts FROM email_change_otp WHERE id = ?', [$rowId]);
        if ($attempts >= $maxAttempts) {
            $this->markEmailChangeOtpUsed($rowId);
        }
    }

    private function ensurePasswordResetOtpTableExists(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS password_reset_otp (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                otp_hash VARCHAR(256) NOT NULL,
                resend_count INT NOT NULL DEFAULT 0,
                attempts INT NOT NULL DEFAULT 0,
                used TINYINT(1) NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES utilisateur(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );

        $this->ensureOtpTableColumnExists('password_reset_otp', 'resend_count', 'INT NOT NULL DEFAULT 0');
    }

    private function ensureLoginTwoFactorOtpTableExists(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS login_2fa_otp (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                otp_hash VARCHAR(256) NOT NULL,
                resend_count INT NOT NULL DEFAULT 0,
                attempts INT NOT NULL DEFAULT 0,
                used TINYINT(1) NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES utilisateur(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );

        $this->ensureOtpTableColumnExists('login_2fa_otp', 'resend_count', 'INT NOT NULL DEFAULT 0');
    }

    private function ensureRegistrationVerificationOtpTableExists(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS registration_verification_otp (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                otp_hash VARCHAR(256) NOT NULL,
                resend_count INT NOT NULL DEFAULT 0,
                attempts INT NOT NULL DEFAULT 0,
                used TINYINT(1) NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES utilisateur(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );

        $this->ensureOtpTableColumnExists('registration_verification_otp', 'resend_count', 'INT NOT NULL DEFAULT 0');
    }

    private function ensureEmailChangeOtpTableExists(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS email_change_otp (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                new_email VARCHAR(180) NOT NULL,
                otp_hash VARCHAR(256) NOT NULL,
                resend_count INT NOT NULL DEFAULT 0,
                attempts INT NOT NULL DEFAULT 0,
                used TINYINT(1) NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES utilisateur(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );

        $this->ensureOtpTableColumnExists('email_change_otp', 'resend_count', 'INT NOT NULL DEFAULT 0');
    }

    private function ensureOtpTableColumnExists(string $table, string $column, string $definition): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if ((int) $exists === 0) {
            $this->connection->executeStatement(sprintf('ALTER TABLE %s ADD %s %s', $table, $column, $definition));
        }
    }
}
