<?php

namespace App\Controller;

use App\Entity\Annonce;
use App\Entity\Contrat;
use App\Entity\Reservation;
use App\Entity\ReglePenalite;
use App\Entity\Utilisateur;
use App\Repository\ContratRepository;
use App\Service\ContratFinanceService;
use App\Service\SecurityNotificationService;
use App\Service\SignatureService;
use App\Service\UserSecurityStateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ContratController - Gestion des contrats avec intégration Finance et Authentification
 */
#[Route('/contrat')]
class ContratController extends AbstractController
{
    private ContratRepository $contratRepo;
    private ContratFinanceService $contratFinanceService;
    private UserSecurityStateService $securityState;
    private SignatureService $signatureService;

    public function __construct(
        ContratRepository $contratRepo,
        ContratFinanceService $contratFinanceService,
        UserSecurityStateService $securityState,
        SignatureService $signatureService
    ) {
        $this->contratRepo = $contratRepo;
        $this->contratFinanceService = $contratFinanceService;
        $this->securityState = $securityState;
        $this->signatureService = $signatureService;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ROUTES LOCATAIRE
    // ═══════════════════════════════════════════════════════════════════════════

    // (La liste des contrats locataire est gérée par LocataireController::contrats via la route tenant_contrats)

    #[Route('/voir/{id}', name: 'contrat_voir')]
    public function voir(int $id): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        
        $contrat = $this->contratRepo->find($id);
        
        if (!$contrat) {
            throw $this->createNotFoundException('Contrat non trouvé');
        }

        // Vérifier accès (locataire ou propriétaire)
        if (!$this->canAccessContrat($user, $contrat)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce contrat');
        }

        // Récupérer données financières
        $dashboardData = $this->contratFinanceService->getContratDashboard($id);

        return $this->render('contrat/detail.html.twig', [
            'contrat' => $contrat,
            'dashboard' => $dashboardData,
            'user' => $user,
            'isLocataire' => $user === $contrat->getLocataire(),
            'isProprietaire' => $user === $contrat->getAnnonce()?->getProprietaire(),
        ]);
    }

    /**
     * Parcours locataire : finaliser le contrat (identité, CIN+OCR, signature) sans modifier le schéma BDD.
     * Met à jour le champ existant utilisateur.nom avec « Prénom Nom ».
     */
    #[Route('/locataire/finaliser/{id}', name: 'tenant_contrat_finaliser', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_LOCATAIRE')]
    public function finaliserContratLocataire(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        if (!$this->securityState->isEmailVerified($user)) {
            $this->addFlash('warning', 'Veuillez vérifier votre email pour finaliser le contrat.');
            return $this->redirectToRoute('app_verify_email');
        }

        $contrat = $this->contratRepo->find($id);
        if (!$contrat || $contrat->getLocataire() !== $user) {
            throw $this->createAccessDeniedException('Accès refusé.');
        }

        if ($contrat->getStatut() !== 'EN_ATTENTE_SIGNATURE' || $contrat->isSigneLocataire()) {
            $this->addFlash('info', 'Aucune finalisation requise pour ce contrat.');
            return $this->redirectToRoute('tenant_contrats');
        }

        $annonce = $contrat->getAnnonce();
        $reservation = $contrat->getReservation();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('finaliser_contrat_' . $id, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expirée, veuillez réessayer.');
                return $this->redirectToRoute('tenant_contrat_finaliser', ['id' => $id]);
            }

            if ($request->request->get('cin_verified') !== '1') {
                $this->addFlash('error', 'Veuillez valider votre CIN avec l’OCR.');
                return $this->redirectToRoute('tenant_contrat_finaliser', ['id' => $id]);
            }

            $prenom = trim((string) $request->request->get('prenom_locataire', ''));
            $nom = trim((string) $request->request->get('nom_locataire', ''));
            if ($prenom === '' || $nom === '') {
                $this->addFlash('error', 'Prénom et nom sont obligatoires.');
                return $this->redirectToRoute('tenant_contrat_finaliser', ['id' => $id]);
            }

            $user->setNom(trim($prenom . ' ' . $nom));

            $cinFile = $request->files->get('cin_file');
            if (!$cinFile) {
                $this->addFlash('error', 'La photo du CIN est obligatoire.');
                return $this->redirectToRoute('tenant_contrat_finaliser', ['id' => $id]);
            }

            $uploadDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/public/uploads/cin';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $newFilename = uniqid('cin_loc_') . '.' . $cinFile->guessExtension();
            try {
                $cinFile->move($uploadDir, $newFilename);
                $contrat->setCinImage($newFilename);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de l\'enregistrement du CIN : ' . $e->getMessage());
                return $this->redirectToRoute('tenant_contrat_finaliser', ['id' => $id]);
            }

            $signatureData = $request->request->get('signature_data');
            if (empty($signatureData)) {
                $this->addFlash('error', 'La signature est obligatoire.');
                return $this->redirectToRoute('tenant_contrat_finaliser', ['id' => $id]);
            }

            // ── Vérification intelligente de la signature ──────────────────
            // Compare new signature with profile signature for validation
            $sessionKey = 'sig_attempts_' . $id;
            $attempts = (int) $request->getSession()->get($sessionKey, 0);
            $profileSignature = $user->getSignature();

            if ($profileSignature) {
                // Check if max attempts exceeded
                if ($attempts >= $this->signatureService->getMaxAttempts()) {
                    $this->addFlash('error', 'Nombre maximum de tentatives atteint (3/3). Veuillez enregistrer une nouvelle signature dans votre profil.');
                    return $this->redirectToRoute('tenant_contrat_finaliser', ['id' => $id]);
                }

                try {
                    // Compare signatures: profile signature vs. new signature
                    $score = $this->signatureService->compare($profileSignature, (string)$signatureData);
                    $attempts++;
                    $request->getSession()->set($sessionKey, $attempts);

                    // Validate if similarity meets threshold
                    if (!$this->signatureService->isValid($score)) {
                        $remaining = $this->signatureService->getMaxAttempts() - $attempts;
                        $threshold = $this->signatureService->getThreshold();
                        
                        $this->addFlash('error', sprintf(
                            'Signature différente de celle enregistrée (similarité : %.1f%% — seuil requis : %.0f%%). %d tentative(s) restante(s).',
                            $score,
                            $threshold,
                            $remaining
                        ));
                        return $this->redirectToRoute('tenant_contrat_finaliser', ['id' => $id]);
                    }

                    // Signature validated successfully
                    $request->getSession()->remove($sessionKey);
                    $this->addFlash('success', sprintf('✓ Signature validée (%.1f%% de similarité).', $score));
                } catch (\RuntimeException $e) {
                    $this->addFlash('error', 'Erreur lors de la vérification de signature : ' . $e->getMessage());
                    return $this->redirectToRoute('tenant_contrat_finaliser', ['id' => $id]);
                }
            }
            // ───────────────────────────────────────────────────────────────

            $contrat->setLocataireSignatureImage((string)$signatureData);
            $contrat->setSigneLocataire(true);
            $contrat->setDateSignatureLocataire((new \DateTime())->format('Y-m-d H:i:s'));

            if ($contrat->isFullySigned()) {
                $contrat->setStatut('ACTIF');
                $this->contratFinanceService->creerPaiementsAutomatiques($contrat);
            }

            $this->contratRepo->save($contrat, true);

            $this->addFlash('success', 'Contrat finalisé. Votre signature et votre identité ont été enregistrées.');

            return $this->redirectToRoute('tenant_contrats');
        }

        return $this->render('locataire/finaliser_contrat.html.twig', [
            'contrat' => $contrat,
            'annonce' => $annonce,
            'reservation' => $reservation,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ROUTES PROPRIÉTAIRE
    // ═══════════════════════════════════════════════════════════════════════════

    #[Route('/proprietaire/liste', name: 'proprietaire_contrats_liste')]
    #[IsGranted('ROLE_PROPRIETAIRE')]
    public function listeProprietaire(Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $statutParam = $request->query->get('statut');
        $statutFilter = is_string($statutParam) && $statutParam !== '' ? $statutParam : null;
        $search = (string) $request->query->get('search', '');

        $contrats = $this->contratRepo->findByProprietaire(
            (int) $user->getId(),
            $statutFilter,
            $search !== '' ? $search : null,
        );

        return $this->render('contrat/proprietaire_liste.html.twig', [
            'contrats' => $contrats,
            'user' => $user,
            'search' => $search,
            'statut_filter' => $statutFilter,
        ]);
    }

    #[Route('/proprietaire/nouveau', name: 'contrat_nouveau')]
    #[IsGranted('ROLE_PROPRIETAIRE')]
    public function nouveau(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $annonceId = $request->request->get('annonce_id');
            $locataireEmail = $request->request->get('locataire_email');

            $annonce = $em->getRepository(Annonce::class)->find($annonceId);
            $locataire = $em->getRepository(Utilisateur::class)->findOneBy(['email' => $locataireEmail]);

            if (!$annonce || $annonce->getProprietaire() !== $user) {
                $this->addFlash('error', 'Bien immobilier invalide.');
                return $this->redirectToRoute('contrat_nouveau');
            }

            if (!$locataire) {
                $this->addFlash('error', 'Aucun locataire trouvé avec cet email.');
                return $this->redirectToRoute('contrat_nouveau');
            }

            $contrat = new Contrat();
            $contrat->setAnnonce($annonce);
            $contrat->setLocataire($locataire);
            $contrat->setDateDebut((string) $request->request->get('date_debut'));
            $contrat->setDateFin((string) $request->request->get('date_fin'));
            $contrat->setMontant((string) $request->request->get('montant'));
            $contrat->setStatut('EN_ATTENTE_SIGNATURE'); // Créé par le proprio, on attend les signatures

            // Comme c'est le proprio qui crée, on dit qu'il a implicitement signé ?
            // Laissez-le cliquer exprès sur "Signer" pour que ce soit légal, ou on le pré-signe.
            $this->contratRepo->save($contrat, true);

            // Insertion des règles de pénalités si présentes
            if ($request->request->get('penalite_jourRemise') !== null) {
                $regle = new ReglePenalite();
                $regle->setContrat($contrat);
                $regle->setDelaiGraceJours((int)$request->request->get('penalite_jourRemise', 5));
                $regle->setPenaliteFixe((float)$request->request->get('penalite_montant', 0));
                $regle->setPenalitePourcentage((float)$request->request->get('penalite_pourcentage', 0));
                $regle->setPlafondPourcentage((float)$request->request->get('penalite_plafond', 10));
                
                $em->persist($regle);
                $em->flush();
            }

            // On génère la caution si mentionnée
            $cautionMontant = $request->request->get('caution_montant');
            if (!empty($cautionMontant)) {
                $this->contratFinanceService->creerCaution(
                    $contrat,
                    (float) $cautionMontant,
                    'Caution contractuelle'
                );
            }

            // Générer le workflow financier
            $this->contratFinanceService->creerPaiementsAutomatiques($contrat);

            $this->addFlash('success', 'Contrat créé avec succès. Il est en attente des signatures.');
            return $this->redirectToRoute('proprietaire_contrats_liste');
        }

        $annonces = $em->getRepository(Annonce::class)->findBy(['proprietaire' => $user]);

        return $this->render('contrat/nouveau.html.twig', [
            'annonces' => $annonces,
        ]);
    }

    #[Route('/proprietaire/reservation/{id}/creer-contrat', name: 'owner_reservation_creer_contrat', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PROPRIETAIRE')]
    public function creerDepuisReservation(int $id, Request $request, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $reservation = $em->getRepository(Reservation::class)->find($id);

        if (!$reservation || $reservation->getAnnonce()?->getProprietaire() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à cette réservation.');
        }

        if (strtolower((string)$reservation->getStatut()) !== 'approuvée') {
            $this->addFlash('error', 'Vous ne pouvez créer un contrat que pour une réservation approuvée.');
            return $this->redirectToRoute('owner_reservations');
        }
        
        $existingContrat = $em->getRepository(Contrat::class)->findOneBy(['reservation' => $reservation]);
        if ($existingContrat) {
            $this->addFlash('info', 'Un contrat existe déjà pour cette réservation.');
            return $this->redirectToRoute('proprietaire_contrats_liste');
        }

        if ($request->isMethod('POST')) {
            $prenom = trim((string) $request->request->get('prenom_proprietaire', ''));
            $nom = trim((string) $request->request->get('nom_proprietaire', ''));
            if ($prenom === '' || $nom === '') {
                $this->addFlash('error', 'Prénom et nom du propriétaire sont obligatoires.');
                return $this->redirectToRoute('owner_reservation_creer_contrat', ['id' => $id]);
            }
            if ($request->request->get('cin_verified') !== '1') {
                $this->addFlash('error', 'Veuillez valider votre CIN avec l’OCR avant de confirmer.');
                return $this->redirectToRoute('owner_reservation_creer_contrat', ['id' => $id]);
            }

            $user->setNom(trim($prenom . ' ' . $nom));

            $contrat = new Contrat();
            $contrat->setAnnonce($reservation->getAnnonce());
            $contrat->setLocataire($reservation->getLocataire());
            $contrat->setDateDebut((string) $request->request->get('date_debut'));
            $contrat->setDateFin((string) $request->request->get('date_fin'));
            $contrat->setMontant((string) $request->request->get('montant'));
            $contrat->setReservation($reservation);
            $contrat->setStatut('EN_ATTENTE_SIGNATURE'); // Le propriétaire la crée, en attente de la signature du locataire (et du proprio)

            // Gestion de la pièce d'identité du Propriétaire (CIN)
            $cinFileProp = $request->files->get('cin_file_proprietaire');
            if ($cinFileProp) {
                $uploadDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/public/uploads/cin';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $propFilename = uniqid('cin_prop_') . '.' . $cinFileProp->guessExtension();
                try {
                    $cinFileProp->move($uploadDir, $propFilename);
                    $contrat->setCinImageProprietaire($propFilename);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur lors de l\'enregistrement de votre CIN : ' . $e->getMessage());
                }
            }

            // Gestion de la signature électronique du Propriétaire
            $signatureData = $request->request->get('signature_data');
            if ($signatureData) {
                $contrat->setProprietaireSignatureImage((string)$signatureData);
                $contrat->setSigneProprietaire(true);
                $contrat->setDateSignatureProprietaire((new \DateTime())->format('Y-m-d H:i:s'));
            }

            $this->contratRepo->save($contrat, true);

            // Insertion des règles de pénalités si présentes
            if ($request->request->get('penalite_jourRemise') !== null) {
                $regle = new ReglePenalite();
                $regle->setContrat($contrat);
                $regle->setDelaiGraceJours((int)$request->request->get('penalite_jourRemise', 5));
                $regle->setPenaliteFixe((float)$request->request->get('penalite_montant', 0));
                $regle->setPenalitePourcentage((float)$request->request->get('penalite_pourcentage', 0));
                $regle->setPlafondPourcentage((float)$request->request->get('penalite_plafond', 10));
                
                $em->persist($regle);
                $em->flush();
            }

            // Générer la caution
            $cautionMontant = $request->request->get('caution_montant');
            if (!empty($cautionMontant)) {
                $this->contratFinanceService->creerCaution(
                    $contrat,
                    (float) $cautionMontant,
                    'Caution contractuelle locative'
                );
            }

            // Créer paiements automatiques
            $this->contratFinanceService->creerPaiementsAutomatiques($contrat);

            $this->addFlash('success', 'Contrat créé avec succès ! Il est en attente de la signature du locataire.');
            return $this->redirectToRoute('proprietaire_contrats_liste');
        }

        return $this->render('owner/reservation_creer_contrat.html.twig', [
            'reservation' => $reservation,
            'annonce' => $reservation->getAnnonce(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WORKFLOW LOCATAIRE PROPOSE → PROPRIÉTAIRE CONFIRME
    // ═══════════════════════════════════════════════════════════════════════════

    #[Route('/proposer', name: 'contrat_proposer')]
    #[IsGranted('ROLE_LOCATAIRE')]
    public function proposer(Request $request, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Vérifier sécurité compte
        if (!$this->securityState->isEmailVerified($user)) {
            $this->addFlash('warning', 'Veuillez vérifier votre email pour proposer un contrat.');
            return $this->redirectToRoute('app_verify_email');
        }

        if ($request->isMethod('POST')) {
            $annonce = $em->getReference(Annonce::class, (int) $request->request->get('annonce_id'));

            $contrat = new Contrat();
            $contrat->setAnnonce($annonce);
            $contrat->setLocataire($user);
            $contrat->setDateDebut((string) $request->request->get('date_debut'));
            $contrat->setDateFin((string) $request->request->get('date_fin'));
            $contrat->setMontant((string) $request->request->get('montant'));
            $contrat->setStatut('PROPOSE');

            // Liaison avec la réservation si elle existe
            $reservationId = $request->request->get('reservation_id');
            if ($reservationId) {
                $reservation = $em->getRepository(Reservation::class)->find($reservationId);
                if ($reservation) {
                    $contrat->setReservation($reservation);
                }
            }

            // --- 1. Gestion de la pièce d'identité (CIN) ---
            $cinFile = $request->files->get('cin_file');
            if ($cinFile) {
                // S'assurer que le dossier existe
                $uploadDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/public/uploads/cin';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                
                $newFilename = uniqid('cin_') . '.' . $cinFile->guessExtension();
                try {
                    $cinFile->move($uploadDir, $newFilename);
                    $contrat->setCinImage($newFilename);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur lors de l\'enregistrement de votre pièce d\'identité : ' . $e->getMessage());
                }
            }

            // --- 2. Gestion de la signature électronique du Locataire ---
            $signatureData = $request->request->get('signature_data');
            if ($signatureData) {
                $contrat->setLocataireSignatureImage((string)$signatureData);
                $contrat->setSigneLocataire(true);
                $contrat->setDateSignatureLocataire((new \DateTime())->format('Y-m-d H:i:s'));
            }

            $this->contratRepo->save($contrat, true);

            $this->addFlash('success', 'Contrat proposé avec succès. En attente de confirmation du propriétaire.');

            return $this->redirectToRoute('tenant_contrats');
        }

        // Récupérer toutes les annonces disponibles via DQL
        $annonces = $em->createQuery(
            'SELECT a FROM App\Entity\Annonce a WHERE a.statut = :statut'
        )->setParameter('statut', 'disponible')->getResult();

        return $this->render('contrat/proposer.html.twig', [
            'annonces' => $annonces,
            'user' => $user,
        ]);
    }

    #[Route('/confirmer/{id}', name: 'contrat_confirmer', methods: ['POST'])]
    #[IsGranted('ROLE_PROPRIETAIRE')]
    public function confirmer(Request $request, int $id, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $contrat = $this->contratRepo->find($id);

        if (!$contrat || $contrat->getAnnonce()?->getProprietaire() !== $user) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas confirmer ce contrat.');
        }

        if ($contrat->getStatut() !== 'PROPOSE') {
            $this->addFlash('error', 'Ce contrat ne peut pas être confirmé.');
            return $this->redirectToRoute('proprietaire_contrats_liste');
        }

        // Vérifier token CSRF
        if (!$this->isCsrfTokenValid('confirmer_contrat_' . $id, (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide');
            return $this->redirectToRoute('contrat_voir', ['id' => $id]);
        }

        // === IMPORTANT: La confirmation du propriétaire nécessite maintenant la signature ===
        // Rediriger vers la page de détail où le formulaire de signature avec pénalités est affiché
        $this->addFlash('info', 'Veuillez maintenant signer le contrat et définir les règles de pénalités.');
        return $this->redirectToRoute('contrat_voir', ['id' => $contrat->getId()]);
    }

    #[Route('/refuser/{id}', name: 'contrat_refuser', methods: ['POST'])]
    #[IsGranted('ROLE_PROPRIETAIRE')]
    public function refuser(Request $request, int $id): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $contrat = $this->contratRepo->find($id);

        if (!$contrat || $contrat->getAnnonce()?->getProprietaire() !== $user) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas refuser ce contrat.');
        }

        if ($contrat->getStatut() !== 'PROPOSE') {
            $this->addFlash('error', 'Ce contrat ne peut pas être refusé.');
            return $this->redirectToRoute('proprietaire_contrats_liste');
        }

        // Vérifier token CSRF
        if (!$this->isCsrfTokenValid('refuser_contrat_' . $id, (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide');
            return $this->redirectToRoute('contrat_voir', ['id' => $id]);
        }

        // Mettre à jour statut
        $contrat->setStatut('REFUSE');
        $this->contratRepo->save($contrat, true);

        $this->addFlash('success', 'Contrat refusé.');

        return $this->redirectToRoute('proprietaire_contrats_liste');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WORKFLOW SIGNATURE
    // ═══════════════════════════════════════════════════════════════════════════

    #[Route('/signer/{id}', name: 'contrat_signer', methods: ['POST'])]
    public function signer(Request $request, int $id, EntityManagerInterface $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $contrat = $this->contratRepo->find($id);

        if (!$contrat || !$this->canAccessContrat($user, $contrat)) {
            throw $this->createAccessDeniedException();
        }

        // Vérifier que le contrat est bien en attente de signature ou en cours de proposition pour le propriétaire
        if (!in_array($contrat->getStatut(), ['EN_ATTENTE_SIGNATURE', 'PROPOSE'])) {
            $this->addFlash('error', 'Ce contrat ne peut pas être signé dans son état actuel.');
            return $this->redirectToRoute('contrat_voir', ['id' => $id]);
        }

        // Vérifier token CSRF
        if (!$this->isCsrfTokenValid('signer_contrat_' . $id, (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide');
            return $this->redirectToRoute('contrat_voir', ['id' => $id]);
        }

        // Vérifier email vérifié pour action sensible (signature)
        if (!$this->securityState->isEmailVerified($user)) {
            $this->addFlash('warning', 'Veuillez vérifier votre email pour signer le contrat.');
            return $this->redirectToRoute('app_verify_email');
        }

        // Enregistrement de la signature selon le rôle du timestamp
        $currentDateString = (new \DateTime())->format('Y-m-d H:i:s');
        $hasSignedNow = false;
        
        $signatureData = $request->request->get('signature_data');

        if ($user === $contrat->getLocataire()) {
            if (!$contrat->isSigneLocataire()) {
                $contrat->setSigneLocataire(true);
                $contrat->setDateSignatureLocataire($currentDateString);
                if ($signatureData) {
                    $contrat->setLocataireSignatureImage((string)$signatureData);
                }
                $hasSignedNow = true;
                $this->addFlash('success', 'Votre signature locataire a été enregistrée avec succès.');
            } else {
                $this->addFlash('info', 'Vous avez déjà signé ce contrat.');
            }
        } elseif ($user === $contrat->getAnnonce()?->getProprietaire()) {
            if (!$contrat->isSigneProprietaire()) {
                // === VALIDATION: Vérifier que la signature est présente ===
                if (empty($signatureData)) {
                    $this->addFlash('error', 'Veuillez dessiner votre signature électronique avant de soumettre.');
                    return $this->redirectToRoute('contrat_voir', ['id' => $id]);
                }

                // === VALIDATION: Vérifier le CIN du propriétaire ===
                $cinFileProp = $request->files->get('cin_file_proprietaire');
                if (!$cinFileProp) {
                    $this->addFlash('error', 'La pièce d\'identité (CIN) est obligatoire pour signer le contrat.');
                    return $this->redirectToRoute('contrat_voir', ['id' => $id]);
                }

                // Gestion de la pièce d'identité du Propriétaire (CIN)
                $uploadDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/public/uploads/cin';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $propFilename = uniqid('cin_prop_') . '.' . $cinFileProp->guessExtension();
                try {
                    $cinFileProp->move($uploadDir, $propFilename);
                    $contrat->setCinImageProprietaire($propFilename);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur lors de l\'enregistrement de votre CIN : ' . $e->getMessage());
                    return $this->redirectToRoute('contrat_voir', ['id' => $id]);
                }
                
                // === CRÉATION des Règles de Pénalités OBLIGATOIRE pour contrat PROPOSÉ ===
                if ($contrat->getStatut() === 'PROPOSE') {
                    $delaiGrace = (int)$request->request->get('penalite_jourRemise', 5);
                    $penaliteFixe = (float)$request->request->get('penalite_montant', 0);
                    $penalitePct = (float)$request->request->get('penalite_pourcentage', 0);
                    $plafond = (float)$request->request->get('penalite_plafond', 10);
                    
                    // Validation des valeurs
                    if ($delaiGrace < 0 || $penaliteFixe < 0 || $penalitePct < 0 || $plafond < 0) {
                        $this->addFlash('error', 'Les valeurs de pénalité ne peuvent pas être négatives.');
                        return $this->redirectToRoute('contrat_voir', ['id' => $id]);
                    }
                    
                    $regle = new ReglePenalite();
                    $regle->setContrat($contrat);
                    $regle->setDelaiGraceJours($delaiGrace);
                    $regle->setPenaliteFixe($penaliteFixe);
                    $regle->setPenalitePourcentage($penalitePct);
                    $regle->setPlafondPourcentage($plafond);
                    
                    $em->persist($regle);
                    
                    // Créer les paiements automatiques APRÈS définition des pénalités
                    $this->contratFinanceService->creerPaiementsAutomatiques($contrat);
                    
                    // Créer caution si montant précisé
                    $cautionMontant = $request->request->get('caution_montant');
                    if ($cautionMontant && (float)$cautionMontant > 0) {
                        $this->contratFinanceService->creerCaution(
                            $contrat,
                            (float) $cautionMontant,
                            'Caution location'
                        );
                    }
                    
                    $em->flush();
                    
                    // Changer le statut: si locataire a déjà signé -> ACTIF, sinon -> EN_ATTENTE_SIGNATURE
                    if ($contrat->isSigneLocataire()) {
                        $contrat->setStatut('ACTIF');
                        $this->addFlash('success', 'Contrat signé et activé ! Les pénalités ont été appliquées.');
                    } else {
                        $contrat->setStatut('EN_ATTENTE_SIGNATURE');
                        $this->addFlash('success', 'Contrat confirmé. En attente de la signature du locataire.');
                    }
                }

                // Enregistrer la signature
                $contrat->setSigneProprietaire(true);
                $contrat->setDateSignatureProprietaire($currentDateString);
                $contrat->setProprietaireSignatureImage((string)$signatureData);
                
                $hasSignedNow = true;
            } else {
                $this->addFlash('info', 'Vous avez déjà signé ce contrat.');
            }
        }

        // Si quelqu'un vient de signer, on vérifie si le contrat devient 100% ACTIF
        if ($hasSignedNow) {
            if ($contrat->isFullySigned()) {
                $contrat->setStatut('ACTIF');
                $this->addFlash('success', 'Le contrat a réuni toutes les signatures et est désormais ACTIF ! Les paiements ont été générés.');
                
                // Génération automatique de la facturation si c'est la première fois qu'il devient actif
                $this->contratFinanceService->creerPaiementsAutomatiques($contrat);
            }
            $this->contratRepo->save($contrat, true);
        }

        return $this->redirectToRoute('contrat_voir', ['id' => $id]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GÉNÉRATION PDF
    // ═══════════════════════════════════════════════════════════════════════════

    #[Route('/pdf/{id}', name: 'contrat_pdf')]
    public function generatePdf(int $id, ParameterBagInterface $params): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();

        $contrat = $this->contratRepo->find($id);

        if (!$contrat || !$user instanceof Utilisateur || !$this->canAccessContrat($user, $contrat)) {
            throw $this->createAccessDeniedException();
        }

        $dashboardData = $this->contratFinanceService->getContratDashboard($id);

        $html = $this->renderView('contrat/pdf.html.twig', [
            'contrat' => $contrat,
            'dashboard' => $dashboardData,
        ]);

        $pdfOptions = new \Dompdf\Options();
        $pdfOptions->set('defaultFont', 'DejaVu Sans');
        $pdfOptions->set('isRemoteEnabled', true);
        $pdfOptions->set('isHtml5ParserEnabled', true);
        $publicDir = (is_string($dir = $params->get('kernel.project_dir')) ? $dir : '') . '/public';
        if (is_dir($publicDir)) {
            $pdfOptions->set('chroot', $publicDir);
        }

        $dompdf = new \Dompdf\Dompdf($pdfOptions);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'Contrat_Sakan_' . $contrat->getId() . '.pdf';

        return new Response(
            $dompdf->output(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MÉTHODES PRIVÉES
    // ═══════════════════════════════════════════════════════════════════════════

    private function canAccessContrat(Utilisateur $user, Contrat $contrat): bool
    {
        return $user === $contrat->getLocataire() 
            || $user === $contrat->getAnnonce()?->getProprietaire()
            || in_array('ROLE_ADMIN', $user->getRoles());
    }


}
