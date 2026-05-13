<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\Visite;
use App\Repository\AnnonceRepository;
use App\Repository\VisiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use App\Service\WeatherService;
use App\Service\ReservationAlgorithmService;
use App\Service\QrCodeService;
use App\Service\CalendarService;
use App\Service\HolidayService;

class VisiteController extends AbstractController
{
    public function __construct(
        private WeatherService $weatherService,
        private ReservationAlgorithmService $algoService,
        private QrCodeService $qrCodeService,
        private CalendarService $calendarService,
        private HolidayService $holidayService,
        private \App\Service\GeocodingService $geocodingService
    ) {}
    // =========================================================
    // LOCATAIRE — Créer une visite
    // Route  : POST /locataire/annonce/{id}/visiter
    // =========================================================
    #[Route('/locataire/annonce/{id}/visiter', name: 'tenant_visite_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(
        int $id,
        Request $request,
        AnnonceRepository $annonceRepository,
        VisiteRepository $visiteRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) return $this->redirectToRoute('app_login');

        $annonce = $annonceRepository->find($id);
        if (!$annonce) throw $this->createNotFoundException('Annonce introuvable');

        // IA Security : Geocoding Validation
        if (!$this->geocodingService->validateAddress((string)$annonce->getAdresse())) {
            $msg = "Attention : L'adresse de ce bien n'a pas pu être formellement identifiée sur la carte. Veuillez rester vigilant.";
            // On ne bloque pas la visite, mais on alerte
            $isAjax = $request->isXmlHttpRequest() || $request->request->get('ajax') === '1' || $request->query->get('ajax') === '1';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('warning', $msg);
        }

        // Récupération directe des données POST (sans formulaire Symfony)
        $dateHeureStr = $request->request->get('dateHeure');
        $commentaire = $request->request->get('commentaire');

        $isAjax = $request->isXmlHttpRequest() || $request->request->get('ajax') === '1' || $request->query->get('ajax') === '1';

        // Validation
        if (!$dateHeureStr) {
            $msg = 'La date et heure de visite sont obligatoires.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        // Conversion de la date
        try {
            $dateHeure = new \DateTime((string)$dateHeureStr);
        } catch (\Exception $e) {
            $msg = 'Format de date invalide.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        if ($dateHeure <= new \DateTime()) {
            $msg = 'La date de visite doit être dans le futur.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        // IA Feature API : Check for Public Holidays
        $holidayName = $this->holidayService->checkPublicHoliday($dateHeure);
        if ($holidayName && !$request->request->get('ignore_holiday')) {
            $msg = "La date sélectionnée tombe sur un jour férié ({$holidayName}). Le propriétaire pourrait ne pas être disponible.";
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg, 'is_holiday_warning' => true]);
            $this->addFlash('warning', $msg);
        }

        // IA Conflict Resolver (Ported from Java)
        $conflits = $this->algoService->detectConflits((int)$user->getId(), $dateHeure);
        if (!empty($conflits)) {
            $suggestions = $this->algoService->suggestAlternatives((int)$user->getId(), $dateHeure);
            $suggestionStr = "";
            if (!empty($suggestions)) {
                $suggestionStr = "<br>Créneaux suggérés par l'IA : " . implode(', ', array_map(fn($s) => '<strong>' . $s->format('H:i') . '</strong>', $suggestions));
            }

            $msg = 'Attention ! Vous avez déjà une visite prévue à proximité de cet horaire.' . $suggestionStr;
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', strip_tags($msg));
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        if ($visiteRepository->hasConflict($annonce, $dateHeure)) {
            $msg = 'Une visite est déjà planifiée pour ce bien à ce créneau.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        // Création et sauvegarde
        $visite = new Visite();
        $visite->setAnnonce($annonce);
        $visite->setLocataire($user);
        $visite->setDateHeure($dateHeure);
        $visite->setCommentaire((string)$commentaire);
        $visite->setStatut('En attente');

        try {
            $entityManager->persist($visite);
            $entityManager->flush();
            
            // IA Feature: Generate Calendar URL
            $calendarUrl = $this->calendarService->generateGoogleCalendarUrl(
                (int)$visite->getId(),
                (string)($visite->getAnnonce() ? $visite->getAnnonce()->getTitre() : ''),
                $visite->getDateHeure() ?: new \DateTime(),
                (string)($visite->getAnnonce() ? $visite->getAnnonce()->getAdresse() : 'Tunis')
            );

            if ($isAjax) {
                return new JsonResponse(['success' => true, 'agenda_url' => $calendarUrl, 'redirect' => $this->generateUrl('tenant_visites')]);
            }
            
            // Non-AJAX fallback
            $this->addFlash('success', 'Votre demande de visite a été envoyée au propriétaire !');
            $this->addFlash('agenda_url', $calendarUrl);
            return $this->redirectToRoute('tenant_visites');
        } catch (\Exception $e) {
            error_log("DEBUG Visite - Exception: " . $e->getMessage());
            $msg = 'Erreur technique lors de la sauvegarde.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }
    }

    // =========================================================
    // LOCATAIRE — Liste de ses visites
    // Route  : GET /locataire/visites
    // =========================================================
    #[Route('/locataire/visites', name: 'tenant_visites')]
    public function index(VisiteRepository $visiteRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $visites = $visiteRepository->findBy(
            ['locataire' => $user],
            ['dateHeure' => 'ASC']
        );

        // Enrichir avec la météo et le Pass QR Code (IA logic)
        $visitesWithWeather = [];
        foreach ($visites as $v) {
            $passUrl = null;
            if (in_array(strtolower((string)$v->getStatut()), ['acceptée', 'acceptee', 'confirmée', 'confirmee'])) {
                $passUrl = $this->qrCodeService->generateVisitPassUrl(
                    (int)$v->getId(), 
                    (string)$user->getNom(), 
                    $v->getDateHeure() ? $v->getDateHeure()->format('d/m/Y H:i') : ''
                );
            }

            $visitesWithWeather[] = [
                'entity' => $v,
                'weather' => $this->weatherService->getWeatherForDate(
                    $v->getDateHeure() ?: new \DateTime(),
                    (string)($v->getAnnonce() ? $v->getAnnonce()->getAdresse() : 'Tunis')
                ),
                'pass_url' => $passUrl
            ];
        }

        return $this->render('locataire/mes_visites.html.twig', [
            'visites' => $visitesWithWeather,
        ]);
    }

    /**
     * Optimisation d'Itinéraire (Porté de JavaFX).
     * Trie les visites du jour par proximité géographique.
     */
    #[Route('/locataire/visites/optimiser', name: 'tenant_visites_optimize')]
    public function optimize(VisiteRepository $visiteRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $visites = $visiteRepository->findBy(['locataire' => $user]);
        $sortedVisits = $this->algoService->optimizeItinerary($visites);
        
        $todayStr = (new \DateTime())->format('Y-m-d');
        $todayVisits = array_filter($sortedVisits, function($v) use ($todayStr) {
            return $v->getDateHeure() && $v->getDateHeure()->format('Y-m-d') === $todayStr;
        });

        if (empty($todayVisits)) {
            $this->addFlash('error', 'Aucune visite prévue pour aujourd\'hui à optimiser. 🗺️');
            return $this->redirectToRoute('tenant_visites');
        }

        $url = 'https://www.google.com/maps/dir/';
        foreach ($todayVisits as $v) {
            if ($v->getAnnonce() && $v->getAnnonce()->getAdresse()) {
                $url .= str_replace(' ', '+', $v->getAnnonce()->getAdresse()) . '/';
            }
        }

        return $this->redirect($url);
    }

    // =========================================================
    // LOCATAIRE — Modifier une visite (standard + AJAX)
    // Route  : GET|POST /locataire/visite/{id}/edit
    // =========================================================
    #[Route('/locataire/visite/{id}/edit', name: 'tenant_visite_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        VisiteRepository $visiteRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            if ($this->isAjax($request)) {
                return new JsonResponse(['error' => 'Non authentifié', 'redirect' => $this->generateUrl('app_login')], 401);
            }
            return $this->redirectToRoute('app_login');
        }

        $visite = $visiteRepository->find($id);
        if (!$visite) {
            if ($this->isAjax($request)) {
                return new JsonResponse(['error' => 'Visite introuvable.'], 404);
            }
            throw $this->createNotFoundException();
        }

        // Sécurité : seul le locataire propriétaire peut modifier
        if ($visite->getLocataire() !== $user) {
            if ($this->isAjax($request)) {
                return new JsonResponse(['error' => 'Accès refusé.'], 403);
            }
            throw new AccessDeniedException();
        }

        // ── Traitement POST ──────────────────────────────────────────
        if ($request->isMethod('POST')) {
            $dateHeureStr = $request->request->get('dateHeure');
            $commentaire  = $request->request->get('commentaire');

            if (!$dateHeureStr) {
                $errorMsg = 'La date et heure sont obligatoires.';
                if ($this->isAjax($request)) {
                    $html = $this->renderView('locataire/_visite_form_ajax.html.twig', [
                        'visite' => $visite,
                        'error'  => $errorMsg,
                    ]);
                    return new JsonResponse(['html' => $html]);
                }
                $this->addFlash('error', $errorMsg);
                return $this->redirectToRoute('tenant_visite_edit', ['id' => $id]);
            }

            try {
                $dateHeure = new \DateTime((string)$dateHeureStr);
            } catch (\Exception $e) {
                $errorMsg = 'Format de date invalide.';
                if ($this->isAjax($request)) {
                    $html = $this->renderView('locataire/_visite_form_ajax.html.twig', [
                        'visite' => $visite,
                        'error'  => $errorMsg,
                    ]);
                    return new JsonResponse(['html' => $html]);
                }
                $this->addFlash('error', $errorMsg);
                return $this->redirectToRoute('tenant_visite_edit', ['id' => $id]);
            }

            if ($dateHeure <= new \DateTime()) {
                $errorMsg = 'La date doit être dans le futur.';
                if ($this->isAjax($request)) {
                    $html = $this->renderView('locataire/_visite_form_ajax.html.twig', [
                        'visite' => $visite,
                        'error'  => $errorMsg,
                    ]);
                    return new JsonResponse(['html' => $html]);
                }
                $this->addFlash('error', $errorMsg);
                return $this->redirectToRoute('tenant_visite_edit', ['id' => $id]);
            }

            // Sauvegarde
            $visite->setDateHeure($dateHeure);
            $visite->setCommentaire((string)$commentaire);
            $entityManager->flush();

            if ($this->isAjax($request)) {
                return new JsonResponse(['success' => true, 'message' => 'Visite modifiée avec succès.']);
            }
            $this->addFlash('success', 'Visite modifiée avec succès.');
            return $this->redirectToRoute('tenant_visites');
        }

        // ── Affichage GET ────────────────────────────────────────────
        // Requête AJAX : fragment HTML du formulaire
        if ($this->isAjax($request)) {
            $html = $this->renderView('locataire/_visite_form_ajax.html.twig', [
                'visite' => $visite,
                'error'  => null,
            ]);
            return new JsonResponse(['html' => $html]);
        }

        // Requête normale : page complète
        return $this->render('locataire/visite_edit.html.twig', [
            'visite' => $visite,
        ]);
    }

    /**
     * Détermine si la requête est de type AJAX.
     */
    private function isAjax(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || $request->query->get('ajax') === '1'
            || $request->request->get('ajax') === '1';
    }

    // =========================================================
    // LOCATAIRE — Annuler une visite
    // Route  : POST /locataire/visite/{id}/cancel
    // =========================================================
    #[Route('/locataire/visite/{id}/cancel', name: 'tenant_visite_cancel', methods: ['POST'])]
    public function cancel(
        int $id,
        VisiteRepository $visiteRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        if (!$user) return $this->redirectToRoute('app_login');

        $visite = $visiteRepository->find($id);
        if (!$visite) throw $this->createNotFoundException();

        // Sécurité : seul le locataire propriétaire peut annuler
        if ($visite->getLocataire() !== $user) {
            throw new AccessDeniedException();
        }

        $visite->setStatut('Annulée');
        $entityManager->flush();
        $this->addFlash('success', 'Visite annulée.');

        return $this->redirectToRoute('tenant_visites');
    }

    // =========================================================
    // PROPRIÉTAIRE — Liste des visites de ses biens
    // Route  : GET /proprietaire/visites
    // =========================================================
    #[Route('/proprietaire/visites', name: 'owner_visites')]
    public function ownerIndex(VisiteRepository $visiteRepository): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $visites = $visiteRepository->createQueryBuilder('v')
            ->join('v.annonce', 'a')
            ->where('a.proprietaire = :user')
            ->setParameter('user', $user)
            ->orderBy('v.dateHeure', 'ASC')
            ->getQuery()
            ->getResult();

        $visitesWithWeather = [];
        foreach ($visites as $v) {
            $weather = $this->weatherService->getWeatherForDate(
                $v->getDateHeure(),
                $v->getAnnonce()->getAdresse() ?: 'Tunis'
            );
            $visitesWithWeather[] = [
                'entity' => $v,
                'weather' => $weather
            ];
        }

        return $this->render('owner/visites.html.twig', [
            'visites_data' => $visitesWithWeather,
            'visites' => $visites // Keep this in case template needs the raw list
        ]);
    }

    // =========================================================
    // PROPRIÉTAIRE — Confirmer une visite
    // Route  : POST /proprietaire/visite/{id}/approve
    // =========================================================
    #[Route('/proprietaire/visite/{id}/approve', name: 'owner_visite_approve', methods: ['POST'])]
    public function approve(
        Visite $visite,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$visite->getAnnonce() || $visite->getAnnonce()->getProprietaire() !== $this->getUser()) {
            throw new AccessDeniedException();
        }

        $visite->setStatut('Confirmée');
        $entityManager->flush();
        $this->addFlash('success', 'La visite a été confirmée.');

        return $this->redirectToRoute('owner_visites');
    }

    // =========================================================
    // PROPRIÉTAIRE — Refuser une visite
    // Route  : POST /proprietaire/visite/{id}/reject
    // =========================================================
    #[Route('/proprietaire/visite/{id}/reject', name: 'owner_visite_reject', methods: ['POST'])]
    public function reject(
        Visite $visite,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$visite->getAnnonce() || $visite->getAnnonce()->getProprietaire() !== $this->getUser()) {
            throw new AccessDeniedException();
        }

        $visite->setStatut('Annulée');
        $entityManager->flush();
        $this->addFlash('danger', 'La visite a été refusée.');

        return $this->redirectToRoute('owner_visites');
    }
}