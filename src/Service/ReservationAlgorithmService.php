<?php

namespace App\Service;

use App\Entity\Visite;
use App\Entity\Annonce;
use App\Repository\VisiteRepository;

/**
 * Service d'Algorithmes Avancés (Porté de JavaFX).
 * Gère la détection de conflits, les suggestions de créneaux et l'optimisation d'itinéraire.
 */
class ReservationAlgorithmService
{
    public function __construct(
        private VisiteRepository $visiteRepository
    ) {}

    /**
     * Détecte les conflits (IA Conflict Resolver logic).
     * Vérifie si d'autres visites sont prévues à moins de 2h (120 min) d'intervalle.
     * @return list<Visite>
     */
    public function detectConflits(int $locataireId, \DateTimeInterface $dateHeure): array
    {
        $visites = $this->visiteRepository->findBy(['locataire' => $locataireId]);
        $conflits = [];

        foreach ($visites as $v) {
            if ($v->getStatut() !== 'Annulée' && $v->getDateHeure()) {
                $diff = abs($v->getDateHeure()->getTimestamp() - $dateHeure->getTimestamp()) / 60;
                if ($diff < 120) {
                    $conflits[] = $v;
                }
            }
        }

        return $conflits;
    }

    /**
     * Suggère 3 créneaux libres sur la même journée (Simulation Smart Suggest).
     * Créneaux cibles : 9h, 11h, 14h, 16h, 18h.
     * @return list<\DateTime>
     */
    public function suggestAlternatives(int $locataireId, \DateTimeInterface $date): array
    {
        $visites = $this->visiteRepository->findBy(['locataire' => $locataireId]);
        $heuresCandidates = [9, 11, 14, 16, 18];
        $suggestions = [];

        foreach ($heuresCandidates as $h) {
            $candidate = \DateTime::createFromInterface($date);
            $candidate->setTime($h, 0, 0);

            if ($candidate < new \DateTime()) continue;

            $conflict = false;
            foreach ($visites as $v) {
                if ($v->getStatut() !== 'Annulée' && $v->getDateHeure()) {
                    $diff = abs($v->getDateHeure()->getTimestamp() - $candidate->getTimestamp()) / 60;
                    if ($diff < 120) {
                        $conflict = true;
                        break;
                    }
                }
            }

            if (!$conflict) {
                $suggestions[] = $candidate;
                if (count($suggestions) >= 3) break;
            }
        }

        return $suggestions;
    }

    /**
     * Optimise le parcours (Itinerary Optimization).
     * Trie une liste d'annonces par proximité (Simulation basée sur le tri par adresse/ID).
     * @param list<Visite> $visites
     * @return list<Visite>
     */
    public function optimizeItinerary(array $visites): array
    {
        // Dans une version réelle, on utiliserait Google Distance Matrix API.
        // Ici on reproduit l'algorithme de tri par proximité simulé du projet Java.
        usort($visites, function($a, $b) {
            $addrA = $a->getAnnonce() ? $a->getAnnonce()->getAdresse() : '';
            $addrB = $b->getAnnonce() ? $b->getAnnonce()->getAdresse() : '';
            return strcmp((string)$addrA, (string)$addrB);
        });

        return $visites;
    }
}
