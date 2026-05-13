<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\ProfileImageStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProfileImageController extends AbstractController
{
    #[Route('/profil/avatar', name: 'app_profile_avatar', methods: ['GET'])]
    public function currentUserAvatar(ProfileImageStorage $profileImageStorage): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $localImagePath = $profileImageStorage->resolveProfileImagePath($user);
        if (is_string($localImagePath) && is_file($localImagePath)) {
            $binary = @file_get_contents($localImagePath);
            if (is_string($binary) && $binary !== '') {
                return new Response($binary, Response::HTTP_OK, [
                    'Content-Type' => $this->detectMimeType($binary),
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                ]);
            }
        }

        $binary = $this->extractBinary($user->getPhotoProfil());
        if ($binary !== null && $binary !== '') {
            return new Response($binary, Response::HTTP_OK, [
                'Content-Type' => $this->detectMimeType($binary),
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]);
        }

        return new Response($this->buildSvgFallback((string) $user->getNom()), Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function extractBinary(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $meta = stream_get_meta_data($value);
            if (!empty($meta['seekable'])) {
                @rewind($value);
            }
            $content = stream_get_contents($value);
            return $content === false ? null : $content;
        }

        if (is_string($value)) {
            return $value;
        }

        return null;
    }

    private function detectMimeType(string $binary): string
    {
        $imageInfo = @getimagesizefromstring($binary);
        if (is_array($imageInfo)) {
            $mime = strtolower($imageInfo['mime']);
            if ($mime !== '') {
                return $mime;
            }
        }

        return 'image/jpeg';
    }

    private function buildSvgFallback(string $fullName): string
    {
        $initials = $this->initials($fullName);
        $safeInitials = htmlspecialchars($initials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160" role="img" aria-label="Avatar">
  <defs>
    <linearGradient id="g" x1="0" x2="1" y1="0" y2="1">
      <stop offset="0%" stop-color="#5E17EB"/>
      <stop offset="100%" stop-color="#7c3aed"/>
    </linearGradient>
  </defs>
  <rect width="160" height="160" fill="url(#g)"/>
  <circle cx="80" cy="80" r="78" fill="none" stroke="rgba(255,255,255,0.22)" stroke-width="4"/>
  <text x="80" y="92" text-anchor="middle" fill="#ffffff" font-size="56" font-family="Arial, sans-serif" font-weight="700">{$safeInitials}</text>
</svg>
SVG;
    }

    private function initials(string $fullName): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $fullName));
        if ($normalized === '') {
            return 'U';
        }

        $parts = explode(' ', $normalized);
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($out) >= 2) {
                break;
            }
        }

        return $out !== '' ? $out : 'U';
    }
}
