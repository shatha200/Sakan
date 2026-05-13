<?php

namespace App\Service;

use App\Repository\AnnonceRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Système Expert : Gestion du Budget et Intelligence Marché
 * Ce service ne touche pas aux paiements réels, il fournit uniquement des estimations et de la sécurité.
 */
class BudgetService
{
    private const EXCHANGE_API = 'https://api.exchangerate-api.com/v4/latest/TND';

    public function __construct(
        private AnnonceRepository $annonceRepository,
        private RequestStack $requestStack,
        private HttpClientInterface $httpClient
    ) {}

    /**
     * Génère un défi mathématique pour la sécurité anti-bot
     * @return array<string, mixed>
     */
    public function generateCaptchaChallenge(): array
    {
        $a = rand(1, 10);
        $b = rand(1, 10);
        $sum = $a + $b;

        $session = $this->requestStack->getSession();
        $session->set('sakan_captcha_result', $sum);

        return [
            'question' => "Combien font $a + $b ?",
            'placeholder' => "Votre réponse..."
        ];
    }

    /**
     * Vérifie la réponse au captcha
     */
    public function verifyCaptcha(int $answer): bool
    {
        $session = $this->requestStack->getSession();
        $expected = $session->get('sakan_captcha_result');
        
        // On nettoie la session après vérification
        $session->remove('sakan_captcha_result');

        return $expected !== null && (int)$answer === (int)$expected;
    }

    /**
     * Analyse le prix par rapport au marché local (Expert Logic)
     * @return array<string, mixed>
     */
    public function getMarketComparison(string $city, float $currentPrice): array
    {
        $allInCity = $this->annonceRepository->findBy(['ville' => $city]);
        if (count($allInCity) < 2) {
            return [
                'label' => 'Prix Unique',
                'color' => '#64748b',
                'icon' => 'fa-circle-info',
                'desc' => 'Pas assez de données pour comparer dans cette ville.'
            ];
        }

        $total = 0;
        foreach ($allInCity as $a) {
            $total += (float)$a->getPrix();
        }
        $average = $total / count($allInCity);

        $diff = (($currentPrice - $average) / $average) * 100;

        if ($diff < -15) {
            return [
                'label' => 'Excellente Affaire',
                'color' => '#10b981',
                'icon' => 'fa-bolt',
                'desc' => 'Ce prix est nettement inférieur à la moyenne locale (-' . round(abs($diff)) . '%).'
            ];
        } elseif ($diff < 5) {
            return [
                'label' => 'Prix Juste',
                'color' => '#3b82f6',
                'icon' => 'fa-check-circle',
                'desc' => 'Ce prix est dans la moyenne du marché pour cette ville.'
            ];
        } else {
            return [
                'label' => 'Haut de gamme',
                'color' => '#f59e0b',
                'icon' => 'fa-gem',
                'desc' => 'Ce bien est proposé au-dessus de la moyenne locale (+' . round($diff) . '%).'
            ];
        }
    }

    /**
     * Calcule le budget estimé avec conversion de devise
     * @return array<string, mixed>
     */
    public function getBudgetEstimation(float $monthlyPrice, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $diff = $start->diff($end);
        $months = $diff->y * 12 + $diff->m + ($diff->d / 30);
        $totalTND = round($monthlyPrice * $months, 2);

        $rates = $this->getExchangeRates();
        
        return [
            'total_tnd' => $totalTND,
            'total_eur' => isset($rates['EUR']) ? round($totalTND * $rates['EUR'], 2) : null,
            'total_usd' => isset($rates['USD']) ? round($totalTND * $rates['USD'], 2) : null,
            'duration_months' => round($months, 1)
        ];
    }

    /**
     * Récupère les taux de change (IA Feature API)
     * @return array<string, mixed>
     */
    private function getExchangeRates(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::EXCHANGE_API, [
                'timeout' => 2
            ]);
            $data = $response->toArray();
            return $data['rates'] ?? [];
        } catch (\Exception $e) {
            // Fallback si l'API est indisponible
            return ['EUR' => 0.30, 'USD' => 0.32]; 
        }
    }
}
