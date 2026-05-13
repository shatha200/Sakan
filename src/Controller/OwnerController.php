<?php

namespace App\Controller;

use App\Entity\Annonce;
use App\Entity\Utilisateur;
use App\Repository\AnnonceRepository;
use App\Repository\PaiementLoyerRepository;
use App\Repository\UtilisateurRepository;
use App\Service\AuthOtpService;
use App\Service\AuthValidationService;
use App\Service\PhoneVerificationService;
use App\Service\DashboardService;
use App\Service\NotificationService;
use App\Service\ProfileImageStorage;
use App\Service\SecurityNotificationService;
use App\Service\AnnoncePhotoStorage;
use App\Service\UserSecurityStateService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[Route('/owner')]
class OwnerController extends AbstractController
{
    private const MAX_PROFILE_IMAGE_BYTES = 5 * 1024 * 1024;
    private const EMAIL_CHANGE_SESSION_KEY = 'profile_email_change_pending';
    private const PHONE_VERIFICATION_SESSION_KEY = 'owner_profile_phone_verification_pending';

    /**
     * Parse une période dans différents formats possibles
     * Formats supportés: 2025-T3 (trimestre), 2025-01-01 (date), 2025-01 (mois), etc.
     */
    private function parsePeriode(?string $periode): ?\DateTimeInterface
    {
        if (!$periode) return null;

        // Format trimestriel: 2025-T3 → 2025-09-01 (début du trimestre)
        if (preg_match('/^(\d{4})-T(\d)$/', $periode, $matches)) {
            $year = $matches[1];
            $trimestre = (int)$matches[2];
            $month = (($trimestre - 1) * 3) + 1;
            return new \DateTime("$year-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . "-01");
        }

        // Format mois: 2025-01 → 2025-01-01
        if (preg_match('/^(\d{4})-(\d{2})$/', $periode, $matches)) {
            return new \DateTime($periode . '-01');
        }

        // Format date standard: 2025-01-05
        try {
            return new \DateTime($periode);
        } catch (\Exception $e) {
            return null;
        }
    }

    #[Route('/dashboard', name: 'owner_dashboard')]
    public function dashboard(UtilisateurRepository $utilisateurRepository, \App\Service\DashboardService $dashboardService, \Doctrine\DBAL\Connection $connection): Response
    {
        $firstName = 'Propriétaire';
        $authenticatedUser = $this->getUser();
        if (!$authenticatedUser instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $proprietaireId = (int) $authenticatedUser->getId();
        $user = $utilisateurRepository->find($proprietaireId);
        if ($user instanceof Utilisateur) {
            [$prenom] = $this->splitFullName((string) $user->getNom());
            if ($prenom !== '') {
                $firstName = $prenom;
            }
        }

        $revenueData = $dashboardService->getRevenueThisMonth($proprietaireId);
        $tendanceData = $dashboardService->getTendance($proprietaireId);
        $alertes = $dashboardService->getAlertesUrgentes($proprietaireId);
        $evolution = $dashboardService->getEvolutionMultiSeries($proprietaireId);

        $sqlBiens = "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN LOWER(statut) IN ('loué', 'loue', 'occupe', 'occupé') THEN 1 ELSE 0 END) AS loues,
                SUM(CASE WHEN LOWER(statut) IN ('disponible', 'libre') THEN 1 ELSE 0 END) AS disponibles,
                SUM(CASE WHEN LOWER(statut) NOT IN ('loué', 'loue', 'occupe', 'occupé', 'disponible', 'libre') THEN 1 ELSE 0 END) AS autres
            FROM annonce
            WHERE CAST(proprietaireId AS UNSIGNED) = :propId
        ";
        $biensStats = $connection->fetchAssociative($sqlBiens, ['propId' => $proprietaireId]);

        $sqlTopBiens = "
            SELECT a.id, a.titre, a.adresse, a.prix, a.statut, a.photo_principale, a.slug,
                   (SELECT u.nom FROM contrat c JOIN utilisateur u ON c.locataireId = u.id WHERE c.annonceId = a.id AND LOWER(c.statut) = 'actif' LIMIT 1) as locataire_nom,
                   (SELECT c.date_fin FROM contrat c WHERE c.annonceId = a.id AND LOWER(c.statut) = 'actif' LIMIT 1) as date_fin_contrat
            FROM annonce a
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            ORDER BY a.id DESC
            LIMIT 3
        ";
        $topBiens = $connection->fetchAllAssociative($sqlTopBiens, ['propId' => $proprietaireId]);

        foreach ($topBiens as &$a) {
            $photoPrincipale = (string) ($a['photo_principale'] ?? '');
            $paths = $photoPrincipale !== '' ? array_filter(array_map('trim', explode(',', $photoPrincipale))) : [];
            $a['cover'] = $paths[0] ?? null;
        }
        unset($a);

        $totalBiens = (int)($biensStats['total'] ?? 0);
        $loues = (int)($biensStats['loues'] ?? 0);
        $tauxOccupation = $totalBiens > 0 ? round(($loues * 100) / $totalBiens) : 0;

        $sqlAvis = "
            SELECT AVG(av.note) as avg_note, COUNT(av.id) as nb_avis
            FROM avis av
            JOIN annonce a ON av.annonce_id = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
        ";
        $avisData = $connection->fetchAssociative($sqlAvis, ['propId' => $proprietaireId]);
        $noteMoyenne = $avisData !== false && $avisData['avg_note'] !== null ? round((float)$avisData['avg_note'], 1) : 0;
        $nbAvis = $avisData !== false ? (int)($avisData['nb_avis'] ?? 0) : 0;

        return $this->render('owner/dashboard.html.twig', [
            'owner_first_name' => $firstName,
            'revenu_encaisse' => $revenueData['encaisse'],
            'revenu_tendance_pct' => $tendanceData['pourcentage'],
            'biens_total' => $totalBiens,
            'biens_loues' => $loues,
            'biens_disponibles' => (int)($biensStats['disponibles'] ?? 0),
            'biens_autres' => (int)($biensStats['autres'] ?? 0),
            'taux_occupation' => $tauxOccupation,
            'note_moyenne' => $noteMoyenne,
            'nb_avis' => $nbAvis,
            'alertes' => $alertes,
            'evolution' => $evolution,
            'top_biens' => $topBiens,
        ]);
    }

    #[Route('/biens', name: 'owner_biens')]
    public function biens(Request $request, Connection $connection): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $q    = trim((string) $request->query->get('q', ''));
        $type = trim((string) $request->query->get('type', ''));
        $sort = (string) $request->query->get('sort', 'recent');

        $allowedTypes = ['Appartement', 'Villa', 'Studio', 'Maison', 'Bureau'];
        $allowedSort  = ['recent', 'prix_asc', 'prix_desc'];
        if (!in_array($type, $allowedTypes, true)) { $type = ''; }
        if (!in_array($sort, $allowedSort, true))  { $sort = 'recent'; }

        $this->refreshStatuts($connection);

        $sql = 'SELECT a.id, a.titre, a.description, a.prix, a.statut, a.date_disponibilite AS dateDisponibilite,
                       a.photo_principale, a.adresse, a.slug, a.type AS bien_type, a.surface AS superficie, a.chambres AS nombreChambres
                FROM annonce a
                WHERE a.proprietaireId = :pid';
        $params = ['pid' => (int) $user->getId()];

        if ($q !== '') {
            $sql .= ' AND (a.titre LIKE :q OR a.description LIKE :q OR a.adresse LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($type !== '') {
            $sql .= ' AND a.type = :type';
            $params['type'] = $type;
        }

        $sql .= match ($sort) {
            'prix_asc'  => ' ORDER BY CAST(a.prix AS DECIMAL(10,2)) ASC',
            'prix_desc' => ' ORDER BY CAST(a.prix AS DECIMAL(10,2)) DESC',
            default     => ' ORDER BY a.id DESC',
        };

        $annonces = [];
        try {
            $annonces = $connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            $annonces = [];
        }

        foreach ($annonces as &$a) {
            $photoPrincipale = (string) ($a['photo_principale'] ?? '');
            $paths = $photoPrincipale !== '' ? array_filter(array_map('trim', explode(',', $photoPrincipale))) : [];
            $a['images'] = $paths;
            $a['cover'] = $paths[0] ?? null;
            $a['statut'] = strtolower((string) ($a['statut'] ?? ''));
            $a['jours_restants'] = null;
            $a['heures_restantes'] = null;
            $a['minutes_restantes'] = null;
            $a['total_seconds'] = null;
            if (in_array($a['statut'], ['bientot', 'occupe']) && !empty($a['dateDisponibilite'])) {
                $dispo = $this->parseDateDispo((string) $a['dateDisponibilite']);
                if ($dispo) {
                    $target = clone $dispo;
                    if ($a['statut'] === 'occupe') {
                        $target->modify('-7 days');
                    }
                    $diffSec = max(0, $target->getTimestamp() - (new \DateTime())->getTimestamp());
                    $a['jours_restants'] = intdiv($diffSec, 86400);
                    $a['heures_restantes'] = intdiv($diffSec % 86400, 3600);
                    $a['minutes_restantes'] = intdiv($diffSec % 3600, 60);
                    $a['total_seconds'] = $diffSec;
                }
            }
        }
        unset($a);

        return $this->render('owner/biens.html.twig', [
            'annonces' => $annonces,
            'filters'  => [
                'q'    => $q,
                'type' => $type,
                'sort' => $sort,
            ],
            'allowed_types' => $allowedTypes,
        ]);
    }

    #[Route('/loyers', name: 'owner_loyers')]
    public function loyers(): Response
    {
        return $this->render('owner/loyers.html.twig');
    }

    // ===== REVENUS =====
    #[Route('/revenus', name: 'owner_revenus')]
    public function revenus(DashboardService $dashboardService): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        
        $proprietaireId = (int) $user->getId();
        
        // Récupération des 7 jeux de données pour le tableau de bord (équivalent JavaFX)
        $revenue = $dashboardService->getRevenueThisMonth($proprietaireId);
        $taux = $dashboardService->getTauxRecouvrement($proprietaireId);
        $alertes = $dashboardService->getAlertesUrgentes($proprietaireId);
        $cautions = $dashboardService->getCautionsARembourser($proprietaireId);
        $tendance = $dashboardService->getTendance($proprietaireId);
        $evolution = $dashboardService->getEvolutionMultiSeries($proprietaireId);
        $contrats = $dashboardService->getContratsDetails($proprietaireId);
        
        return $this->render('owner/revenus/index.html.twig', [
            // KPI 1: Revenus
            'revenuEncaisse' => $revenue['encaisse'],
            'revenuPrevu' => $revenue['prevu'],
            
            // KPI 2: Taux recouvrement
            'tauxRecouvrement' => $taux['taux'],
            'nbPayes' => $taux['payes'],
            'nbRetards' => $taux['retards'],
            'nbTotal' => $taux['total'],
            
            // KPI 3: Alertes
            'nbAlertes' => count($alertes),
            'alertes' => $alertes,
            
            // Cautions
            'cautions' => $cautions,
            
            // KPI 4: Tendance
            'tendancePct' => $tendance['pourcentage'],
            'tendanceActuel' => $tendance['mois_actuel'],
            'tendancePrecedent' => $tendance['mois_precedent'],
            
            // Graphique
            'evolution' => $evolution,
            
            // Contrats
            'contrats' => $contrats,
        ]);
    }

    #[Route('/revenus/paiements-attendus', name: 'owner_revenus_paiements')]
    public function revenusPaiements(PaiementLoyerRepository $paiementRepo): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        
        $proprietaireId = (int) $user->getId();
        $loyers = $paiementRepo->findLoyersARecevoir($proprietaireId);
        
        $total = array_sum(array_map(fn($l) => (float) $l['montant'], $loyers));
        
        return $this->render('owner/revenus/paiements_attendus.html.twig', [
            'loyers' => $loyers,
            'count' => count($loyers),
            'total' => $total,
        ]);
    }

    #[Route('/revenus/retards', name: 'owner_revenus_retards')]
    public function revenusRetards(Request $request, PaiementLoyerRepository $paiementRepo): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        
        $proprietaireId = (int) $user->getId();
        $loyers = $paiementRepo->findLoyersEnRetard($proprietaireId);
        
        // Pagination
        $limit = (int) $request->query->get('limit', 10);
        $page = (int) $request->query->get('page', 1);
        
        // Pagination Pagerfanta
        $adapter = new \Pagerfanta\Adapter\ArrayAdapter($loyers);
        $pagerfanta = new \Pagerfanta\Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($limit);
        $pagerfanta->setCurrentPage($page);
        
        // Total dû (loyer + pénalité) - calculé sur tous les résultats
        $totalDu = array_sum(array_map(
            fn($l) => (float) $l['montant'] + (float) ($l['penalite'] ?? 0),
            $loyers
        ));
        
        return $this->render('owner/revenus/retards.html.twig', [
            'loyers' => $pagerfanta->getCurrentPageResults(),
            'pager' => $pagerfanta,
            'count' => count($loyers),
            'totalDu' => $totalDu,
            'limit' => $limit,
        ]);
    }

    #[Route('/revenus/journal', name: 'owner_revenus_journal')]
    public function revenusJournal(Request $request, PaiementLoyerRepository $paiementRepo): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        
        $proprietaireId = (int) $user->getId();
        $year = $request->query->getInt('year', (int) date('Y'));
        
        // Récupérer tous les loyers pour l'année
        $loyers = $paiementRepo->findLoyersHistorique($proprietaireId, $year);
        
        // Filtres
        $filterBien = $request->query->get('bien', '');
        $filterStatut = $request->query->get('statut', '');
        $filterMois = $request->query->get('mois', '');
        $limit = (int) $request->query->get('limit', 10);
        $page = (int) $request->query->get('page', 1);
        
        // Application des filtres PHP
        $filtered = array_filter($loyers, function($l) use ($filterBien, $filterStatut, $filterMois) {
            if ($filterBien && ($l['nom_bien'] ?? '') !== $filterBien) {
                return false;
            }
            if ($filterStatut && ($l['statut'] ?? '') !== $filterStatut) {
                return false;
            }
            if ($filterMois) {
                $periodeMois = ($this->parsePeriode($l['periode'] ?? null))?->format('F') ?? '';
                if ($periodeMois !== $filterMois) {
                    return false;
                }
            }
            return true;
        });
        $filtered = array_values($filtered);
        
        // Pagination Pagerfanta
        $adapter = new \Pagerfanta\Adapter\ArrayAdapter($filtered);
        $pagerfanta = new \Pagerfanta\Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($limit);
        $pagerfanta->setCurrentPage($page);
        
        // Extraction filtres distincts (pour les selects)
        $biens = array_unique(array_column($loyers, 'nom_bien'));
        $mois = array_unique(array_map(
            fn($l) => ($this->parsePeriode($l['periode'] ?? null))?->format('F') ?? 'N/A',
            $loyers
        ));
        
        // Groupement par bien pour affichage paginé
        $pageResults = $pagerfanta->getCurrentPageResults();
        $grouped = [];
        foreach ($pageResults as $l) {
            $grouped[$l['nom_bien']][] = $l;
        }
        
        return $this->render('owner/revenus/journal.html.twig', [
            'groupedLoyers' => $grouped,
            'loyers' => $pageResults,
            'year' => $year,
            'years' => range((int) date('Y'), (int) date('Y') - 5),
            'biens' => $biens,
            'mois' => $mois,
            'pager' => $pagerfanta,
            'filterBien' => $filterBien,
            'filterStatut' => $filterStatut,
            'filterMois' => $filterMois,
            'limit' => $limit,
            'nbFiltered' => count($filtered)
        ]);
    }

    // ===== CHARGES =====
    // Ces routes sont gérées par ChargeController (/proprietaire/charges/*)
    // L'OwnerController expose des alias /owner/charges/* qui redirigent
    #[Route('/charges', name: 'owner_charges')]
    public function charges(): Response
    {
        return $this->redirectToRoute('charge_frais_gestion');
    }

    #[Route('/charges/gestion', name: 'owner_charges_gestion')]
    public function chargesGestion(): Response
    {
        return $this->redirectToRoute('charge_frais_gestion');
    }

    #[Route('/charges/annuels', name: 'owner_charges_annuels')]
    public function chargesAnnuels(): Response
    {
        return $this->redirectToRoute('charge_frais_annuels');
    }

    // ===== CAUTIONS =====
    // Ces routes sont gérées par CautionController (/proprietaire/cautions/*)
    // L'OwnerController expose des alias /owner/cautions/* qui redirigent
    #[Route('/cautions', name: 'owner_cautions')]
    public function cautions(): Response
    {
        return $this->redirectToRoute('caution_depots_actifs');
    }

    #[Route('/cautions/actifs', name: 'owner_cautions_actifs')]
    public function cautionsActifs(): Response
    {
        return $this->redirectToRoute('caution_depots_actifs');
    }

    #[Route('/cautions/regulariser', name: 'owner_cautions_regulariser')]
    public function cautionsRegulariser(): Response
    {
        return $this->redirectToRoute('caution_a_regulariser');
    }

    #[Route('/cautions/archive', name: 'owner_cautions_archive')]
    public function cautionsArchive(): Response
    {
        return $this->redirectToRoute('caution_archivage');
    }

    // ===== PÉNALITÉS =====
    #[Route('/penalites', name: 'owner_penalites')]
    public function penalites(Request $request, PaiementLoyerRepository $paiementRepo): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        
        $proprietaireId = (int) $user->getId();
        
        // Fetch KPIs
        $totalRecouvre = $paiementRepo->getTotalPenalitesRecouvrees($proprietaireId);
        $totalMois = $paiementRepo->getTotalPenalitesMois($proprietaireId);
        $nbRetards = $paiementRepo->getNombreRetardsMois($proprietaireId);
        $pireBien = $paiementRepo->getTopRetardataire($proprietaireId);
        
        // Fetch Charts data
        $evolution = $paiementRepo->getEvolutionEncaissements($proprietaireId);
        $repartition = $paiementRepo->getRepartitionParBien($proprietaireId);
        
        // Journal / Table paginée
        $search = $request->query->get('search');
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 5;
        
        $historique = $paiementRepo->getHistoriqueEncaissements($proprietaireId, (string)$search, $page, $limit);
        $totalHistorique = $paiementRepo->countHistoriqueEncaissements($proprietaireId, (string)$search);
        
        $pages = ceil($totalHistorique / $limit);

        return $this->render('owner/penalites.html.twig', [
            'totalRecouvre' => $totalRecouvre,
            'totalMois' => $totalMois,
            'nbRetards' => $nbRetards,
            'pireBien' => $pireBien,
            'historique' => $historique,
            'evolution' => $evolution,
            'repartition' => $repartition,
            'search' => $search,
            'page' => $page,
            'pages' => $pages
        ]);
    }

    // NOTE: Les méthodes visites() et reservations() sont maintenant dans
    // VisiteController et ReservationController dédiés.
    // Routes: /proprietaire/visites et /proprietaire/reservations

    #[Route('/contrats', name: 'owner_contrats')]
    public function contrats(): Response
    {
        return $this->render('owner/contrats.html.twig');
    }

    #[Route('/reclamations', name: 'owner_reclamations')]
    public function reclamations(): Response
    {
        return $this->render('owner/reclamations.html.twig');
    }

    #[Route('/profil', name: 'owner_profil', methods: ['GET', 'POST'])]
    public function profil(
        Request $request,
        EntityManagerInterface $entityManager,
        UtilisateurRepository $utilisateurRepository,
        AuthValidationService $validationService,
        UserPasswordHasherInterface $passwordHasher,
        ProfileImageStorage $profileImageStorage,
        TokenStorageInterface $tokenStorage,
        SecurityNotificationService $securityNotificationService,
        UserSecurityStateService $userSecurityStateService,
        AuthOtpService $authOtpService,
    ): Response {
        $authenticatedUser = $this->getUser();
        if (!$authenticatedUser instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $user = $authenticatedUser;
        if ((int) $user->getId() <= 0) {
            $tokenStorage->setToken(null);
            $request->getSession()->invalidate();

            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');

            if ($action === 'upload_photo') {
                $csrfToken = (string) $request->request->get('_csrf_token', '');
                if (!$this->isCsrfTokenValid('upload_profile_photo_'.$user->getId(), $csrfToken)) {
                    $this->addFlash('auth_error', 'Session invalide. Veuillez réessayer.');
                    return $this->redirectToRoute('owner_profil');
                }

                try {
                    $uploadedFile = $request->files->get('profile_photo');
                    if (!$uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        throw new \RuntimeException('Veuillez choisir une image valide.');
                    }

                    $profileImageStorage->storeUploadedProfileImage($user, $uploadedFile, self::MAX_PROFILE_IMAGE_BYTES);
                    $user->setPhotoProfil(null);
                    $entityManager->flush();
                    $this->addFlash('auth_success', 'Image de profil mise à jour avec succès.');
                } catch (\RuntimeException $exception) {
                    $this->addFlash('auth_error', $exception->getMessage());
                }

                return $this->redirectToRoute('owner_profil');
            }

            if ($action === 'toggle_2fa') {
                $csrfToken = (string) $request->request->get('_csrf_token', '');
                if (!$this->isCsrfTokenValid('toggle_2fa_'.$user->getId(), $csrfToken)) {
                    $this->addFlash('auth_error', 'Session invalide. Veuillez réessayer.');
                    return $this->redirectToRoute('owner_profil');
                }

                $enabled = !$user->isTwoFactorEnabled();
                $user->setTwoFactorEnabled($enabled);
                $entityManager->flush();

                $this->addFlash('auth_success', $enabled ? 'Vérification 2FA activée.' : 'Vérification 2FA désactivée.');
                return $this->redirectToRoute('owner_profil');
            }

            if ($action === 'update_profile') {
                $firstName = trim((string) $request->request->get('prenom', ''));
                $lastName = trim((string) $request->request->get('nom', ''));
                $email = strtolower(trim((string) $request->request->get('email', '')));
                $telephone = trim((string) $request->request->get('telephone', ''));

                if ($firstName === '' || $lastName === '' || $email === '' || $telephone === '') {
                    $this->addFlash('auth_error', 'Veuillez remplir tous les champs du profil.');
                    return $this->redirectToRoute('owner_profil');
                }

                if (!$validationService->isValidEmail($email)) {
                    $this->addFlash('auth_error', 'Veuillez saisir une adresse email valide.');
                    return $this->redirectToRoute('owner_profil');
                }

                if (!$validationService->isValidTunisiaPhone($telephone)) {
                    $this->addFlash('auth_error', 'Veuillez saisir un numéro de téléphone tunisien valide (8 chiffres).');
                    return $this->redirectToRoute('owner_profil');
                }

                $telephoneE164 = $validationService->toTunisiaE164($telephone);
                $userId = (int) $user->getId();
                $currentEmail = strtolower(trim((string) $user->getEmail()));

                if ($utilisateurRepository->emailExistsForAnotherUser($email, $userId)) {
                    $this->addFlash('auth_error', 'Cette adresse email est déjà utilisée.');
                    return $this->redirectToRoute('owner_profil');
                }

                if ($utilisateurRepository->phoneExistsForAnotherUser($telephone, $telephoneE164, $userId)) {
                    $this->addFlash('auth_error', 'Ce numéro de téléphone est déjà utilisé.');
                    return $this->redirectToRoute('owner_profil');
                }

                if ($email !== $currentEmail) {
                    if (!$authOtpService->createEmailChangeOtp($user, $email)) {
                        $this->addFlash('auth_error', 'Impossible d\'envoyer le code OTP sur votre ancien email. Veuillez réessayer.');
                        return $this->redirectToRoute('owner_profil');
                    }

                    $otpStatus = $authOtpService->getEmailChangeOtpStatus($user, $email);
                    $request->getSession()->set(self::EMAIL_CHANGE_SESSION_KEY, [
                        'user_id' => $userId,
                        'prenom' => $firstName,
                        'nom' => $lastName,
                        'email' => $email,
                        'telephone' => $telephone,
                        'telephone_e164' => $telephoneE164,
                        'old_email' => $currentEmail,
                        'expires_at' => $otpStatus['expires_at'],
                        'seconds_remaining' => $otpStatus['seconds_remaining'],
                        'resend_count' => $otpStatus['resend_count'],
                        'max_resends' => $otpStatus['max_resends'],
                    ]);

                    $this->addFlash('auth_success', 'Un code OTP a été envoyé à votre ancien email pour confirmer le changement d\'adresse.');
                    return $this->redirectToRoute('owner_profil');
                }

                $user->setNom(trim($firstName . ' ' . $lastName));
                $user->setEmail($email);
                $user->setTelephone($telephone);
                $user->setTelephoneE164($telephoneE164);

                try {
                    $entityManager->flush();
                    $this->clearPendingEmailChange($request);
                    $this->addFlash('auth_success', 'Profil mis à jour avec succès.');
                } catch (UniqueConstraintViolationException) {
                    $this->addFlash('auth_error', 'Impossible de mettre à jour : email ou téléphone déjà utilisé.');
                }

                return $this->redirectToRoute('owner_profil');
            }

            if ($action === 'change_password') {
                $currentPassword = (string) $request->request->get('current_password', '');
                $newPassword = (string) $request->request->get('new_password', '');
                $confirmPassword = (string) $request->request->get('confirm_password', '');

                if (trim($currentPassword) === '' || trim($newPassword) === '' || trim($confirmPassword) === '') {
                    $this->addFlash('auth_error', 'Veuillez remplir tous les champs du mot de passe.');
                    return $this->redirectToRoute('owner_profil');
                }

                if (!$this->isCurrentPasswordValid($user, $currentPassword)) {
                    $this->addFlash('auth_error', 'Mot de passe actuel incorrect.');
                    return $this->redirectToRoute('owner_profil');
                }

                if (!$validationService->isValidPassword($newPassword)) {
                    $this->addFlash('auth_error', 'Le nouveau mot de passe doit contenir au moins 6 caracteres, une minuscule, une majuscule et un chiffre.');
                    return $this->redirectToRoute('owner_profil');
                }

                if ($newPassword !== $confirmPassword) {
                    $this->addFlash('auth_error', 'La confirmation du nouveau mot de passe est incorrecte.');
                    return $this->redirectToRoute('owner_profil');
                }

                if (hash_equals($currentPassword, $newPassword)) {
                    $this->addFlash('auth_error', 'Le nouveau mot de passe doit etre different de l actuel.');
                    return $this->redirectToRoute('owner_profil');
                }

                $user->setMotDePasse($passwordHasher->hashPassword($user, $newPassword));
                $entityManager->flush();
                $userSecurityStateService->notePasswordChanged($user);
                $securityNotificationService->sendPasswordChanged($user);

                $this->addFlash('auth_success', 'Mot de passe mis à jour avec succès.');
                return $this->redirectToRoute('owner_profil');
            }

            if ($action === 'delete_profile') {
                $csrfToken = (string) $request->request->get('_csrf_token', '');
                if (!$this->isCsrfTokenValid('delete_profile_'.$user->getId(), $csrfToken)) {
                    $this->addFlash('auth_error', 'Session invalide. Veuillez réessayer.');
                    return $this->redirectToRoute('owner_profil');
                }

                try {
                    $profileImageStorage->deleteProfileImage($user);
                    $entityManager->remove($user);
                    $entityManager->flush();
                } catch (ForeignKeyConstraintViolationException) {
                    $this->addFlash('auth_error', 'Suppression impossible : ce compte est lié à des données existantes.');
                    return $this->redirectToRoute('owner_profil');
                }

                $tokenStorage->setToken(null);
                $request->getSession()->invalidate();

                $response = $this->redirectToRoute('app_login');
                $response->headers->clearCookie('REMEMBERME');
                $response->headers->clearCookie('remember_me');

                return $response;
            }

            if ($action === 'deactivate_account') {
                $csrfToken = (string) $request->request->get('_csrf_token', '');
                if (!$this->isCsrfTokenValid('deactivate_account_'.$user->getId(), $csrfToken)) {
                    $this->addFlash('auth_error', 'Session invalide. Veuillez réessayer.');
                    return $this->redirectToRoute('owner_profil');
                }

                $user->setStatut('SUSPENDU');
                $entityManager->flush();
                $securityNotificationService->sendAccountSuspended($user);

                $tokenStorage->setToken(null);
                $request->getSession()->invalidate();

                $response = $this->redirectToRoute('app_login');
                $response->headers->clearCookie('REMEMBERME');
                $response->headers->clearCookie('remember_me');

                return $response;
            }
        }

        $pendingEmailChange = $this->getPendingEmailChange($request, $user);
        if ($pendingEmailChange !== null) {
            $otpStatus = $authOtpService->getEmailChangeOtpStatus($user, (string) ($pendingEmailChange['email'] ?? ''));
            $pendingEmailChange['expires_at'] = $otpStatus['expires_at'];
            $pendingEmailChange['seconds_remaining'] = $otpStatus['seconds_remaining'];
            $pendingEmailChange['resend_count'] = $otpStatus['resend_count'];
            $pendingEmailChange['max_resends'] = $otpStatus['max_resends'];
            $request->getSession()->set(self::EMAIL_CHANGE_SESSION_KEY, $pendingEmailChange);
        }

        $pendingPhoneVerification = $this->getPendingPhoneVerification($request, $user);

        [$prenom, $nom] = $this->splitFullName((string) $user->getNom());
        $lastLogin = $userSecurityStateService->getLastLoginDataFromSession($user);

        return $this->render('owner/profil.html.twig', [
            'profil' => [
                'prenom' => $prenom,
                'nom' => $nom,
                'full_name' => trim($prenom . ' ' . $nom),
                'email' => (string) $user->getEmail(),
                'telephone' => (string) $user->getTelephone(),
                'telephone_e164' => (string) ($user->getTelephoneE164() ?? ''),
                'role' => $user->getRoleName(),
                'member_since' => $user->getDateInscription(),
                'two_factor_enabled' => $user->isTwoFactorEnabled(),
                'phone_verified' => $user->isTelephoneVerified(),
                'last_login_at' => $lastLogin['last_login_at'],
                'last_login_ip' => $lastLogin['last_login_ip'],
            ],
            'email_change_pending' => $pendingEmailChange,
            'phone_verification_pending' => $pendingPhoneVerification,
            'profile_image_directory' => $profileImageStorage->getConfiguredDirectory(),
        ]);
    }

    #[Route('/profil/email', name: 'owner_profil_email_change', methods: ['POST'])]
    public function handleEmailChangeOtp(
        Request $request,
        EntityManagerInterface $entityManager,
        UtilisateurRepository $utilisateurRepository,
        AuthOtpService $authOtpService,
    ): Response {
        $authenticatedUser = $this->getUser();
        if (!$authenticatedUser instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $user = $utilisateurRepository->find((int) $authenticatedUser->getId());
        if (!$user instanceof Utilisateur) {
            $request->getSession()->invalidate();
            return $this->redirectToRoute('app_login');
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('email_change_otp_'.$user->getId(), $csrfToken)) {
            $this->addFlash('auth_error', 'Session invalide. Veuillez réessayer.');
            return $this->redirectToRoute('owner_profil');
        }

        $pending = $this->getPendingEmailChange($request, $user);
        if ($pending === null) {
            $this->addFlash('auth_error', 'Aucune demande de changement d\'email en attente.');
            return $this->redirectToRoute('owner_profil');
        }

        $action = (string) $request->request->get('action', 'verify_email_change_otp');

        if ($action === 'cancel_email_change_otp') {
            $this->clearPendingEmailChange($request);
            $this->addFlash('auth_success', 'La demande de changement d\'email a été annulée.');
            return $this->redirectToRoute('owner_profil');
        }

        if ($action === 'resend_email_change_otp') {
            $status = $authOtpService->getEmailChangeOtpStatus($user, (string) $pending['email']);
            if (!$status['can_resend'] || !$authOtpService->resendEmailChangeOtp($user, (string) $pending['email'])) {
                $message = ((int) ($status['seconds_remaining'] ?? 0) > 0 && (int) ($status['resend_count'] ?? 0) >= (int) ($status['max_resends'] ?? AuthOtpService::OTP_MAX_RESENDS))
                    ? 'Vous avez atteint la limite de 5 renvois pour ce code OTP.'
                    : 'Impossible de renvoyer le code pour le moment.';
                $this->addFlash('auth_error', $message);
                return $this->redirectToRoute('owner_profil');
            }

            $updatedStatus = $authOtpService->getEmailChangeOtpStatus($user, (string) $pending['email']);
            $pending['expires_at'] = $updatedStatus['expires_at'];
            $pending['seconds_remaining'] = $updatedStatus['seconds_remaining'];
            $pending['resend_count'] = $updatedStatus['resend_count'];
            $pending['max_resends'] = $updatedStatus['max_resends'];
            $request->getSession()->set(self::EMAIL_CHANGE_SESSION_KEY, $pending);

            $this->addFlash('auth_success', 'Un nouveau code OTP a été envoyé à votre ancien email.');
            return $this->redirectToRoute('owner_profil');
        }

        $otp = (string) $request->request->get('otp_code', '');
        if (!$authOtpService->verifyEmailChangeOtp($user, (string) $pending['email'], $otp)) {
            $this->addFlash('auth_error', 'Code OTP invalide ou expiré.');
            return $this->redirectToRoute('owner_profil');
        }

        $pendingEmail = strtolower(trim((string) $pending['email']));
        $pendingPhone = trim((string) $pending['telephone']);
        $pendingPhoneE164 = trim((string) $pending['telephone_e164']);

        if ($utilisateurRepository->emailExistsForAnotherUser($pendingEmail, (int) $user->getId())) {
            $this->addFlash('auth_error', 'Cette adresse email est déjà utilisée. Veuillez recommencer.');
            return $this->redirectToRoute('owner_profil');
        }

        if ($utilisateurRepository->phoneExistsForAnotherUser($pendingPhone, $pendingPhoneE164, (int) $user->getId())) {
            $this->addFlash('auth_error', 'Ce numéro de téléphone est déjà utilisé. Veuillez recommencer.');
            return $this->redirectToRoute('owner_profil');
        }

        $user->setNom(trim((string) $pending['prenom'] . ' ' . (string) $pending['nom']));
        $user->setEmail($pendingEmail);
        $user->setTelephone($pendingPhone);
        $user->setTelephoneE164($pendingPhoneE164);

        try {
            $entityManager->flush();
            $this->clearPendingEmailChange($request);
            $this->addFlash('auth_success', 'Adresse email mise à jour avec succès.');
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('auth_error', 'Impossible de mettre à jour l\'adresse email pour le moment.');
        }

        return $this->redirectToRoute('owner_profil');
    }

    #[Route('/profil/telephone', name: 'owner_profil_phone_verification', methods: ['POST'])]
    public function handlePhoneVerification(
        Request $request,
        EntityManagerInterface $entityManager,
        UtilisateurRepository $utilisateurRepository,
        PhoneVerificationService $phoneVerificationService,
    ): Response {
        $authenticatedUser = $this->getUser();
        if (!$authenticatedUser instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $user = $utilisateurRepository->find((int) $authenticatedUser->getId());
        if (!$user instanceof Utilisateur) {
            $request->getSession()->invalidate();
            return $this->redirectToRoute('app_login');
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('phone_verification_'.$user->getId(), $csrfToken)) {
            $this->addFlash('auth_error', 'Session invalide. Veuillez réessayer.');
            return $this->redirectToRoute('owner_profil');
        }

        $action = (string) $request->request->get('action', 'start_phone_verification');

        if ($action === 'cancel_phone_verification') {
            $this->clearPendingPhoneVerification($request);
            $this->addFlash('auth_success', 'La vérification du numéro a été annulée.');
            return $this->redirectToRoute('owner_profil');
        }

        $phoneE164 = trim((string) ($user->getTelephoneE164() ?? ''));
        $phoneDisplay = trim((string) ($user->getTelephone() ?? ''));
        if ($phoneE164 === '' || $phoneDisplay === '') {
            $this->addFlash('auth_error', 'Veuillez enregistrer un numéro de téléphone valide avant de le vérifier.');
            return $this->redirectToRoute('owner_profil');
        }

        if ($action === 'verify_phone_code') {
            $code = (string) $request->request->get('phone_verification_code', '');
            try {
                $approved = $phoneVerificationService->checkVerification($phoneE164, $code);
            } catch (\RuntimeException $exception) {
                $this->addFlash('auth_error', $exception->getMessage());
                return $this->redirectToRoute('owner_profil');
            }

            if (!$approved) {
                $this->addFlash('auth_error', 'Code SMS invalide ou expiré.');
                return $this->redirectToRoute('owner_profil');
            }

            $user->setTelephoneVerified(true);
            $entityManager->flush();
            $this->clearPendingPhoneVerification($request);
            $this->addFlash('auth_success', 'Numéro de téléphone vérifié avec succès.');
            return $this->redirectToRoute('owner_profil');
        }

        if ($action === 'resend_phone_verification') {
            if ($user->isTelephoneVerified()) {
                $this->addFlash('auth_success', 'Votre numéro de téléphone est déjà vérifié.');
                return $this->redirectToRoute('owner_profil');
            }
            try {
                $phoneVerificationService->startVerification($phoneE164);
                $this->addFlash('auth_success', 'Un nouveau code de vérification a été envoyé par SMS.');
            } catch (\RuntimeException $exception) {
                $this->addFlash('auth_error', $exception->getMessage());
            }
            return $this->redirectToRoute('owner_profil');
        }

        if ($user->isTelephoneVerified()) {
            $this->addFlash('auth_success', 'Votre numéro de téléphone est déjà vérifié.');
            return $this->redirectToRoute('owner_profil');
        }

        try {
            $phoneVerificationService->startVerification($phoneE164);
        } catch (\RuntimeException $exception) {
            $this->addFlash('auth_error', $exception->getMessage());
            return $this->redirectToRoute('owner_profil');
        }

        $request->getSession()->set(self::PHONE_VERIFICATION_SESSION_KEY, [
            'user_id' => (int) $user->getId(),
            'telephone' => $phoneDisplay,
            'telephone_e164' => $phoneE164,
        ]);

        $this->addFlash('auth_success', 'Un code de vérification a été envoyé par SMS.');
        return $this->redirectToRoute('owner_profil');
    }

    /** @return array<string, mixed>|null */
    private function getPendingEmailChange(Request $request, Utilisateur $user): ?array
    {
        $pending = $request->getSession()->get(self::EMAIL_CHANGE_SESSION_KEY);
        if (!is_array($pending)) {
            return null;
        }
        if ((int) ($pending['user_id'] ?? 0) !== (int) $user->getId()) {
            return null;
        }
        return $pending;
    }

    private function clearPendingEmailChange(Request $request): void
    {
        $request->getSession()->remove(self::EMAIL_CHANGE_SESSION_KEY);
    }

    /** @return array<string, mixed>|null */
    private function getPendingPhoneVerification(Request $request, Utilisateur $user): ?array
    {
        $pending = $request->getSession()->get(self::PHONE_VERIFICATION_SESSION_KEY);
        if (!is_array($pending)) {
            return null;
        }
        if ((int) ($pending['user_id'] ?? 0) !== (int) $user->getId()) {
            $this->clearPendingPhoneVerification($request);
            return null;
        }
        if ($user->isTelephoneVerified()) {
            $this->clearPendingPhoneVerification($request);
            return null;
        }
        $currentPhone = trim((string) ($user->getTelephoneE164() ?? ''));
        if ($currentPhone === '' || $currentPhone !== trim((string) ($pending['telephone_e164'] ?? ''))) {
            $this->clearPendingPhoneVerification($request);
            return null;
        }
        return $pending;
    }

    private function clearPendingPhoneVerification(Request $request): void
    {
        $request->getSession()->remove(self::PHONE_VERIFICATION_SESSION_KEY);
    }

    /** @return array{string, string} */
    private function splitFullName(string $fullName): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $fullName) ?? '');
        if ($normalized === '') {
            return ['', ''];
        }

        $parts = explode(' ', $normalized, 2);
        $firstName = $parts[0] ?? '';
        $lastName = $parts[1] ?? '';

        return [$firstName, $lastName];
    }

    private function isCurrentPasswordValid(Utilisateur $user, string $plainPassword): bool
    {
        $stored = (string) $user->getPassword();
        if ($stored === '') {
            return false;
        }

        if (preg_match('/^\$2[aby]\$/', $stored)) {
            return password_verify($plainPassword, $stored);
        }

        return hash_equals($stored, $plainPassword);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // CRUD ANNONCES - MODULE ANNONCE (DBAL, porté depuis la référence)
    // ═══════════════════════════════════════════════════════════════════════════

    #[Route('/bien/nouveau', name: 'owner_ajouter_bien', methods: ['GET', 'POST'])]
    public function ajouterBien(Request $request, Connection $connection): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $this->ensureAnnonceCautionColumn($connection);

        $old = [];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('owner_ajouter_bien', $token)) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->render('owner/ajouter_bien.html.twig', ['old' => [], 'gemini_api_key' => $_ENV['GEMINI_API_KEY'] ?? '']);
            }

            $dateImprevu = $request->request->get('dateImprevu') === '1';
            $dateVal = trim((string) $request->request->get('dateDisponibilite', ''));
            $timeVal = trim((string) $request->request->get('heureDisponibilite', ''));

            if ($dateImprevu) {
                $dateDispoFull = '';
            } else {
                $dateDispoFull = $dateVal;
                if ($dateDispoFull !== '' && $timeVal !== '') {
                    $dateDispoFull .= ' ' . $timeVal;
                }
            }

            $old = [
                'titre'             => trim((string) $request->request->get('titre', '')),
                'description'       => trim((string) $request->request->get('description', '')),
                'prix'              => trim((string) $request->request->get('prix', '')),
                'type'              => trim((string) $request->request->get('type', '')),
                'meuble'            => trim((string) $request->request->get('meuble', '')),
                'superficie'        => trim((string) $request->request->get('superficie', '')),
                'nombreChambres'    => trim((string) $request->request->get('nombreChambres', '')),
                'sallesBain'        => trim((string) $request->request->get('sallesBain', '')),
                'adresse_text'      => trim((string) $request->request->get('adresse_text', '')),
                'caution_mois'      => trim((string) $request->request->get('caution_mois', '')),
                'dateDisponibilite' => $dateDispoFull,
                'dateImprevu'       => $dateImprevu,
                'dateVal'           => $dateVal,
                'heureVal'          => $timeVal,
            ];
            $old['statut'] = $this->computeStatut($old['dateDisponibilite']);

            $errors = $this->validateBienInput($old);

            $imagePaths = [];
            $uploaded = $request->files->get('photos', []);
            if (!is_array($uploaded)) { $uploaded = [$uploaded]; }
            $uploaded = array_filter($uploaded, fn($f) => $f instanceof UploadedFile);

            if (empty($uploaded)) {
                $errors['photos'] = "Au moins une photo est requise.";
            } else {
                $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $uploadDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/public/images/upload';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

                foreach ($uploaded as $file) {
                    if (!$file->isValid()) { $errors['photos'] = "Échec de l'envoi d'une image."; break; }
                    if ($file->getSize() > self::MAX_PROFILE_IMAGE_BYTES) { $errors['photos'] = "Image trop lourde (5 Mo max)."; break; }
                    $mime = (string) $file->getMimeType();
                    if (!in_array($mime, $allowedMime, true)) { $errors['photos'] = "Format d'image non supporté (jpg, png, webp, gif)."; break; }
                    $ext = $file->guessExtension() ?: 'jpg';
                    $name = bin2hex(random_bytes(16)) . '.' . $ext;
                    try {
                        $file->move($uploadDir, $name);
                    } catch (\Throwable) {
                        $errors['photos'] = "Impossible d'enregistrer une image.";
                        break;
                    }
                    $imagePaths[] = 'images/upload/' . $name;
                }
            }

            if (!empty($errors)) {
                return $this->render('owner/ajouter_bien.html.twig', ['old' => $old, 'errors' => $errors, 'gemini_api_key' => $_ENV['GEMINI_API_KEY'] ?? '']);
            }

            try {
                $connection->beginTransaction();
                $slugValue = $this->generateUniqueSlug($connection, $old['titre']);
                $connection->executeStatement(
                    'INSERT INTO annonce (titre, description, prix, statut, date_disponibilite, proprietaireId, photo_principale, type, surface, chambres, salles_de_bain, adresse, ville, caution_mois, slug)
                     VALUES (:titre, :description, :prix, :statut, :dateDispo, :pid, :imgs, :type, :surface, :chambres, :sdb, :adresse, :ville, :caution, :slug)',
                    [
                        'titre'       => $old['titre'],
                        'description' => $old['description'],
                        'prix'        => (float) $old['prix'],
                        'statut'      => $old['statut'],
                        'dateDispo'   => $old['dateDisponibilite'] !== '' ? $old['dateDisponibilite'] : null,
                        'pid'         => (int) $user->getId(),
                        'imgs'        => implode(',', $imagePaths),
                        'type'        => $old['type'],
                        'surface'     => (int) $old['superficie'],
                        'chambres'    => $old['nombreChambres'] !== '' ? (int) $old['nombreChambres'] : 0,
                        'sdb'         => $old['sallesBain'] !== '' ? (int) $old['sallesBain'] : 0,
                        'adresse'     => $old['adresse_text'] ? mb_substr($old['adresse_text'], 0, 255) : null,
                        'ville'       => $old['adresse_text'] ? mb_substr((explode(',', $old['adresse_text'])[count(explode(',', $old['adresse_text'])) - 1] ?? $old['adresse_text']), 0, 100) : null,
                        'caution'     => in_array($old['caution_mois'], ['1', '2', '3'], true) ? (int) $old['caution_mois'] : null,
                        'slug'        => $slugValue,
                    ]
                );
                $connection->commit();
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) { $connection->rollBack(); }
                $this->addFlash('error', "Erreur lors de l'enregistrement : " . $e->getMessage());
                return $this->render('owner/ajouter_bien.html.twig', ['old' => $old, 'errors' => [], 'gemini_api_key' => $_ENV['GEMINI_API_KEY'] ?? '']);
            }

            $this->addFlash('success', 'Annonce publiée avec succès.');
            return $this->redirectToRoute('owner_biens');
        }

        return $this->render('owner/ajouter_bien.html.twig', ['old' => $old, 'errors' => [], 'gemini_api_key' => $_ENV['GEMINI_API_KEY'] ?? '']);
    }

    #[Route('/bien/slug/{slug}', name: 'owner_bien_show_slug', requirements: ['slug' => '[a-z0-9][a-z0-9\-]*'], methods: ['GET'])]
    public function showBienBySlug(string $slug, Connection $connection): Response
    {
        try {
            $id = $connection->fetchOne('SELECT id FROM annonce WHERE slug = :s LIMIT 1', ['s' => $slug]);
        } catch (\Throwable) {
            $id = false;
        }
        if (!$id) {
            throw $this->createNotFoundException('Bien introuvable pour ce slug.');
        }
        return $this->showBien((int) $id, $connection);
    }

    #[Route('/bien/{id}', name: 'owner_bien_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showBien(int $id, Connection $connection): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $this->ensureAnnonceCautionColumn($connection);
        $this->refreshStatuts($connection);

        $annonce = $connection->fetchAssociative(
            'SELECT a.*, a.date_disponibilite AS dateDisponibilite,
                    a.type AS bien_type, a.surface AS superficie, a.chambres AS nombreChambres, a.adresse AS bien_adresse
             FROM annonce a
             WHERE a.id = :id AND a.proprietaireId = :pid',
            ['id' => $id, 'pid' => (int) $user->getId()]
        );

        if (!$annonce) {
            $this->addFlash('error', "Bien introuvable.");
            return $this->redirectToRoute('owner_biens');
        }

        $photoPrincipale = (string) ($annonce['photo_principale'] ?? '');
        $annonce['images'] = $photoPrincipale !== '' ? array_filter(array_map('trim', explode(',', $photoPrincipale))) : [];
        $annonce['statut'] = strtolower((string) ($annonce['statut'] ?? ''));

        $annonce['jours_restants'] = null;
        $annonce['heures_restantes'] = null;
        $annonce['minutes_restantes'] = null;
        $annonce['secondes_restantes'] = null;
        $annonce['total_seconds'] = null;
        if (in_array($annonce['statut'], ['bientot', 'occupe']) && !empty($annonce['dateDisponibilite'])) {
            $dispo = $this->parseDateDispo((string) $annonce['dateDisponibilite']);
            if ($dispo) {
                $target = clone $dispo;
                if ($annonce['statut'] === 'occupe') {
                    $target->modify('-7 days');
                }
                $diffSec = max(0, $target->getTimestamp() - (new \DateTime())->getTimestamp());
                $annonce['jours_restants'] = intdiv($diffSec, 86400);
                $annonce['heures_restantes'] = intdiv($diffSec % 86400, 3600);
                $annonce['minutes_restantes'] = intdiv($diffSec % 3600, 60);
                $annonce['secondes_restantes'] = $diffSec % 60;
                $annonce['total_seconds'] = $diffSec;
            }
        }

        $this->ensureAvisTableExists($connection);
        $avisList = $connection->fetchAllAssociative(
            'SELECT av.*, u.nom AS auteur_nom
             FROM avis av
             LEFT JOIN utilisateur u ON u.id = av.user_id
             WHERE av.annonce_id = :aid
             ORDER BY av.date_creation DESC',
            ['aid' => $id]
        );

        $avgNote = 0;
        if (count($avisList) > 0) {
            $avgNote = round(array_sum(array_column($avisList, 'note')) / count($avisList), 1);
        }

        return $this->render('owner/bien_show.html.twig', [
            'a' => $annonce,
            'avis_list' => $avisList,
            'avis_avg' => $avgNote,
            'avis_count' => count($avisList),
        ]);
    }

    #[Route('/bien/{id}/edit', name: 'owner_bien_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editBien(int $id, Request $request, Connection $connection): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $this->ensureAnnonceCautionColumn($connection);

        $row = $connection->fetchAssociative(
            'SELECT a.*, a.date_disponibilite AS dateDisponibilite,
                    a.type AS bien_type, a.surface AS superficie, a.chambres AS nombreChambres
             FROM annonce a
             WHERE a.id = :id AND a.proprietaireId = :pid',
            ['id' => $id, 'pid' => (int) $user->getId()]
        );
        if (!$row) {
            $this->addFlash('error', "Bien introuvable.");
            return $this->redirectToRoute('owner_biens');
        }

        $rawDate = $row['dateDisponibilite'] ? (string) $row['dateDisponibilite'] : '';
        $isImprevu = ($rawDate === '');
        $dateOnly = '';
        $timeOnly = '';
        if ($rawDate !== '') {
            $parts = explode(' ', $rawDate, 2);
            $dateOnly = $parts[0];
            $timeOnly = $parts[1] ?? '';
        }

        $old = [
            'titre'             => (string) ($row['titre'] ?? ''),
            'description'       => (string) ($row['description'] ?? ''),
            'prix'              => (string) ($row['prix'] ?? ''),
            'type'              => (string) ($row['bien_type'] ?? ''),
            'meuble'            => '',
            'superficie'        => (string) ($row['superficie'] ?? ''),
            'nombreChambres'    => (string) ($row['nombreChambres'] ?? ''),
            'sallesBain'        => (string) ($row['salles_de_bain'] ?? ''),
            'adresse_text'      => (string) ($row['adresse'] ?? ''),
            'caution_mois'      => (string) ($row['caution_mois'] ?? ''),
            'slug'              => (string) ($row['slug'] ?? ''),
            'dateDisponibilite' => $rawDate,
            'dateImprevu'       => $isImprevu,
            'dateVal'           => $dateOnly,
            'heureVal'          => $timeOnly,
            'statut'            => (string) ($row['statut'] ?? 'disponible'),
        ];
        $photoPrincipale = (string) ($row['photo_principale'] ?? '');
        $existingImages = $photoPrincipale !== '' ? array_filter(array_map('trim', explode(',', $photoPrincipale))) : [];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('owner_bien_edit_' . $id, $token)) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('owner_bien_edit', ['id' => $id]);
            }

            $dateImprevu = $request->request->get('dateImprevu') === '1';
            $dateVal = trim((string) $request->request->get('dateDisponibilite', ''));
            $timeVal = trim((string) $request->request->get('heureDisponibilite', ''));

            if ($dateImprevu) {
                $dateDispoFull = '';
            } else {
                $dateDispoFull = $dateVal;
                if ($dateDispoFull !== '' && $timeVal !== '') {
                    $dateDispoFull .= ' ' . $timeVal;
                }
            }

            $old = [
                'titre'             => trim((string) $request->request->get('titre', '')),
                'description'       => trim((string) $request->request->get('description', '')),
                'prix'              => trim((string) $request->request->get('prix', '')),
                'type'              => trim((string) $request->request->get('type', '')),
                'meuble'            => trim((string) $request->request->get('meuble', '')),
                'superficie'        => trim((string) $request->request->get('superficie', '')),
                'nombreChambres'    => trim((string) $request->request->get('nombreChambres', '')),
                'sallesBain'        => trim((string) $request->request->get('sallesBain', '')),
                'adresse_text'      => trim((string) $request->request->get('adresse_text', '')),
                'caution_mois'      => trim((string) $request->request->get('caution_mois', '')),
                'dateDisponibilite' => $dateDispoFull,
                'dateImprevu'       => $dateImprevu,
                'dateVal'           => $dateVal,
                'heureVal'          => $timeVal,
            ];
            $old['statut'] = $this->computeStatut($old['dateDisponibilite']);

            $errors = $this->validateBienInput($old, false);

            $newImagePaths = [];
            if (empty($errors)) {
                $uploaded = $request->files->get('photos', []);
                if (!is_array($uploaded)) { $uploaded = [$uploaded]; }
                $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $uploadDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/public/images/upload';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

                foreach ($uploaded as $file) {
                    if (!$file instanceof UploadedFile) { continue; }
                    if (!$file->isValid()) { $errors[] = "Échec de l'envoi d'une image."; break; }
                    if ($file->getSize() > self::MAX_PROFILE_IMAGE_BYTES) { $errors[] = "Image trop lourde (5 Mo max)."; break; }
                    $mime = (string) $file->getMimeType();
                    if (!in_array($mime, $allowedMime, true)) { $errors[] = "Format d'image non supporté."; break; }
                    $ext = $file->guessExtension() ?: 'jpg';
                    $name = bin2hex(random_bytes(16)) . '.' . $ext;
                    try {
                        $file->move($uploadDir, $name);
                    } catch (\Throwable) {
                        $errors[] = "Impossible d'enregistrer une image.";
                        break;
                    }
                    $newImagePaths[] = 'images/upload/' . $name;
                }
            }

            $removeImages = (array) $request->request->all('remove_images');
            $keptImages = array_values(array_diff($existingImages, $removeImages));
            $finalImages = array_merge($keptImages, $newImagePaths);

            if (empty($finalImages)) {
                $errors['photos'] = "Au moins une photo est requise.";
            }

            if (!empty($errors)) {
                return $this->render('owner/edit_bien.html.twig', [
                    'id' => $id,
                    'old' => $old,
                    'existing_images' => $existingImages,
                    'errors' => $errors,
                ]);
            }

            try {
                $connection->beginTransaction();
                $slugValue = $this->generateUniqueSlug($connection, $old['titre'], $id);
                $connection->executeStatement(
                    'UPDATE annonce SET titre = :titre, description = :description, prix = :prix,
                       statut = :statut, date_disponibilite = :dateDispo, photo_principale = :imgs,
                       type = :type, surface = :surface, chambres = :chambres, salles_de_bain = :sdb, adresse = :adresse, ville = :ville,
                       caution_mois = :caution, slug = :slug
                     WHERE id = :id AND proprietaireId = :pid',
                    [
                        'titre'       => $old['titre'],
                        'description' => $old['description'],
                        'prix'        => (float) $old['prix'],
                        'statut'      => $old['statut'],
                        'dateDispo'   => $old['dateDisponibilite'] !== '' ? $old['dateDisponibilite'] : null,
                        'imgs'        => implode(',', $finalImages),
                        'type'        => $old['type'],
                        'surface'     => (int) $old['superficie'],
                        'chambres'    => $old['nombreChambres'] !== '' ? (int) $old['nombreChambres'] : 0,
                        'sdb'         => $old['sallesBain'] !== '' ? (int) $old['sallesBain'] : 0,
                        'adresse'     => $old['adresse_text'] ? mb_substr($old['adresse_text'], 0, 255) : null,
                        'ville'       => $old['adresse_text'] ? mb_substr((explode(',', $old['adresse_text'])[count(explode(',', $old['adresse_text'])) - 1] ?? $old['adresse_text']), 0, 100) : null,
                        'caution'     => in_array($old['caution_mois'], ['1', '2', '3'], true) ? (int) $old['caution_mois'] : null,
                        'slug'        => $slugValue,
                        'id'          => $id,
                        'pid'         => (int) $user->getId(),
                    ]
                );

                $projectDir = is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '';
                foreach ($removeImages as $rel) {
                    $abs = $projectDir . '/public/' . ltrim((string) $rel, '/');
                    if (is_file($abs)) { @unlink($abs); }
                }

                $connection->commit();
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) { $connection->rollBack(); }
                $this->addFlash('error', "Erreur lors de la mise à jour : " . $e->getMessage());
                return $this->render('owner/edit_bien.html.twig', [
                    'id' => $id,
                    'old' => $old,
                    'existing_images' => $existingImages,
                    'errors' => [],
                ]);
            }

            $prevStatutForNotif = strtolower((string) ($row['statut'] ?? ''));
            $newStatutForNotif = strtolower((string) $old['statut']);
            if ($prevStatutForNotif !== $newStatutForNotif && $prevStatutForNotif !== '') {
                $titreForNotif = trim($old['titre']) !== '' ? $old['titre'] : (string) ($row['titre'] ?? 'sans titre');
                $ownerMessages = [
                    'bientot_disponible' => 'Votre annonce "%s" est maintenant disponible.',
                    'occupe_disponible'  => 'Votre annonce "%s" est maintenant disponible.',
                    'occupe_bientot'     => 'Votre annonce "%s" est maintenant visible aux locataires.',
                ];
                $key = $prevStatutForNotif.'_'.$newStatutForNotif;
                if (isset($ownerMessages[$key])) {
                    $this->insertNotification($connection, (int) $user->getId(), $id,
                        'STATUT_'.strtoupper($newStatutForNotif),
                        sprintf($ownerMessages[$key], $titreForNotif));
                }

                if ($newStatutForNotif === 'disponible' && in_array($prevStatutForNotif, ['bientot', 'occupe'], true)) {
                    try {
                        $wishUsers = $connection->fetchAllAssociative(
                            'SELECT utilisateurId FROM wishlist WHERE annonceId = :aid',
                            ['aid' => $id]
                        );
                        foreach ($wishUsers as $wu) {
                            $this->insertNotification($connection, (int) $wu['utilisateurId'], $id,
                                'WISHLIST_DISPONIBLE',
                                sprintf('L\'annonce "%s" de votre wishlist est maintenant disponible. Consultez-la !', $titreForNotif));
                        }
                    } catch (\Throwable) {}
                } elseif ($newStatutForNotif === 'bientot' && $prevStatutForNotif === 'occupe') {
                    try {
                        $wishUsers = $connection->fetchAllAssociative(
                            'SELECT utilisateurId FROM wishlist WHERE annonceId = :aid',
                            ['aid' => $id]
                        );
                        foreach ($wishUsers as $wu) {
                            $this->insertNotification($connection, (int) $wu['utilisateurId'], $id,
                                'WISHLIST_BIENTOT',
                                sprintf('L\'annonce "%s" de votre wishlist sera bientôt visible.', $titreForNotif));
                        }
                    } catch (\Throwable) {}
                }
            }

            $this->addFlash('success', 'Annonce mise à jour.');
            // Redirect via slug if available so the owner lands on the pretty URL
            $slugForRedirect = $connection->fetchOne('SELECT slug FROM annonce WHERE id = :id', ['id' => $id]);
            if (is_string($slugForRedirect) && $slugForRedirect !== '') {
                return $this->redirectToRoute('owner_bien_show_slug', ['slug' => $slugForRedirect]);
            }
            return $this->redirectToRoute('owner_bien_show', ['id' => $id]);
        }

        return $this->render('owner/edit_bien.html.twig', [
            'id' => $id,
            'old' => $old,
            'existing_images' => $existingImages,
            'errors' => [],
        ]);
    }

    #[Route('/bien/{id}/delete', name: 'owner_bien_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteBien(int $id, Request $request, Connection $connection): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('owner_bien_delete_' . $id, $token)) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('owner_biens');
        }

        $row = $connection->fetchAssociative(
            'SELECT id, photo_principale FROM annonce WHERE id = :id AND proprietaireId = :pid',
            ['id' => $id, 'pid' => (int) $user->getId()]
        );
        if (!$row) {
            $this->addFlash('error', "Bien introuvable.");
            return $this->redirectToRoute('owner_biens');
        }

        try {
            $connection->beginTransaction();
            $connection->executeStatement(
                'DELETE FROM annonce WHERE id = :id AND proprietaireId = :pid',
                ['id' => $id, 'pid' => (int) $user->getId()]
            );
            $connection->commit();

            $projectDir = is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '';
            $photoPrincipale = (string) ($row['photo_principale'] ?? '');
            $imgs = $photoPrincipale !== '' ? array_filter(array_map('trim', explode(',', $photoPrincipale))) : [];
            foreach ($imgs as $rel) {
                $abs = $projectDir . '/public/' . ltrim($rel, '/');
                if (is_file($abs)) { @unlink($abs); }
            }
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) { $connection->rollBack(); }
            $this->addFlash('error', "Suppression impossible : " . $e->getMessage());
            return $this->redirectToRoute('owner_biens');
        }

        $this->addFlash('success', 'Annonce supprimée.');
        return $this->redirectToRoute('owner_biens');
    }

    private function computeStatut(?string $dateDisponibilite): string
    {
        if ($dateDisponibilite === null || $dateDisponibilite === '') {
            return 'bientot';
        }

        $dispo = $this->parseDateDispo($dateDisponibilite);
        if (!$dispo) {
            return 'occupe';
        }

        $hasTime = str_contains($dateDisponibilite, ' ');
        $now = $hasTime ? new \DateTime() : new \DateTime('today');
        $diffSeconds = $dispo->getTimestamp() - $now->getTimestamp();

        if ($diffSeconds <= 0) {
            return 'disponible';
        }
        if ($diffSeconds <= 7 * 24 * 3600) {
            return 'bientot';
        }
        return 'occupe';
    }

    private function parseDateDispo(string $val): ?\DateTime
    {
        $val = trim($val);
        if ($val === '') {
            return null;
        }
        if (str_contains($val, ' ')) {
            $d = \DateTime::createFromFormat('!Y-m-d H:i:s', $val)
              ?: \DateTime::createFromFormat('!Y-m-d H:i', $val);
        } else {
            $d = \DateTime::createFromFormat('!Y-m-d', $val);
        }
        return $d ?: null;
    }

    private function refreshStatuts(Connection $connection): void
    {
        $rows = $connection->fetchAllAssociative(
            "SELECT id, proprietaireId, titre, statut, date_disponibilite
             FROM annonce WHERE LOWER(statut) IN (:sOccupe, :sBientot)
                OR (LOWER(statut) = :sDisponible AND (date_disponibilite IS NULL OR date_disponibilite = :emptyStr))",
            [
                'sOccupe'     => 'occupe',
                'sBientot'    => 'bientot',
                'sDisponible' => 'disponible',
                'emptyStr'    => '',
            ]
        );

        foreach ($rows as $row) {
            $oldStatut = strtolower((string) ($row['statut'] ?? ''));
            $newStatut = $this->computeStatut($row['date_disponibilite'] ?: null);

            if ($newStatut === $oldStatut) {
                continue;
            }

            try {
                $connection->executeStatement(
                    'UPDATE annonce SET statut = :s WHERE id = :id',
                    ['s' => $newStatut, 'id' => (int) $row['id']]
                );
            } catch (\Throwable) {
                continue;
            }

            $ownerId = (int) ($row['proprietaireId'] ?? 0);
            if ($ownerId <= 0) {
                continue;
            }
            $titre = (string) ($row['titre'] ?? 'sans titre');
            $annonceId = (int) $row['id'];

            $messages = [
                'occupe_bientot'     => 'Votre annonce "%s" est maintenant visible aux locataires.',
                'bientot_disponible' => 'Votre annonce "%s" est maintenant disponible.',
                'occupe_disponible'  => 'Votre annonce "%s" est maintenant disponible.',
            ];
            $key = $oldStatut . '_' . $newStatut;
            if (isset($messages[$key])) {
                $this->insertNotification($connection, $ownerId, $annonceId,
                    'STATUT_' . strtoupper($newStatut),
                    sprintf($messages[$key], $titre));
            }

            // Notify locataires who wishlisted this annonce when it becomes disponible
            if ($newStatut === 'disponible' && in_array($oldStatut, ['bientot', 'occupe'], true)) {
                try {
                    $wishUsers = $connection->fetchAllAssociative(
                        'SELECT utilisateurId FROM wishlist WHERE annonceId = :aid',
                        ['aid' => $annonceId]
                    );
                    foreach ($wishUsers as $wu) {
                        $this->insertNotification($connection, (int) $wu['utilisateurId'], $annonceId,
                            'WISHLIST_DISPONIBLE',
                            sprintf('L\'annonce "%s" de votre wishlist est maintenant disponible. Consultez-la !', $titre));
                    }
                } catch (\Throwable) {}
            }

            // Notify locataires who wishlisted this annonce when it switches occupe -> bientot
            if ($newStatut === 'bientot' && $oldStatut === 'occupe') {
                try {
                    $wishUsers = $connection->fetchAllAssociative(
                        'SELECT utilisateurId FROM wishlist WHERE annonceId = :aid',
                        ['aid' => $annonceId]
                    );
                    foreach ($wishUsers as $wu) {
                        $this->insertNotification($connection, (int) $wu['utilisateurId'], $annonceId,
                            'WISHLIST_BIENTOT',
                            sprintf('L\'annonce "%s" de votre wishlist sera bientôt visible.', $titre));
                    }
                } catch (\Throwable) {}
            }
        }
    }

    private function insertNotification(Connection $connection, int $ownerId, int $annonceId, string $type, string $message): void
    {
        try {
            $connection->executeStatement(
                'INSERT INTO notification (destinataire_id, annonce_id, type, message, is_read, created_at)
                 VALUES (:d, :a, :t, :m, 0, :now)',
                ['d' => $ownerId, 'a' => $annonceId, 't' => $type, 'm' => $message,
                 'now' => (new \DateTime())->format('Y-m-d H:i:s')]
            );
        } catch (\Throwable) {}
    }

    private function ensureAnnonceCautionColumn(Connection $connection): void
    {
        // Auto-heal: add caution_mois column on existing annonce tables that pre-date this feature.
        // Harmless no-op if the column already exists (MySQL throws "Duplicate column name").
        try {
            $connection->executeStatement('ALTER TABLE annonce ADD COLUMN caution_mois SMALLINT NULL');
        } catch (\Throwable) {}
    }

    /**
     * Generates a URL-friendly slug from the title and ensures uniqueness against
     * existing annonce rows. Used because annonce CRUD is done via DBAL, not ORM,
     * so the Gedmo Sluggable listener never fires.
     */
    private function generateUniqueSlug(Connection $connection, string $titre, ?int $excludeId = null): string
    {
        $slugger = new \Symfony\Component\String\Slugger\AsciiSlugger();
        $base = strtolower($slugger->slug($titre)->toString());
        if ($base === '') {
            $base = 'annonce';
        }

        $slug = $base;
        $i = 2;
        while (true) {
            $sql = 'SELECT COUNT(*) FROM annonce WHERE slug = :s';
            $params = ['s' => $slug];
            if ($excludeId !== null) {
                $sql .= ' AND id != :id';
                $params['id'] = $excludeId;
            }
            try {
                $count = (int) $connection->fetchOne($sql, $params);
            } catch (\Throwable) {
                // slug column missing → migration not yet applied, fall back to base
                return $base;
            }
            if ($count === 0) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    private function validateBienInput(array $in, bool $checkDateFuture = true): array
    {
        $errors = [];

        $allowedTypes  = ['Appartement', 'Villa', 'Studio', 'Maison', 'Bureau'];
        $allowedMeuble = ['', 'Oui, entièrement meublé', 'Partiellement meublé', 'Non meublé'];

        $titre = (string) ($in['titre'] ?? '');
        if ($titre === '') {
            $errors['titre'] = "Le titre est requis.";
        } elseif (mb_strlen($titre) < 3) {
            $errors['titre'] = "Le titre doit contenir au moins 3 caractères.";
        } elseif (mb_strlen($titre) > 150) {
            $errors['titre'] = "Le titre ne doit pas dépasser 150 caractères.";
        } elseif ($titre !== strip_tags($titre)) {
            $errors['titre'] = "Le titre ne peut pas contenir de balises HTML.";
        }

        $description = (string) ($in['description'] ?? '');
        if ($description === '') {
            $errors['description'] = "La description est requise.";
        } elseif (mb_strlen($description) < 10) {
            $errors['description'] = "La description doit contenir au moins 10 caractères.";
        } elseif (mb_strlen($description) > 5000) {
            $errors['description'] = "La description ne doit pas dépasser 5000 caractères.";
        }

        $prix = (string) ($in['prix'] ?? '');
        if ($prix === '') {
            $errors['prix'] = "Le prix est requis.";
        } elseif (!is_numeric($prix)) {
            $errors['prix'] = "Le prix doit être un nombre.";
        } else {
            $prixF = (float) $prix;
            if ($prixF < 0) {
                $errors['prix'] = "Le prix ne peut pas être négatif.";
            } elseif ($prixF < 100) {
                $errors['prix'] = "Le prix doit être supérieur ou égal à 100 TND.";
            } elseif ($prixF > 1000000) {
                $errors['prix'] = "Le prix semble irréaliste (max 1 000 000 TND).";
            }
        }

        $type = (string) ($in['type'] ?? '');
        if ($type === '' || $type === '-- Sélectionner --') {
            $errors['type'] = "Le type de bien est requis.";
        } elseif (!in_array($type, $allowedTypes, true)) {
            $errors['type'] = "Type de bien invalide.";
        }

        $meuble = (string) ($in['meuble'] ?? '');
        if (!in_array($meuble, $allowedMeuble, true)) {
            $errors['meuble'] = "Valeur 'meublé' invalide.";
        }

        $superficie = (string) ($in['superficie'] ?? '');
        if ($superficie === '') {
            $errors['superficie'] = "La surface est requise.";
        } elseif (!is_numeric($superficie)) {
            $errors['superficie'] = "La surface doit être un nombre.";
        } else {
            $supF = (float) $superficie;
            if ($supF < 0) {
                $errors['superficie'] = "La surface ne peut pas être négative.";
            } elseif ($supF < 70) {
                $errors['superficie'] = "La surface doit être supérieure ou égale à 70 m².";
            } elseif ($supF > 10000) {
                $errors['superficie'] = "Surface irréaliste (max 10 000 m²).";
            }
        }

        $chambres = (string) ($in['nombreChambres'] ?? '');
        if ($chambres === '') {
            $errors['nombreChambres'] = "Le nombre de chambres est requis.";
        } elseif (!ctype_digit($chambres)) {
            $errors['nombreChambres'] = "Le nombre de chambres doit être un entier positif.";
        } elseif ((int) $chambres > 50) {
            $errors['nombreChambres'] = "Nombre de chambres irréaliste (max 50).";
        }

        $sdb = (string) ($in['sallesBain'] ?? '');
        if ($sdb !== '') {
            if (!ctype_digit($sdb)) {
                $errors['sallesBain'] = "Le nombre de salles de bain doit être un entier positif.";
            } elseif ((int) $sdb > 20) {
                $errors['sallesBain'] = "Nombre de salles de bain irréaliste (max 20).";
            }
        }

        $adresse = (string) ($in['adresse_text'] ?? '');
        if ($adresse === '') {
            $errors['adresse_text'] = "L'adresse est requise.";
        } elseif (mb_strlen($adresse) < 5) {
            $errors['adresse_text'] = "L'adresse doit contenir au moins 5 caractères.";
        } elseif (mb_strlen($adresse) > 255) {
            $errors['adresse_text'] = "L'adresse ne doit pas dépasser 255 caractères.";
        }

        $caution = (string) ($in['caution_mois'] ?? '');
        if ($caution === '') {
            $errors['caution_mois'] = "La caution est requise (1, 2 ou 3 mois).";
        } elseif (!in_array($caution, ['1', '2', '3'], true)) {
            $errors['caution_mois'] = "La caution doit être 1, 2 ou 3 mois.";
        }

        $isImprevu = !empty($in['dateImprevu']);
        $date = (string) ($in['dateDisponibilite'] ?? '');

        if (!$isImprevu) {
            $datePartOnly = explode(' ', $date)[0] ?? '';
            if ($date === '') {
                $errors['dateDisponibilite'] = "La date de disponibilité est requise (ou cochez 'Date imprévue').";
            } else {
                $d = \DateTime::createFromFormat('!Y-m-d', $datePartOnly);
                if (!$d || $d->format('Y-m-d') !== $datePartOnly) {
                    $errors['dateDisponibilite'] = "Date invalide.";
                } else {
                    $today = new \DateTime('today');
                    $maxDate = (new \DateTime())->modify('+5 years');

                    if ($checkDateFuture && $d < $today) {
                        $errors['dateDisponibilite'] = "La date doit être supérieure ou égale à aujourd'hui.";
                    } elseif ($d > $maxDate) {
                        $errors['dateDisponibilite'] = "La date ne peut pas dépasser 5 ans.";
                    }
                }
            }
        }

        return $errors;
    }

    private function ensureAvisTableExists(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS avis (
                id INT AUTO_INCREMENT PRIMARY KEY,
                annonce_id INT NOT NULL,
                user_id INT NOT NULL,
                note TINYINT UNSIGNED NOT NULL,
                commentaire TEXT NOT NULL,
                is_masked TINYINT(1) NOT NULL DEFAULT 0,
                date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_avis (annonce_id, user_id),
                FOREIGN KEY (annonce_id) REFERENCES annonce(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES utilisateur(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
        // Auto-heal: add is_masked column on existing tables that pre-date profanity moderation.
        // Harmless no-op if the column already exists.
        try {
            $connection->executeStatement('ALTER TABLE avis ADD COLUMN is_masked TINYINT(1) NOT NULL DEFAULT 0');
        } catch (\Throwable) {}
    }

    #[Route('/notifications', name: 'owner_notifications', methods: ['GET'])]
    public function notifications(NotificationService $notificationService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        return $this->render('owner/notifications.html.twig', [
            'notifications' => $notificationService->getRecent((int) $user->getId(), 100),
        ]);
    }

    #[Route('/notifications/{id}/read', name: 'owner_notification_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function notificationRead(int $id, Request $request, NotificationService $notificationService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid('owner_notif_'.$id, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('owner_notifications');
        }
        $notificationService->markRead($id, (int) $user->getId());
        return $this->redirectToRoute('owner_notifications');
    }

    #[Route('/notifications/read-all', name: 'owner_notifications_read_all', methods: ['POST'])]
    public function notificationsReadAll(Request $request, NotificationService $notificationService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        if (!$this->isCsrfTokenValid('owner_notifs_read_all', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('owner_notifications');
        }
        $notificationService->markAllRead((int) $user->getId());
        return $this->redirectToRoute('owner_notifications');
    }
}
