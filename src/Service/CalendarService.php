<?php

namespace App\Service;

/**
 * Service d'exportation vers Google Calendar (Porté de JavaFX).
 * Génère des liens d'exportation pour les rendez-vous de visite.
 */
class CalendarService
{
    private const BASE_URL = 'https://calendar.google.com/calendar/r/eventedit';

    /**
     * Génère l'URL Google Calendar pour une visite.
     */
    public function generateGoogleCalendarUrl(int $visiteId, string $titre, \DateTimeInterface $dateHeure, string $adresse): string
    {
        $startDate = $dateHeure->format('Ymd\THis\Z');
        $dateHeureMutable = \DateTime::createFromInterface($dateHeure);
        $endDate = $dateHeureMutable->modify('+1 hour')->format('Ymd\THis\Z');

        $params = [
            'text'     => "Visite Sakan : " . $titre,
            'dates'    => $startDate . '/' . $endDate,
            'details'  => "Rendez-vous de visite pour le bien immobilier Sakan.\nID Visite : " . $visiteId,
            'location' => $adresse,
            'sf'       => 'true',
            'output'   => 'xml'
        ];

        return self::BASE_URL . '?' . http_build_query($params);
    }
}
