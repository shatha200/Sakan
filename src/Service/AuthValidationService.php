<?php

namespace App\Service;

class AuthValidationService
{
    public function isValidEmail(string $email): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $email);
    }

    public function isValidPassword(string $password): bool
    {
        if (strlen($password) < 6) {
            return false;
        }

        $hasLower = false;
        $hasUpper = false;
        $hasDigit = false;
        foreach (str_split($password) as $char) {
            if (ctype_lower($char)) {
                $hasLower = true;
            } elseif (ctype_upper($char)) {
                $hasUpper = true;
            } elseif (ctype_digit($char)) {
                $hasDigit = true;
            }
        }

        return $hasLower && $hasUpper && $hasDigit;
    }

    public function isValidTunisiaPhone(string $phone): bool
    {
        return (bool) preg_match('/^[2-9][0-9]{7}$/', $phone);
    }

    public function toTunisiaE164(string $phone): string
    {
        if (!$this->isValidTunisiaPhone($phone)) {
            throw new \InvalidArgumentException('Numero tunisien invalide (8 chiffres, commence par 2-9).');
        }

        return '+216' . $phone;
    }
}

