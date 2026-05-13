<?php

namespace App\Controller;

use App\Entity\Contrat;
use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Repository\AnnonceRepository;
use App\Repository\ContratRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ReservationController extends AbstractController
{
    public function __construct(
        private \App\Service\BudgetService $budgetService
    ) {}

    // =========================================================
    // LOCATAIRE — Créer une réservation
    // Route  : POST /locataire/annonce/{id}/reserver
    // =========================================================
    #[Route('/locataire/annonce/{id}/reserver', name: 'tenant_reservation_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function create(
        int $id,
        Request $request,
        AnnonceRepository $annonceRepository,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $entityManager,
        \App\Service\HolidayService $holidayService,
        \App\Service\GeocodingService $geocodingService
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\Utilisateur) return $this->redirectToRoute('app_login');

        $annonce = $annonceRepository->find($id);
        if (!$annonce) throw $this->createNotFoundException('Annonce non trouvée');

        // IA Security : Geocoding Validation — DISABLED
        // Le contrôle Nominatim bloquait les réservations légitimes quand l'API
        // OSM était indisponible ou quand l'adresse mélangeait Nominatim+GeoNames
        // (faux négatifs fréquents). Conservé en commentaire pour référence.
        // if (!$geocodingService->validateAddress($annonce->getAdresse())) {
        //     $isAjax = $request->isXmlHttpRequest() || $request->request->get('ajax') === '1' || $request->query->get('ajax') === '1';
        //     $msg = "Attention : L'adresse de ce bien n'est pas certifiée par le cadastre numérique (OpenStreetMap). Prudence lors de la réservation.";
        //     if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
        //     $this->addFlash('warning', $msg);
        // }

        $dateDebutStr = $request->request->get('dateDebut');
        $dateFinStr = $request->request->get('dateFin');

        $isAjax = $request->isXmlHttpRequest() || $request->request->get('ajax') === '1' || $request->query->get('ajax') === '1';

        // Security : Math Captcha
        $captchaAnswer = $request->request->get('captcha_answer');
        if (!$this->budgetService->verifyCaptcha((int)$captchaAnswer)) {
            $msg = 'Réponse au captcha incorrecte. Veuillez réessayer.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        // Validation des dates
        if (!$dateDebutStr || !$dateFinStr) {
            $msg = 'Les dates de début et de fin sont obligatoires.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        // Conversion des dates
        try {
            $startDate = new \DateTime((string)$dateDebutStr);
            $endDate = new \DateTime((string)$dateFinStr);
        } catch (\Exception $e) {
            $msg = 'Format de date invalide.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        if ($endDate <= $startDate) {
            $msg = 'La date de fin doit être après la date de début.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        // IA Feature API : Check for Public Holidays
        $holidayName = $holidayService->checkPublicHoliday($startDate);
        if ($holidayName && !$request->request->get('ignore_holiday')) {
            $msg = "La date d’arrivée tombe sur un jour férié ({$holidayName}). Le propriétaire pourrait ne pas être disponible pour la remise des clés.";
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg, 'is_holiday_warning' => true]);
            $this->addFlash('warning', $msg);
        }

        if ($reservationRepository->hasConflict($annonce, $startDate, $endDate)) {
            $msg = 'Ce bien est déjà réservé pour cette période.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        // Création et sauvegarde
        $reservation = new Reservation();
        $reservation->setAnnonce($annonce);
        $reservation->setLocataire($user);
        $reservation->setDateDebut($startDate);
        $reservation->setDateFin($endDate);
        $reservation->setStatut('En attente');

        try {
            $entityManager->persist($reservation);
            $entityManager->flush();
            
            if ($isAjax) {
                return new JsonResponse(['success' => true, 'redirect' => $this->generateUrl('tenant_reservations')]);
            }
            
            $this->addFlash('success', 'Votre demande de réservation a été envoyée au propriétaire !');
            return $this->redirectToRoute('tenant_reservations');
        } catch (\Exception $e) {
            error_log("DEBUG Reservation - Exception: " . $e->getMessage());
            $msg = 'Erreur technique lors de la sauvegarde.';
            if ($isAjax) return new JsonResponse(['success' => false, 'error' => $msg]);
            $this->addFlash('error', $msg);
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }
    }

    // =========================================================
    // LOCATAIRE — Liste de ses réservations
    // Route  : GET /locataire/reservations
    // =========================================================
    #[Route('/locataire/reservations', name: 'tenant_reservations')]
    public function index(
        ReservationRepository $reservationRepository,
        EntityManagerInterface $entityManager,
        \App\Service\QrCodeService $qrCodeService
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) return $this->redirectToRoute('app_login');

        $reservations = $reservationRepository->createQueryBuilder('r')
            ->join('r.annonce', 'a')
            ->where('r.locataire = :user')
            ->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        // Construction de la map des contrats existants
        // Clé = ID réservation, Valeur = objet Contrat
        $contratsMap = [];
        $contratsExistants = $entityManager
            ->getRepository(\App\Entity\Contrat::class)
            ->findBy(['locataire' => $user]);

        foreach ($contratsExistants as $c) {
            $reservation = $c->getReservation();
            if ($reservation && $c->getStatut() !== 'SUPPRIME') {
                $contratsMap[$reservation->getId()] = $c;
            }
        }

        // IA Feature : Secure QR Code Pass Generation
        $qrCodesMap = [];
        foreach ($reservations as $res) {
            if (strtolower($res->getStatut()) === 'approuvée' || strtolower($res->getStatut()) === 'approuvee') {
                $qrCodesMap[$res->getId()] = $qrCodeService->generateVisitPassUrl(
                    $res->getId(),
                    (string)$user->getNom(),
                    $res->getDateDebut() ? $res->getDateDebut()->format('Y-m-d') : ''
                );
            }
        }

        return $this->render('locataire/mes_reservations.html.twig', [
            'reservations' => $reservations,
            'contratsMap'  => $contratsMap,
            'qrCodesMap'   => $qrCodesMap,
        ]);
    }

    // =========================================================
    // LOCATAIRE — Créer un contrat depuis la réservation
    // Route  : GET /locataire/reservation/{id}/contrat/creer
    // =========================================================
    #[Route('/locataire/reservation/{id}/contrat/creer', name: 'tenant_reservation_creer_contrat', methods: ['GET'])]
    public function creerContrat(
        int $id,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        if (!$user) return $this->redirectToRoute('app_login');

        $reservation = $reservationRepository->find($id);

        if (!$reservation || $reservation->getLocataire() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à cette réservation.');
        }

        if (strtolower((string)$reservation->getStatut()) !== 'approuvée') {
            $this->addFlash('error', 'Le contrat ne peut être créé que pour une réservation approuvée.');
            return $this->redirectToRoute('tenant_reservations');
        }

        // Vérifier qu'il n'y a pas déjà un contrat pour cette réservation
        $existingContrat = $em->getRepository(Contrat::class)->findOneBy(['reservation' => $reservation]);
        if ($existingContrat) {
            $this->addFlash('info', 'Un contrat existe déjà pour cette réservation.');
            return $this->redirectToRoute('tenant_contrats');
        }

        return $this->render('locataire/reservation_creer_contrat.html.twig', [
            'reservation' => $reservation,
            'annonce' => $reservation->getAnnonce(),
        ]);
    }

    // =========================================================
    // LOCATAIRE — Modifier une réservation (standard + AJAX)
    // Route  : GET|POST /locataire/reservation/{id}/edit
    // =========================================================
    #[Route('/locataire/reservation/{id}/edit', name: 'tenant_reservation_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            if ($this->isAjax($request)) {
                return new JsonResponse(['error' => 'Non authentifié', 'redirect' => $this->generateUrl('app_login')], 401);
            }
            return $this->redirectToRoute('app_login');
        }

        $reservation = $reservationRepository->find($id);
        if (!$reservation) {
            if ($this->isAjax($request)) {
                return new JsonResponse(['error' => 'Réservation introuvable.'], 404);
            }
            throw $this->createNotFoundException();
        }

        // Sécurité : seul le locataire propriétaire peut modifier
        if ($reservation->getLocataire() !== $user) {
            if ($this->isAjax($request)) {
                return new JsonResponse(['error' => 'Accès refusé.'], 403);
            }
            throw new AccessDeniedException();
        }

        // ── Traitement POST ──────────────────────────────────────────
        if ($request->isMethod('POST')) {
            $dateDebutStr = $request->request->get('dateDebut');
            $dateFinStr   = $request->request->get('dateFin');

            // Validation
            if (!$dateDebutStr || !$dateFinStr) {
                $errorMsg = 'Les dates de début et de fin sont obligatoires.';
                if ($this->isAjax($request)) {
                    $html = $this->renderView('locataire/_reservation_form_ajax.html.twig', [
                        'reservation' => $reservation,
                        'error'       => $errorMsg,
                    ]);
                    return new JsonResponse(['html' => $html]);
                }
                $this->addFlash('error', $errorMsg);
                return $this->redirectToRoute('tenant_reservation_edit', ['id' => $id]);
            }

            try {
                $startDate = new \DateTime((string)$dateDebutStr);
                $endDate   = new \DateTime((string)$dateFinStr);
            } catch (\Exception $e) {
                $errorMsg = 'Format de date invalide.';
                if ($this->isAjax($request)) {
                    $html = $this->renderView('locataire/_reservation_form_ajax.html.twig', [
                        'reservation' => $reservation,
                        'error'       => $errorMsg,
                    ]);
                    return new JsonResponse(['html' => $html]);
                }
                $this->addFlash('error', $errorMsg);
                return $this->redirectToRoute('tenant_reservation_edit', ['id' => $id]);
            }

            if ($endDate <= $startDate) {
                $errorMsg = 'La date de fin doit être après la date de début.';
                if ($this->isAjax($request)) {
                    $html = $this->renderView('locataire/_reservation_form_ajax.html.twig', [
                        'reservation' => $reservation,
                        'error'       => $errorMsg,
                    ]);
                    return new JsonResponse(['html' => $html]);
                }
                $this->addFlash('error', $errorMsg);
                return $this->redirectToRoute('tenant_reservation_edit', ['id' => $id]);
            }

            // Sauvegarde
            $reservation->setDateDebut($startDate);
            $reservation->setDateFin($endDate);
            $entityManager->flush();

            if ($this->isAjax($request)) {
                return new JsonResponse(['success' => true, 'message' => 'Réservation modifiée avec succès.']);
            }
            $this->addFlash('success', 'Réservation modifiée avec succès.');
            return $this->redirectToRoute('tenant_reservations');
        }

        // ── Affichage GET ────────────────────────────────────────────
        // Requête AJAX : on retourne uniquement le fragment HTML du formulaire
        if ($this->isAjax($request)) {
            $html = $this->renderView('locataire/_reservation_form_ajax.html.twig', [
                'reservation' => $reservation,
                'error'       => null,
            ]);
            return new JsonResponse(['html' => $html]);
        }

        // Requête normale : page complète
        return $this->render('locataire/reservation_edit.html.twig', [
            'reservation' => $reservation,
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
    // LOCATAIRE — Annuler une réservation
    // Route  : POST /locataire/reservation/{id}/cancel
    // =========================================================
    #[Route('/locataire/reservation/{id}/cancel', name: 'tenant_reservation_cancel', methods: ['POST'])]
    public function cancel(
        int $id,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $user = $this->getUser();
        if (!$user) return $this->redirectToRoute('app_login');

        $reservation = $reservationRepository->find($id);
        if (!$reservation) throw $this->createNotFoundException();

        // Sécurité : seul le locataire propriétaire peut annuler
        if ($reservation->getLocataire() !== $user) {
            throw new AccessDeniedException();
        }

        $reservation->setStatut('Annulée');
        $entityManager->flush();
        $this->addFlash('success', 'Réservation annulée.');

        return $this->redirectToRoute('tenant_reservations');
    }

    // =========================================================
    // PROPRIÉTAIRE — Liste des réservations de ses biens
    // Route  : GET /proprietaire/reservations
    // =========================================================
    #[Route('/proprietaire/reservations', name: 'owner_reservations')]
    public function ownerIndex(ReservationRepository $reservationRepository, ContratRepository $contratRepository): Response
    {
        $user = $this->getUser();
        if (!$user) return $this->redirectToRoute('app_login');

        $reservations = $reservationRepository->createQueryBuilder('r')
            ->join('r.annonce', 'a')
            ->where('a.proprietaire = :user')
            ->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        $contratsParReservation = [];
        foreach ($reservations as $reservation) {
            $contratsParReservation[$reservation->getId()] = $contratRepository->findOneBy(['reservation' => $reservation]);
        }

        return $this->render('owner/reservations.html.twig', [
            'reservations' => $reservations,
            'contratsParReservation' => $contratsParReservation,
        ]);
    }

    // =========================================================
    // PROPRIÉTAIRE — Approuver une réservation
    // Route  : POST /proprietaire/reservation/{id}/approve
    // =========================================================
    #[Route('/proprietaire/reservation/{id}/approve', name: 'owner_reservation_approve', methods: ['POST'])]
    public function approve(
        Reservation $reservation,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$reservation->getAnnonce() || $reservation->getAnnonce()->getProprietaire() !== $this->getUser()) {
            throw new AccessDeniedException();
        }

        // Vérifier conflit avant approbation (un autre locataire a pu réserver entre-temps)
        if (!$reservation->getDateDebut() || !$reservation->getDateFin() || $reservationRepository->hasConflict(
            $reservation->getAnnonce(),
            $reservation->getDateDebut(),
            $reservation->getDateFin(),
            $reservation->getId()
        )) {
            $this->addFlash('danger', 'Impossible d\'approuver : ce bien est déjà réservé sur cette période.');
            return $this->redirectToRoute('owner_reservations');
        }

        $reservation->setStatut('Approuvée');
        $entityManager->flush();
        $this->addFlash('success', 'La réservation a été approuvée.');

        return $this->redirectToRoute('owner_reservations');
    }

    // =========================================================
    // PROPRIÉTAIRE — Refuser une réservation
    // Route  : POST /proprietaire/reservation/{id}/reject
    // =========================================================
    #[Route('/proprietaire/reservation/{id}/reject', name: 'owner_reservation_reject', methods: ['POST'])]
    public function reject(
        Reservation $reservation,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$reservation->getAnnonce() || $reservation->getAnnonce()->getProprietaire() !== $this->getUser()) {
            throw new AccessDeniedException();
        }

        $reservation->setStatut('Refusée');
        $entityManager->flush();
        $this->addFlash('danger', 'La réservation a été refusée.');

        return $this->redirectToRoute('owner_reservations');
    }

    // =========================================================
    // API — Simuler le budget (AJAX)
    // =========================================================
    #[Route('/api/reservation/simulate-budget', name: 'api_reservation_simulate', methods: ['POST'])]
    public function simulateBudget(Request $request): JsonResponse
    {
        $price = (float)$request->request->get('price');
        $startStr = $request->request->get('start');
        $endStr = $request->request->get('end');

        if (!$price || !$startStr || !$endStr) {
            return new JsonResponse(['error' => 'Données incomplètes'], 400);
        }

        try {
            $start = new \DateTime((string)$startStr);
            $end = new \DateTime((string)$endStr);
            
            if ($end <= $start) {
                return new JsonResponse(['error' => 'Dates invalides'], 400);
            }

            $estimation = $this->budgetService->getBudgetEstimation($price, $start, $end);
            return new JsonResponse($estimation);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Erreur de calcul'], 500);
        }
    }
}