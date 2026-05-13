<?php

namespace App\Service;

/**
 * Assistant Virtuel de Réservation (Porté de JavaFX).
 * Analyse les messages et fournit des réponses contextuelles sur les logements,
 * contrats et visites. Support multilingue simulé.
 */
class ChatbotService
{
    private const KNOWLEDGE_BASE = [
        'salut|bonjour|bonsoir|hello|hi|salam' => [
            "Bonjour ! 👋 Je suis l'assistant Sakan.\n\n" .
            "Je peux vous accompagner pour :\n" .
            "• Trouver et réserver un studio étudiant 🏠\n" .
            "• Organiser vos visites et optimiser vos trajets 📍\n" .
            "• Consulter la météo pour vos rendez-vous ☀️\n" .
            "• Obtenir des conseils sur les contrats de bail 📜"
        ],
        'réserv|louer|location' => [
            "🏠 **Processus de Réservation :**\n" .
            "1. Identifiez le bien idéal sur votre catalogue.\n" .
            "2. Cliquez sur **'Réserver'** pour envoyer votre demande.\n" .
            "3. Le statut sera **'En attente'** jusqu'à validation du propriétaire.\n" .
            "4. Dès validation, vous pourrez signer votre contrat en ligne."
        ],
        'visite|visiter|voir|créneau|horaire' => [
            "🗓 **Gestion des Visites :**\n" .
            "Il est vivement conseillé de visiter avant de signer :\n" .
            "- Utilisez l'option **'Réserver visite'** sur l'annonce.\n" .
            "- Notre système vérifie automatiquement vos éventuels conflits d'horaire.\n" .
            "- Chaque visite affiche la météo prévue pour vous aider à mieux choisir votre trajet !"
        ],
        'prix|coût|tarif|combien|budget' => [
            "💰 **Conseils Budget :**\n" .
            "• Les loyers incluent souvent la connexion WiFi, vérifiez les détails techniques.\n" .
            "• Utilisez nos outils de comparaison (multi-critères).\n" .
            "• Prévoyez généralement un mois de caution lors de la signature."
        ],
        'itinéraire|itineraire|parcours|gps|trajet|maps' => [
            "🚀 **Optimisation d'Itinéraire :**\n" .
            "Sakan utilise un algorithme de tri par proximité couplé à Google Maps :\n" .
            "1. Allez dans 'Mes Visites'.\n" .
            "2. Cliquez sur **'Optimiser le parcours'**.\n" .
            "3. Le système calcule le trajet le plus court entre vos rendez-vous.\n" .
            "4. Vous pouvez ensuite suivre l'itinéraire sur votre smartphone !"
        ],
        'merci|thanks|super|parfait' => [
            "Je vous en prie ! 😊 Bonne chance dans votre recherche.\n" .
            "N'hésitez pas si vous avez d'autres questions."
        ]
    ];

    public function __construct(
        private \Symfony\Bundle\SecurityBundle\Security $security,
        private \App\Repository\ReservationRepository $reservationRepository,
        private \App\Repository\VisiteRepository $visiteRepository
    ) {}

    public function getResponse(string $userMessage): string
    {
        if (empty(trim($userMessage))) {
            return "Je n'ai pas compris votre message. Posez-moi une question sur vos réservations !";
        }

        $lower = mb_strtolower(trim($userMessage));
        $user = $this->security->getUser();

        // --- Intent: Reservations ---
        if ($this->isSimilar($lower, ['réservation', 'reservation', 'resrvation', 'louer', 'mes réservations'])) {
            if (!$user) return "🔒 Vous devez être connecté pour voir vos réservations.";
            
            // On utilise un QueryBuilder robuste pour éviter les erreurs de méthode inexistante
            $reservations = $this->reservationRepository->createQueryBuilder('r')
                ->join('r.annonce', 'a')
                ->where('r.locataire = :u OR a.proprietaire = :u')
                ->setParameter('u', $user)
                ->orderBy('r.dateDebut', 'DESC')
                ->setMaxResults(5)
                ->getQuery()
                ->getResult();

            if (empty($reservations)) return "📍 Vous n'avez aucune réservation active pour le moment dans la base Sakan.";

            $res = "📑 **Voici vos dernières réservations :**\n";
            foreach ($reservations as $r) {
                $statusEmoji = ($r->getStatut() === 'Approuvée' || $r->getStatut() === 'Confirmée') ? '✅' : (($r->getStatut() === 'Refusée' || $r->getStatut() === 'Annulée') ? '❌' : '⏳');
                $res .= "• **{$r->getAnnonce()->getTitre()}** : $statusEmoji {$r->getStatut()} (Du {$r->getDateDebut()->format('d/m')} au {$r->getDateFin()->format('d/m')})\n";
            }
            return $res;
        }

        // --- Intent: Visits ---
        if ($this->isSimilar($lower, ['visite', 'visiter', 'vistite', 'rendez-vous', 'rdv'])) {
            if (!$user) return "🔒 Connectez-vous pour consulter vos rendez-vous de visite.";
            
            $visites = $this->visiteRepository->createQueryBuilder('v')
                ->join('v.annonce', 'a')
                ->where('v.locataire = :u OR a.proprietaire = :u')
                ->setParameter('u', $user)
                ->orderBy('v.dateHeure', 'DESC')
                ->setMaxResults(5)
                ->getQuery()
                ->getResult();

            if (empty($visites)) return "🗓 Aucune visite programmée dans votre agenda Sakan.";

            $res = "🚗 **Vos prochains rendez-vous (Expert Sync) :**\n";
            foreach ($visites as $v) {
                $res .= "• **{$v->getAnnonce()->getTitre()}** : 📅 {$v->getDateHeure()->format('d/m à H:i')} ({$v->getStatut()})\n";
            }
            return $res;
        }

        // --- Fallback to Knowledge Base ---
        foreach (self::KNOWLEDGE_BASE as $pattern => $responses) {
            $keywords = explode('|', $pattern);
            if ($this->isSimilar($lower, $keywords)) {
                return $responses[array_rand($responses)];
            }
        }

        return "🤔 Je ne suis pas sûr de comprendre votre demande.\n\n" .
               "Essayez par exemple :\n" .
               "• \"Quelles sont mes réservations ?\"\n" .
               "• \"Liste de mes visites\"\n" .
               "• \"Comment louer un studio ?\"";
    }

    /**
     * Algorithme de correspondance floue (Fuzzy Logic - Orthographe)
     */
    /** @param array<string> $keywords */
    private function isSimilar(string $input, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            // Correspondance directe
            if (str_contains($input, $kw)) return true;
            
            // Calcul de distance de Levenshtein (pour les fautes de frappe)
            $words = explode(' ', $input);
            foreach ($words as $word) {
                if (strlen($word) > 3 && (levenshtein($word, $kw) <= 2)) return true;
            }
        }
        return false;
    }

    public function getWelcomeMessage(): string
    {
        $hour = (int) (new \DateTime())->format('H');
        $greeting = ($hour < 12) ? "Bonjour" : (($hour < 18) ? "Bon après-midi" : "Bonsoir");

        return "$greeting ! 👋 Je suis l'Assistant IA Sakan.\n\n" .
               "🏠 Réservations | 🗓 Visites | 📍 Itinéraires\n\n" .
               "Comment puis-je vous aider ?";
    }
}
