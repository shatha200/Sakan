<?php

namespace App\Service;

class BrevoService
{
    /**
     * Sends an email notification.
     */
    public function sendEmail(string $to, string $name, string $subject, string $htmlContent): bool
    {
        // Placeholder: simulate success
        error_log("BrevoService: Simulating email to $to: $subject");
        return true;
    }
}
