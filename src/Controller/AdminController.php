<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\Reclamation;
use App\Entity\Traitement;
use App\Form\TraitementFormType;
use App\Repository\UtilisateurRepository;
use App\Repository\TraitementRepository;
use App\Service\AdminAuditService;
use App\Service\AdminDataService;
use App\Service\AuthValidationService;
use App\Service\SecurityNotificationService;
use App\Service\UserSecurityStateService;
use App\Service\SightengineService;
use App\Service\BrevoService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Route('/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(AdminDataService $adminDataService): Response
    {
        return $this->render('admin/dashboard.html.twig', $adminDataService->getDashboardData());
    }

    #[Route('/users', name: 'admin_users', methods: ['GET', 'POST'])]
    public function users(
        Request $request,
        EntityManagerInterface $entityManager,
        UtilisateurRepository $utilisateurRepository,
        AuthValidationService $validationService,
        UserPasswordHasherInterface $passwordHasher,
        AdminDataService $adminDataService,
        AdminAuditService $adminAuditService,
        SecurityNotificationService $securityNotificationService,
        UserSecurityStateService $userSecurityStateService,
    ): Response {
        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');
            $this->handleUsersPostAction(
                $action,
                $request,
                $entityManager,
                $utilisateurRepository,
                $validationService,
                $passwordHasher,
                $adminAuditService,
                $securityNotificationService,
                $userSecurityStateService,
                $adminDataService
            );

            return $this->redirectToRoute('admin_users');
        }

        $search = trim((string) $request->query->get('q', ''));
        $role = strtoupper(trim((string) $request->query->get('role', 'TOUS')));

        return $this->render('admin/users.html.twig', [
            'users' => $adminDataService->getUsers($search, $role),
            'stats' => $adminDataService->getUsersStats(),
            'filters' => [
                'q' => $search,
                'role' => $role === '' ? 'TOUS' : $role,
            ],
            'roles' => ['TOUS', 'LOCATAIRE', 'PROPRIETAIRE', 'ADMIN'],
        ]);
    }

    #[Route('/users/{id}', name: 'admin_user_fiche', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function userFiche(int $id, Request $request, AdminDataService $adminDataService): Response
    {
        $fiche = $adminDataService->getUserFiche($id);
        if ($fiche === null) {
            $this->addFlash('auth_error', 'Utilisateur introuvable.');

            return $this->redirectToRoute('admin_users');
        }

        $role = strtoupper((string) ($fiche['profile']['role'] ?? ''));

        // Pagination pour les paiements de loyer
        $pageLoyer = (int) $request->query->get('page_loyer', 1);
        $limitLoyer = 10;
        $loyerPaginated = $adminDataService->getUserFichePaiementsLoyerPaginated($id, $role, $pageLoyer, $limitLoyer);

        // Pagination pour les paiements de charges
        $pageCharges = (int) $request->query->get('page_charges', 1);
        $limitCharges = 10;
        $chargesPaginated = $adminDataService->getUserFichePaiementsChargesPaginated($id, $role, $pageCharges, $limitCharges);

        return $this->render('admin/user_fiche.html.twig', [
            'fiche' => $fiche,
            'contrats' => $adminDataService->getUserContrats($id),
            'paiements_loyer' => $loyerPaginated['items'],
            'pager_loyer' => $loyerPaginated['pager'],
            'paiements_charges' => $chargesPaginated['items'],
            'pager_charges' => $chargesPaginated['pager'],
            'page_loyer' => $pageLoyer,
            'page_charges' => $pageCharges,
        ]);
    }

    #[Route('/audit', name: 'admin_audit', methods: ['GET'])]
    public function audit(Request $request, AdminAuditService $adminAuditService): Response
    {
        $search = trim((string) $request->query->get('q', ''));

        return $this->render('admin/audit.html.twig', [
            'audit_rows' => $adminAuditService->getAuditRows($search, 250),
            'filters' => ['q' => $search],
        ]);
    }

    #[Route('/annonces', name: 'admin_annonces', methods: ['GET', 'POST'])]
    public function annonces(Request $request, AdminDataService $adminDataService): Response
    {
        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');
            $this->handleAnnoncesPostAction($action, $request, $adminDataService);

            return $this->redirectToRoute('admin_annonces');
        }

        $search = trim((string) $request->query->get('q', ''));
        $status = strtoupper(trim((string) $request->query->get('status', 'TOUS')));

        return $this->render('admin/annonces.html.twig', [
            'annonces' => $adminDataService->getAnnonces($search, $status),
            'stats' => $adminDataService->getAnnoncesStats(),
            'filters' => ['q' => $search, 'status' => $status === '' ? 'TOUS' : $status],
            'status_options' => ['TOUS', 'DISPONIBLE', 'OCCUPE', 'BIENTOT DISPONIBLE'],
            'proprietaires' => $adminDataService->getProprietaires(),
        ]);
    }

    #[Route('/reservations', name: 'admin_reservations', methods: ['GET', 'POST'])]
    public function reservations(Request $request, AdminDataService $adminDataService): Response
    {
        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');
            $this->handleReservationsPostAction($action, $request, $adminDataService);

            return $this->redirectToRoute('admin_reservations');
        }

        $search = trim((string) $request->query->get('q', ''));
        $status = strtoupper(trim((string) $request->query->get('status', 'TOUS')));

        return $this->render('admin/reservations.html.twig', [
            'reservations' => $adminDataService->getReservations($search, $status),
            'stats' => $adminDataService->getReservationsStats(),
            'filters' => ['q' => $search, 'status' => $status === '' ? 'TOUS' : $status],
            'status_options' => ['TOUS', 'EN ATTENTE', 'CONFIRMEE', 'ANNULEE'],
        ]);
    }

    #[Route('/contrats', name: 'admin_contrats', methods: ['GET', 'POST'])]
    public function contrats(Request $request, AdminDataService $adminDataService): Response
    {
        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');
            $this->handleContratsPostAction($action, $request, $adminDataService);

            return $this->redirectToRoute('admin_contrats');
        }

        $search = trim((string) $request->query->get('q', ''));
        $status = strtoupper(trim((string) $request->query->get('status', 'TOUS')));

        return $this->render('admin/contrats.html.twig', [
            'contrats' => $adminDataService->getContrats($search, $status),
            'stats' => $adminDataService->getContratsStats(),
            'filters' => ['q' => $search, 'status' => $status === '' ? 'TOUS' : $status],
            'status_options' => ['TOUS', 'ACTIF', 'EN ATTENTE', 'RESILIE'],
        ]);
    }

    #[Route('/reclamations', name: 'admin_reclamations', methods: ['GET', 'POST'])]
    public function reclamations(Request $request, AdminDataService $adminDataService, PaginatorInterface $paginator): Response
    {
        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');
            $this->handleReclamationsPostAction($action, $request, $adminDataService);

            return $this->redirectToRoute('admin_reclamations');
        }

        $search = trim((string) $request->query->get('q', ''));
        $status = strtoupper(trim((string) $request->query->get('status', 'TOUS')));

        $allReclamations = $adminDataService->getReclamations($search, $status);
        $reclamations = $paginator->paginate(
            $allReclamations,
            $request->query->getInt('page', 1),
            5
        );

        return $this->render('admin/reclamations.html.twig', [
            'reclamations' => $reclamations,
            'stats' => $adminDataService->getReclamationsStats(),
            'filters' => ['q' => $search, 'status' => $status === '' ? 'TOUS' : $status],
            'status_options' => ['TOUS', 'EN_ATTENTE', 'EN_COURS', 'RESOLU'],
        ]);
    }

    #[Route('/reclamations/{id}/traiter', name: 'admin_reclamation_traiter', methods: ['GET', 'POST'])]
    public function traiterReclamation(
        Reclamation $reclamation,
        Request $request,
        EntityManagerInterface $entityManager,
        TraitementRepository $traitementRepository,
        SightengineService $sightengine,
        BrevoService $brevo
    ): Response {
        $traitement = $traitementRepository->findOneBy(['reclamationId' => $reclamation]);
        if (!$traitement) {
            $traitement = new Traitement();
            $traitement->setReclamationId($reclamation);
            $traitement->setDateTraitement(new \DateTime());
        }

        if ($reclamation->getStatut() === 'EN_ATTENTE') {
            $reclamation->setStatut('EN_COURS');
            $entityManager->flush();
        }

        $form = $this->createForm(TraitementFormType::class, $traitement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $responseContent = $form->get('reponse')->getData();

            if (!$sightengine->isTextSafe($responseContent)) {
                $form->get('reponse')->addError(new FormError('Contenu inapproprié, veuillez réessayer.'));
                return $this->render('admin/reclamation_traiter.html.twig', [
                    'reclamation' => $reclamation,
                    'form' => $form->createView(),
                    'traitement' => $traitement,
                    'summary' => substr($reclamation->getDescription() ?? '', 0, 100) . '...'
                ]);
            }

            $entityManager->persist($traitement);
            $entityManager->flush();

            $tenantEmail = "votre-locataire@example.com";
            $tenantName = "Locataire #" . $reclamation->getLocataireId();
            $subject = "Réponse à votre réclamation #" . $reclamation->getId();
            $htmlContent = "<h1>Réponse reçue (Admin) !</h1><p>Votre réclamation a été traitée par l'administration.</p><p><strong>Réponse:</strong><br>" . nl2br($responseContent) . "</p>";

            $brevo->sendEmail($tenantEmail, $tenantName, $subject, $htmlContent);

            $this->addFlash('auth_success', 'Réponse envoyée au locataire avec succès.');
            return $this->redirectToRoute('admin_reclamations');
        }

        $summary = substr($reclamation->getDescription() ?? '', 0, 100) . '...';

        return $this->render('admin/reclamation_traiter.html.twig', [
            'reclamation' => $reclamation,
            'summary' => $summary,
            'form' => $form->createView(),
            'traitement' => $traitement
        ]);
    }

    #[Route('/reclamations/{id}', name: 'admin_reclamation_show', methods: ['GET'])]
    public function showReclamation(
        Reclamation $reclamation
    ): Response {
        $summary = substr($reclamation->getDescription() ?? '', 0, 100) . '...';

        return $this->render('admin/reclamation_show.html.twig', [
            'reclamation' => $reclamation,
            'summary' => $summary
        ]);
    }

    #[Route('/settings', name: 'admin_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, AdminDataService $adminDataService): Response
    {
        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', '');
            $csrf = (string) $request->request->get('_csrf_token', '');
            if (!$this->isCsrfTokenValid('admin_settings', $csrf)) {
                $this->addFlash('auth_error', 'Session invalide. Veuillez reessayer.');

                return $this->redirectToRoute('admin_settings');
            }

            if ($action === 'save_settings') {
                $section = (string) $request->request->get('settings_section', '');
                $settings = $adminDataService->getAdminSettings();

                if ($section === 'notifications') {
                    $notifEmail = trim((string) $request->request->get('notif_email', $settings['notif_email'] ?? 'admin@sakan.tn'));
                    if ($notifEmail !== '') {
                        $settings['notif_email'] = $notifEmail;
                    }
                    $settings['notif_reservation'] = $request->request->getBoolean('notif_reservation') ? '1' : '0';
                    $settings['notif_reclamation'] = $request->request->getBoolean('notif_reclamation') ? '1' : '0';
                    $settings['notif_retard'] = $request->request->getBoolean('notif_retard') ? '1' : '0';
                } elseif ($section === 'preferences') {
                    $settings['sec_2fa'] = $request->request->getBoolean('sec_2fa') ? '1' : '0';
                    $settings['sec_lock'] = $request->request->getBoolean('sec_lock') ? '1' : '0';
                    $settings['sec_audit'] = $request->request->getBoolean('sec_audit') ? '1' : '0';
                    $settings['ui_animations'] = $request->request->getBoolean('ui_animations') ? '1' : '0';
                    $settings['ui_compact'] = $request->request->getBoolean('ui_compact') ? '1' : '0';
                } else {
                    $notifEmail = trim((string) $request->request->get('notif_email', $settings['notif_email'] ?? 'admin@sakan.tn'));
                    if ($notifEmail !== '') {
                        $settings['notif_email'] = $notifEmail;
                    }
                    $settings['notif_reservation'] = $request->request->getBoolean('notif_reservation') ? '1' : '0';
                    $settings['notif_reclamation'] = $request->request->getBoolean('notif_reclamation') ? '1' : '0';
                    $settings['notif_retard'] = $request->request->getBoolean('notif_retard') ? '1' : '0';
                    $settings['sec_2fa'] = $request->request->getBoolean('sec_2fa') ? '1' : '0';
                    $settings['sec_lock'] = $request->request->getBoolean('sec_lock') ? '1' : '0';
                    $settings['sec_audit'] = $request->request->getBoolean('sec_audit') ? '1' : '0';
                    $settings['ui_animations'] = $request->request->getBoolean('ui_animations') ? '1' : '0';
                    $settings['ui_compact'] = $request->request->getBoolean('ui_compact') ? '1' : '0';
                }

                $saved = $adminDataService->saveAdminSettings($settings);
                $this->addFlash($saved ? 'auth_success' : 'auth_error', $saved ? 'Parametres admin enregistres.' : 'Impossible d enregistrer les parametres.');
            } elseif ($action === 'check_db') {
                $ok = $adminDataService->checkDatabaseConnection();
                $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Connexion a la base de donnees OK.' : 'Connexion a la base de donnees indisponible.');
            } elseif ($action === 'export_sql') {
                try {
                    $projectDir = $this->getParameter('kernel.project_dir');
                    $targetDir = (is_string($projectDir) ? $projectDir : '').DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'exports';
                    $export = $adminDataService->createSqlExportFile($targetDir);

                    $response = new BinaryFileResponse($export['path']);
                    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $export['name']);
                    $response->headers->set('Content-Type', 'application/sql; charset=utf-8');
                    $response->headers->set('X-Content-Type-Options', 'nosniff');

                    return $response;
                } catch (\Throwable $exception) {
                    $this->addFlash('auth_error', 'Export SQL echoue: '.$exception->getMessage());
                }
            } elseif ($action === 'export_csv') {
                try {
                    $projectDir = $this->getParameter('kernel.project_dir');
                    $targetDir = (is_string($projectDir) ? $projectDir : '').DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'exports';
                    $export = $adminDataService->createCsvExportFile($targetDir);

                    $response = new BinaryFileResponse($export['path']);
                    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $export['name']);
                    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
                    $response->headers->set('X-Content-Type-Options', 'nosniff');

                    return $response;
                } catch (\Throwable $exception) {
                    $this->addFlash('auth_error', 'Export CSV echoue: '.$exception->getMessage());
                }
            } elseif ($action === 'clear_logs_demo') {
                $this->addFlash('auth_success', 'Logs nettoyes (mode demo).');
            }

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'settings' => $adminDataService->getAdminSettings(),
        ]);
    }

    private function handleUsersPostAction(
        string $action,
        Request $request,
        EntityManagerInterface $entityManager,
        UtilisateurRepository $utilisateurRepository,
        AuthValidationService $validationService,
        UserPasswordHasherInterface $passwordHasher,
        AdminAuditService $adminAuditService,
        SecurityNotificationService $securityNotificationService,
        UserSecurityStateService $userSecurityStateService,
        AdminDataService $adminDataService,
    ): void {
        $currentAdmin = $this->getUser();
        if (!$currentAdmin instanceof Utilisateur) {
            $currentAdmin = null;
        }

        if ($action === 'add_user') {
            $csrf = (string) $request->request->get('_csrf_token', '');
            if (!$this->isCsrfTokenValid('admin_add_user', $csrf)) {
                $this->addFlash('auth_error', 'Session invalide. Veuillez reessayer.');
                return;
            }

            $nom = trim((string) $request->request->get('nom', ''));
            $email = strtolower(trim((string) $request->request->get('email', '')));
            $telephone = trim((string) $request->request->get('telephone', ''));
            $role = strtoupper(trim((string) $request->request->get('role', 'LOCATAIRE')));
            $statut = strtoupper(trim((string) $request->request->get('statut', 'ACTIF')));
            $password = (string) $request->request->get('password', '');

            if ($nom === '' || $email === '' || $password === '') {
                $this->addFlash('auth_error', 'Nom, email et mot de passe sont obligatoires.');
                return;
            }
            if (!$validationService->isValidEmail($email)) {
                $this->addFlash('auth_error', 'Adresse email invalide.');
                return;
            }
            if (!$validationService->isValidPassword($password)) {
                $this->addFlash('auth_error', 'Le mot de passe doit contenir au moins 6 caracteres, une minuscule, une majuscule et un chiffre.');
                return;
            }
            if (!in_array($role, ['ADMIN', 'PROPRIETAIRE', 'LOCATAIRE'], true)) {
                $this->addFlash('auth_error', 'Role invalide.');
                return;
            }
            if (!in_array($statut, ['ACTIF', 'SUSPENDU'], true)) {
                $statut = 'ACTIF';
            }
            if ($telephone !== '' && !$validationService->isValidTunisiaPhone($telephone)) {
                $this->addFlash('auth_error', 'Numero de telephone invalide (8 chiffres).');
                return;
            }
            $telephoneE164 = $telephone !== '' ? $validationService->toTunisiaE164($telephone) : null;

            if ($utilisateurRepository->emailExists($email)) {
                $this->addFlash('auth_error', 'Cet email existe deja.');
                return;
            }
            if ($telephone !== '' && $utilisateurRepository->phoneExists($telephone, $telephoneE164)) {
                $this->addFlash('auth_error', 'Ce numero de telephone existe deja.');
                return;
            }

            $user = new Utilisateur();
            $user->setNom($nom)
                ->setEmail($email)
                ->setRoleName($role)
                ->setStatut($statut)
                ->setTelephone($telephone)
                ->setTelephoneE164($telephoneE164)
                ->setDateInscription(new \DateTime('today'));
            $user->setMotDePasse($passwordHasher->hashPassword($user, $password));

            $entityManager->persist($user);
            $entityManager->flush();
            $userSecurityStateService->bootstrapUser($user, true);
            $adminAuditService->logUserAction($currentAdmin, $user, 'user_created', [
                'role' => $role,
                'statut' => $statut,
            ]);
            if ($statut === 'SUSPENDU') {
                $securityNotificationService->sendAccountSuspended($user);
            }

            $this->addFlash('auth_success', 'Utilisateur ajoute avec succes.');
            return;
        }

        if ($action === 'edit_user') {
            $id = (int) $request->request->get('user_id', 0);
            $csrf = (string) $request->request->get('_csrf_token', '');
            if ($id <= 0 || !$this->isCsrfTokenValid('admin_edit_user_'.$id, $csrf)) {
                $this->addFlash('auth_error', 'Session invalide. Veuillez reessayer.');
                return;
            }

            $user = $utilisateurRepository->find($id);
            if (!$user instanceof Utilisateur) {
                $this->addFlash('auth_error', 'Utilisateur introuvable.');
                return;
            }

            $before = [
                'nom' => (string) $user->getNom(),
                'email' => (string) $user->getEmail(),
                'telephone' => (string) $user->getTelephone(),
                'role' => (string) $user->getRoleName(),
                'statut' => (string) $user->getStatut(),
            ];

            $nom = trim((string) $request->request->get('nom', ''));
            $email = strtolower(trim((string) $request->request->get('email', '')));
            $telephone = trim((string) $request->request->get('telephone', ''));
            $role = strtoupper(trim((string) $request->request->get('role', 'LOCATAIRE')));
            $statut = strtoupper(trim((string) $request->request->get('statut', 'ACTIF')));
            $newPassword = (string) $request->request->get('password', '');

            if ($nom === '' || $email === '') {
                $this->addFlash('auth_error', 'Nom et email sont obligatoires.');
                return;
            }
            if (!$validationService->isValidEmail($email)) {
                $this->addFlash('auth_error', 'Adresse email invalide.');
                return;
            }
            if ($utilisateurRepository->emailExistsForAnotherUser($email, $id)) {
                $this->addFlash('auth_error', 'Cet email est deja utilise par un autre compte.');
                return;
            }
            if (!in_array($role, ['ADMIN', 'PROPRIETAIRE', 'LOCATAIRE'], true)) {
                $role = 'LOCATAIRE';
            }
            if (!in_array($statut, ['ACTIF', 'SUSPENDU'], true)) {
                $statut = 'ACTIF';
            }

            $telephoneE164 = null;
            if ($telephone !== '') {
                if (!$validationService->isValidTunisiaPhone($telephone)) {
                    $this->addFlash('auth_error', 'Numero de telephone invalide.');
                    return;
                }
                $telephoneE164 = $validationService->toTunisiaE164($telephone);
                if ($utilisateurRepository->phoneExistsForAnotherUser($telephone, $telephoneE164, $id)) {
                    $this->addFlash('auth_error', 'Ce numero de telephone est deja utilise.');
                    return;
                }
            }

            $user->setNom($nom)
                ->setEmail($email)
                ->setRoleName($role)
                ->setStatut($statut)
                ->setTelephone($telephone)
                ->setTelephoneE164($telephoneE164);

            if (trim($newPassword) !== '') {
                if (!$validationService->isValidPassword($newPassword)) {
                    $this->addFlash('auth_error', 'Mot de passe invalide (6 caracteres min., minuscule, majuscule, chiffre).');
                    return;
                }
                $user->setMotDePasse($passwordHasher->hashPassword($user, $newPassword));
            }

            $entityManager->flush();
            $userSecurityStateService->bootstrapUser($user, trim((string) $user->getGoogleSub()) !== '');

            if (strcasecmp($before['email'], $email) !== 0) {
                $adminDataService->logEmailChange(
                    (int) $user->getId(),
                    $before['email'],
                    $email,
                    $currentAdmin?->getId(),
                    $currentAdmin?->getNom(),
                    'admin'
                );
            }

            if (trim($newPassword) !== '') {
                $userSecurityStateService->notePasswordChanged($user);
                $securityNotificationService->sendPasswordChanged($user);
            }

            if ($before['statut'] !== $statut) {
                if ($statut === 'SUSPENDU') {
                    $securityNotificationService->sendAccountSuspended($user);
                } else {
                    $securityNotificationService->sendAccountReactivated($user);
                }
            }

            $changedFields = [];
            foreach (['nom', 'email', 'telephone', 'role', 'statut'] as $field) {
                if (strcasecmp((string) $before[$field], (string) $$field) !== 0) {
                    $changedFields[] = $field;
                }
            }
            if (trim($newPassword) !== '') {
                $changedFields[] = 'password';
            }

            $adminAuditService->logUserAction($currentAdmin, $user, 'user_updated', [
                'changed_fields' => $changedFields,
                'role' => $role,
                'statut' => $statut,
            ]);

            $this->addFlash('auth_success', 'Utilisateur modifie avec succes.');
            return;
        }

        if ($action === 'toggle_user_status') {
            $id = (int) $request->request->get('user_id', 0);
            $newStatus = strtoupper(trim((string) $request->request->get('new_status', 'ACTIF')));
            $csrf = (string) $request->request->get('_csrf_token', '');
            if ($id <= 0 || !$this->isCsrfTokenValid('admin_toggle_user_'.$id, $csrf)) {
                $this->addFlash('auth_error', 'Session invalide. Veuillez reessayer.');
                return;
            }

            if (!in_array($newStatus, ['ACTIF', 'SUSPENDU'], true)) {
                $newStatus = 'ACTIF';
            }

            $user = $utilisateurRepository->find($id);
            if (!$user instanceof Utilisateur) {
                $this->addFlash('auth_error', 'Utilisateur introuvable.');
                return;
            }

            $currentUser = $this->getUser();
            if ($currentUser instanceof Utilisateur && $currentUser->getId() === $user->getId() && $newStatus === 'SUSPENDU') {
                $this->addFlash('auth_error', 'Vous ne pouvez pas suspendre votre propre compte admin.');
                return;
            }

            $user->setStatut($newStatus);
            $entityManager->flush();

            if ($newStatus === 'SUSPENDU') {
                $securityNotificationService->sendAccountSuspended($user);
            } else {
                $securityNotificationService->sendAccountReactivated($user);
            }

            $lastLogin = $userSecurityStateService->getLastLoginData($user);
            $adminAuditService->logUserAction(
                $currentAdmin,
                $user,
                $newStatus === 'SUSPENDU' ? 'user_suspended' : 'user_reactivated',
                [
                    'statut' => $newStatus,
                    'last_login_at' => $lastLogin['last_login_at'],
                ]
            );

            $this->addFlash('auth_success', $newStatus === 'SUSPENDU' ? 'Compte suspendu.' : 'Compte active.');
            return;
        }

        if ($action === 'delete_user') {
            $id = (int) $request->request->get('user_id', 0);
            $csrf = (string) $request->request->get('_csrf_token', '');
            if ($id <= 0 || !$this->isCsrfTokenValid('admin_delete_user_'.$id, $csrf)) {
                $this->addFlash('auth_error', 'Session invalide. Veuillez reessayer.');
                return;
            }

            $user = $utilisateurRepository->find($id);
            if (!$user instanceof Utilisateur) {
                $this->addFlash('auth_error', 'Utilisateur introuvable.');
                return;
            }

            $currentUser = $this->getUser();
            if ($currentUser instanceof Utilisateur && $currentUser->getId() === $user->getId()) {
                $this->addFlash('auth_error', 'Vous ne pouvez pas supprimer votre propre compte admin.');
                return;
            }

            try {
                $deletedPayload = [
                    'role' => $user->getRoleName(),
                    'statut' => $user->getStatut(),
                ];
                $entityManager->remove($user);
                $entityManager->flush();
                $adminAuditService->logUserAction($currentAdmin, $user, 'user_deleted', $deletedPayload);
                $this->addFlash('auth_success', 'Utilisateur supprime avec succes.');
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash('auth_error', 'Suppression impossible : utilisateur lie a des donnees existantes.');
            }
        }
    }

    private function handleAnnoncesPostAction(string $action, Request $request, AdminDataService $adminDataService): void
    {
        if ($action === 'create_annonce') {
            $csrf = (string) $request->request->get('_csrf_token', '');
            if (!$this->isCsrfTokenValid('admin_create_annonce', $csrf)) {
                $this->addFlash('auth_error', 'Session invalide.');
                return;
            }

            $title = trim((string) $request->request->get('titre', ''));
            $status = strtoupper(trim((string) $request->request->get('statut', 'DISPONIBLE')));
            $description = trim((string) $request->request->get('description', ''));
            $ownerRaw = trim((string) $request->request->get('proprietaire_id', ''));
            $ownerId = $ownerRaw === '' ? null : (int) $ownerRaw;
            if ($title === '') {
                $this->addFlash('auth_error', 'Le titre de l annonce est obligatoire.');
                return;
            }

            $ok = $adminDataService->createAnnonce($ownerId, $title, $status, $description);
            $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Annonce creee.' : 'Impossible de creer l annonce.');
            return;
        }

        if ($action === 'update_annonce') {
            $id = (int) $request->request->get('annonce_id', 0);
            $csrf = (string) $request->request->get('_csrf_token', '');
            if ($id <= 0 || !$this->isCsrfTokenValid('admin_update_annonce_'.$id, $csrf)) {
                $this->addFlash('auth_error', 'Session invalide.');
                return;
            }

            $title = trim((string) $request->request->get('titre', ''));
            $status = strtoupper(trim((string) $request->request->get('statut', 'DISPONIBLE')));
            if ($title === '') {
                $this->addFlash('auth_error', 'Le titre de l annonce est obligatoire.');
                return;
            }

            $ok = $adminDataService->updateAnnonce($id, $title, $status);
            $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Annonce modifiee.' : 'Impossible de modifier l annonce.');
            return;
        }

        if ($action === 'delete_annonce') {
            $id = (int) $request->request->get('annonce_id', 0);
            $csrf = (string) $request->request->get('_csrf_token', '');
            if ($id <= 0 || !$this->isCsrfTokenValid('admin_delete_annonce_'.$id, $csrf)) {
                $this->addFlash('auth_error', 'Session invalide.');
                return;
            }
            $ok = $adminDataService->deleteAnnonce($id);
            $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Annonce supprimee.' : 'Suppression impossible.');
            return;
        }

        if ($action === 'bulk_delete_annonces') {
            $csrf = (string) $request->request->get('_csrf_token', '');
            if (!$this->isCsrfTokenValid('admin_bulk_delete_annonces', $csrf)) {
                $this->addFlash('auth_error', 'Session invalide.');
                return;
            }
            $ids = $request->request->all('annonce_ids');
            if (empty($ids)) {
                $this->addFlash('auth_error', 'Aucune annonce sélectionnée.');
                return;
            }
            $deleted = 0;
            $failed = 0;
            foreach ($ids as $raw) {
                $id = (int) $raw;
                if ($id <= 0) { $failed++; continue; }
                if ($adminDataService->deleteAnnonce($id)) {
                    $deleted++;
                } else {
                    $failed++;
                }
            }
            if ($deleted > 0) {
                $this->addFlash('auth_success', sprintf('%d annonce%s supprimée%s.', $deleted, $deleted > 1 ? 's' : '', $deleted > 1 ? 's' : ''));
            }
            if ($failed > 0) {
                $this->addFlash('auth_error', sprintf('%d annonce%s n\'a pas pu être supprimée%s.', $failed, $failed > 1 ? 's' : '', $failed > 1 ? 's' : ''));
            }
        }
    }

    private function handleReservationsPostAction(string $action, Request $request, AdminDataService $adminDataService): void
    {
        $id = (int) $request->request->get('reservation_id', 0);
        $csrf = (string) $request->request->get('_csrf_token', '');
        if ($id <= 0 || !$this->isCsrfTokenValid('admin_reservation_'.$id, $csrf)) {
            $this->addFlash('auth_error', 'Session invalide.');
            return;
        }

        if ($action === 'update_reservation_status') {
            $status = strtoupper(trim((string) $request->request->get('statut', 'EN ATTENTE')));
            $ok = $adminDataService->updateReservationStatus($id, $status);
            $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Statut de la reservation mis a jour.' : 'Mise a jour impossible.');
            return;
        }

        if ($action === 'delete_reservation') {
            $ok = $adminDataService->deleteReservation($id);
            $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Reservation supprimee.' : 'Suppression impossible.');
        }
    }

    private function handleContratsPostAction(string $action, Request $request, AdminDataService $adminDataService): void
    {
        $id = (int) $request->request->get('contrat_id', 0);
        $csrf = (string) $request->request->get('_csrf_token', '');
        if ($id <= 0 || !$this->isCsrfTokenValid('admin_contrat_'.$id, $csrf)) {
            $this->addFlash('auth_error', 'Session invalide.');
            return;
        }

        if ($action === 'update_contrat_status') {
            $status = strtoupper(trim((string) $request->request->get('statut', 'ACTIF')));
            $ok = $adminDataService->updateContratStatus($id, $status);
            $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Statut du contrat mis a jour.' : 'Mise a jour impossible.');
            return;
        }

        if ($action === 'delete_contrat') {
            $ok = $adminDataService->deleteContrat($id);
            $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Contrat supprime.' : 'Suppression impossible.');
        }
    }

    #[Route('/avis/moderation', name: 'admin_avis_moderation', methods: ['GET'])]
    public function avisModeration(\Doctrine\DBAL\Connection $connection): Response
    {
        $masked = [];
        try {
            $masked = $connection->fetchAllAssociative(
                'SELECT av.id, av.annonce_id, av.user_id, av.note, av.commentaire, av.date_creation, av.is_masked,
                        u.nom AS auteur_nom, u.email AS auteur_email,
                        a.titre AS annonce_titre
                 FROM avis av
                 LEFT JOIN utilisateur u ON u.id = av.user_id
                 LEFT JOIN annonce a ON a.id = av.annonce_id
                 WHERE av.is_masked = 1
                 ORDER BY av.date_creation DESC'
            );
        } catch (\Throwable) {
            // Table or column missing — migration not applied yet
        }

        return $this->render('admin/avis_moderation.html.twig', [
            'masked' => $masked,
        ]);
    }

    #[Route('/avis/{id}/unmask', name: 'admin_avis_unmask', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function avisUnmask(int $id, Request $request, \Doctrine\DBAL\Connection $connection): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('admin_avis_unmask_' . $id, $token)) {
            $this->addFlash('auth_error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_avis_moderation');
        }

        try {
            $connection->executeStatement(
                'UPDATE avis SET is_masked = 0 WHERE id = :id',
                ['id' => $id]
            );
            $this->addFlash('auth_success', 'Avis réactivé avec succès.');
        } catch (\Throwable $e) {
            $this->addFlash('auth_error', 'Impossible de réactiver cet avis : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_avis_moderation');
    }

    #[Route('/avis/{id}/delete', name: 'admin_avis_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function avisDelete(int $id, Request $request, \Doctrine\DBAL\Connection $connection): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('admin_avis_delete_' . $id, $token)) {
            $this->addFlash('auth_error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_avis_moderation');
        }

        try {
            $connection->executeStatement('DELETE FROM avis WHERE id = :id', ['id' => $id]);
            $this->addFlash('auth_success', 'Avis supprimé définitivement.');
        } catch (\Throwable $e) {
            $this->addFlash('auth_error', 'Suppression impossible : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_avis_moderation');
    }

    private function handleReclamationsPostAction(string $action, Request $request, AdminDataService $adminDataService): void
    {
        $id = (int) $request->request->get('reclamation_id', 0);
        $csrf = (string) $request->request->get('_csrf_token', '');
        if ($id <= 0 || !$this->isCsrfTokenValid('admin_reclamation_'.$id, $csrf)) {
            $this->addFlash('auth_error', 'Session invalide.');
            return;
        }

        if ($action === 'update_reclamation') {
            $type = trim((string) $request->request->get('type', ''));
            $status = strtoupper(trim((string) $request->request->get('statut', 'OUVERT')));
            $ok = $adminDataService->updateReclamation($id, $type, $status);
            $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Reclamation mise a jour.' : 'Mise a jour impossible.');
            return;
        }

        if ($action === 'delete_reclamation') {
            $ok = $adminDataService->deleteReclamation($id);
            $this->addFlash($ok ? 'auth_success' : 'auth_error', $ok ? 'Reclamation supprimee.' : 'Suppression impossible.');
        }
    }
}
