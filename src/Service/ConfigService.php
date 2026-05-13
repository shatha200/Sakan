<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ConfigService
{
    public function __construct(
        #[Autowire('%gemini_api_key%')]
        private string $geminiApiKey,

        #[Autowire('%brevo_api_key%')]
        private string $brevoApiKey,

        #[Autowire('%brevo_sender_email%')]
        private string $brevoSenderEmail,

        #[Autowire('%brevo_sender_name%')]
        private string $brevoSenderName,

        #[Autowire('%supabase_url%')]
        private string $supabaseUrl,

        #[Autowire('%supabase_key%')]
        private string $supabaseKey,

        #[Autowire('%supabase_bucket%')]
        private string $supabaseBucket,
    ) {
    }

    public function getGeminiApiKey(): string
    {
        return $this->geminiApiKey;
    }

    public function getBrevoApiKey(): string
    {
        return $this->brevoApiKey;
    }

    public function getBrevoSenderEmail(): string
    {
        return $this->brevoSenderEmail;
    }

    public function getBrevoSenderName(): string
    {
        return $this->brevoSenderName;
    }

    public function getSupabaseUrl(): string
    {
        return $this->supabaseUrl;
    }

    public function getSupabaseKey(): string
    {
        return $this->supabaseKey;
    }

    public function getSupabaseBucket(): string
    {
        return $this->supabaseBucket;
    }
}
