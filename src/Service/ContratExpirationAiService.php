<?php

namespace App\Service;

use App\Entity\Contrat;
use App\Entity\Utilisateur;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Alerte fin de bail : repère les contrats ACTIF se terminant sous 7 jours
 * et génère un rappel en français via Google Gemini (texte uniquement).
 */
class ContratExpirationAiService
{
    private const MAX_JOURS = 7;

    private const GEMINI_MODEL = 'gemini-2.5-flash-lite';

    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . self::GEMINI_MODEL . ':generateContent';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param iterable<Contrat> $contrats
     *
     * @return list<array{contrat: Contrat, jours_restants: int}>
     */
    public function collectExpiringSoon(iterable $contrats, int $maxJours = self::MAX_JOURS): array
    {
        $today = new \DateTimeImmutable('today');
        $out = [];

        foreach ($contrats as $c) {
            if ($c->getStatut() !== 'ACTIF') {
                continue;
            }
            $j = $this->daysUntilContractEnd($c->getDateFin(), $today);
            if ($j === null || $j > $maxJours) {
                continue;
            }
            $out[] = ['contrat' => $c, 'jours_restants' => $j];
        }

        usort($out, static fn (array $a, array $b): int => $a['jours_restants'] <=> $b['jours_restants']);

        return $out;
    }

    /**
     * @param list<array{contrat: Contrat, jours_restants: int}> $items
     */
    public function generateReminderText(Utilisateur $user, array $items): ?string
    {
        $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        if ($apiKey === '' || $items === []) {
            return null;
        }

        $prenom = $this->guessPrenom($user);
        $lines = [];
        foreach ($items as $row) {
            /** @var Contrat $ct */
            $ct = $row['contrat'];
            $j = $row['jours_restants'];
            $titre = $ct->getAnnonce()?->getTitre() ?? 'Bien #' . $ct->getAnnonce()?->getId();
            $fin = '';
            try {
                $fin = $ct->getDateFin() ? (new \DateTimeImmutable($ct->getDateFin()))->format('d/m/Y') : '';
            } catch (\Exception) {
            }
            $lines[] = sprintf('- « %s » : fin du bail le %s, il reste %d jour(s) avant la fin.', $titre, $fin ?: '—', $j);
        }

        $prompt = <<<PROMPT
Tu es l'assistant virtuel de la plateforme immobilière tunisienne SAKAN.
Rédige UN SEUL message court et chaleureux en français (4 à 6 phrases maximum, ton professionnel et rassurant).
Commence par saluer le locataire par son prénom : « {$prenom} ».
Contexte factuel (ne rien inventer d'autre) :
{$this->implodeLines($lines)}

Le message doit :
- rappeler poliment que le ou les baux concernés touchent à leur fin ;
- inviter à contacter le ou les propriétaires pour un renouvellement, une prorogation ou l'organisation de la sortie ;
- mentionner SAKAN comme facilitateur d'information (sans promesse juridique).

Réponds uniquement par le texte du message, sans titre ni markdown, sans guillemets autour du prénom au-delà de la première phrase.
PROMPT;

        try {
            $url = self::GEMINI_URL . '?key=' . urlencode($apiKey);
            $body = [
                'contents' => [[
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.65,
                    'maxOutputTokens' => 400,
                    'thinkingConfig' => ['thinkingBudget' => 0],
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($body, JSON_UNESCAPED_UNICODE),
                'timeout' => 25,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('ContratExpirationAiService: HTTP ' . $response->getStatusCode());

                return null;
            }

            $data = $response->toArray(false);
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!\is_string($text) || $text === '') {
                $this->logger->warning('ContratExpirationAiService: réponse Gemini vide');

                return null;
            }

            return $this->cleanAiText($text);
        } catch (\Throwable $e) {
            $this->logger->notice('ContratExpirationAiService: ' . $e->getMessage());

            return null;
        }
    }

    public function guessPrenom(Utilisateur $user): string
    {
        $nom = trim((string) $user->getNom());
        if ($nom !== '') {
            $parts = preg_split('/\s+/u', $nom, 2) ?: [];

            return $parts[0] ?? 'cher locataire';
        }
        $email = (string) $user->getEmail();
        if ($email !== '' && str_contains($email, '@')) {
            return explode('@', $email, 2)[0];
        }

        return 'cher locataire';
    }

    /**
     * Message de secours si Gemini est indisponible.
     *
     * @param list<array{contrat: Contrat, jours_restants: int}> $items
     */
    public function buildFallbackMessage(Utilisateur $user, array $items): string
    {
        $prenom = $this->guessPrenom($user);
        if ($items === []) {
            return '';
        }

        $parts = [];
        foreach ($items as $row) {
            $ct = $row['contrat'];
            $j = $row['jours_restants'];
            $titre = $ct->getAnnonce()?->getTitre() ?? 'votre bien';
            $fin = '';
            try {
                $fin = $ct->getDateFin() ? (new \DateTimeImmutable($ct->getDateFin()))->format('d/m/Y') : '';
            } catch (\Exception) {
            }
            $parts[] = sprintf(
                'votre bail pour « %s » se termine dans %d jour(s)%s',
                $titre,
                $j,
                $fin !== '' ? sprintf(' (fin prévue le %s)', $fin) : ''
            );
        }

        $liste = implode(' ; ', $parts);

        return sprintf(
            'Bonjour %s, SAKAN vous informe que %s. Pensez à recontacter votre propriétaire pour la suite (renouvellement, état des lieux de sortie, etc.). Merci de votre confiance.',
            $prenom,
            $liste
        );
    }

    private function daysUntilContractEnd(?string $dateFin, \DateTimeImmutable $today): ?int
    {
        if ($dateFin === null || $dateFin === '') {
            return null;
        }
        try {
            $end = new \DateTimeImmutable($dateFin);
        } catch (\Exception) {
            return null;
        }
        $endDay = $end->setTime(0, 0, 0);
        $today0 = $today->setTime(0, 0, 0);
        if ($endDay < $today0) {
            return null;
        }

        return (int) $today0->diff($endDay)->days;
    }

    /** @param array<string> $lines */
    private function implodeLines(array $lines): string
    {
        return implode("\n", $lines);
    }

    private function cleanAiText(string $text): string
    {
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        $text = str_replace(['**', '__'], '', $text);

        return trim($text);
    }
}
