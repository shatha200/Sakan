<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\AnnonceRepository;
use App\Repository\ContratRepository;
use App\Repository\UtilisateurRepository;
use App\Service\AuthOtpService;
use App\Service\AuthValidationService;
use App\Service\CautionLocataireService;
use App\Service\ChargeLocataireService;
use App\Service\ContratExpirationAiService;
use App\Service\DashboardLocataireService;
use App\Service\HistoryService;
use App\Service\LoyerLocataireService;
use App\Service\NotificationService;
use App\Service\PhoneVerificationService;
use App\Service\ProfileImageStorage;
use App\Service\SecurityNotificationService;
use App\Service\UserSecurityStateService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\SentimentAnalysisService;
use App\Service\WeatherService;
use App\Service\ReservationAlgorithmService;
use App\Service\QrCodeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[Route('/locataire')]
class LocataireController extends AbstractController
{
    private const MAX_PROFILE_IMAGE_BYTES = 5 * 1024 * 1024;
    private const EMAIL_CHANGE_SESSION_KEY = 'profile_email_change_pending';
    private const PHONE_VERIFICATION_SESSION_KEY = 'profile_phone_verification_pending';

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
    public function __construct(
        private SentimentAnalysisService $sentimentService,
        private \App\Service\BudgetService $budgetService
    ) {}

    /**
     * Parse une date de disponibilité "bientôt" (expert logic)
     */
    private function parseDateDispo(?string $date): ?\DateTime
    {
        if (!$date) return null;
        try {
            return new \DateTime($date);
        } catch (\Exception) {
            return null;
        }
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
            if ($newStatut === 'disponible' && in_array($oldStatut, ['bientot', 'occupe'])) {
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

    #[Route('/catalogue', name: 'tenant_catalogue')]
    public function catalogue(Request $request, Connection $connection): Response
    {
        $this->refreshStatuts($connection);

        $q       = trim((string) $request->query->get('q', ''));
        $type    = trim((string) $request->query->get('type', ''));
        $prixMax = trim((string) $request->query->get('prix_max', ''));
        $surfMin = trim((string) $request->query->get('surface_min', ''));
        $sort    = (string) $request->query->get('sort', 'recent');

        $allowedTypes = ['Appartement', 'Villa', 'Studio', 'Maison', 'Bureau'];
        $allowedSort  = ['recent', 'prix_asc', 'prix_desc'];
        if (!in_array($type, $allowedTypes, true)) { $type = ''; }
        if (!in_array($sort, $allowedSort, true))  { $sort = 'recent'; }

        $sql = 'SELECT a.id, a.titre, a.description, a.prix, a.statut, a.date_disponibilite AS dateDisponibilite,
                       a.photo_principale, a.adresse, a.slug,
                       a.type AS bien_type, a.surface AS superficie, a.chambres AS nombreChambres
                FROM annonce a
                WHERE a.statut != \'occupe\'';
        $params = [];

        if ($q !== '') {
            $sql .= ' AND (a.titre LIKE :q OR a.description LIKE :q OR a.adresse LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($type !== '') {
            $sql .= ' AND a.type = :type';
            $params['type'] = $type;
        }
        if (is_numeric($prixMax) && (float) $prixMax > 0) {
            $sql .= ' AND CAST(a.prix AS DECIMAL(10,2)) <= :pmax';
            $params['pmax'] = (float) $prixMax;
        }
        if (is_numeric($surfMin) && (float) $surfMin > 0) {
            $sql .= ' AND a.surface >= :smin';
            $params['smin'] = (float) $surfMin;
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
            $a['adresse_text'] = $a['adresse'] ?? '';
            $a['statut'] = strtolower((string) ($a['statut'] ?? ''));
            $a['jours_restants'] = null;
            $a['heures_restantes'] = null;
            $a['minutes_restantes'] = null;
            $a['total_seconds'] = null;
            if ($a['statut'] === 'bientot' && !empty($a['dateDisponibilite'])) {
                $dispo = $this->parseDateDispo((string) $a['dateDisponibilite']);
                if ($dispo) {
                    $diffSec = max(0, $dispo->getTimestamp() - (new \DateTime())->getTimestamp());
                    $a['jours_restants'] = intdiv($diffSec, 86400);
                    $a['heures_restantes'] = intdiv($diffSec % 86400, 3600);
                    $a['minutes_restantes'] = intdiv($diffSec % 3600, 60);
                    $a['total_seconds'] = $diffSec;
                }
            }
        }
        unset($a);

        $wishlistIds = ($this->getUser() instanceof Utilisateur)
            ? $this->getWishlistIds($connection, (int) $this->getUser()->getId())
            : [];

        return $this->render('locataire/catalogue.html.twig', [
            'annonces' => $annonces,
            'filters'  => [
                'q' => $q, 'type' => $type, 'prix_max' => $prixMax,
                'surface_min' => $surfMin, 'sort' => $sort,
            ],
            'allowed_types' => $allowedTypes,
            'wishlist_ids'  => $wishlistIds,
        ]);
    }

    private function ensureWishlistTable(Connection $connection): void
    {
        try {
            $connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS wishlist (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    utilisateurId INT NOT NULL,
                    annonceId INT NOT NULL,
                    dateAjout DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_annonce (utilisateurId, annonceId),
                    INDEX idx_user (utilisateurId)
                )'
            );
        } catch (\Throwable) {}
    }

    /** @return array<int, int> */
    private function getWishlistIds(Connection $connection, int $userId): array
    {
        $this->ensureWishlistTable($connection);
        try {
            $rows = $connection->fetchAllAssociative(
                'SELECT annonceId FROM wishlist WHERE utilisateurId = :uid',
                ['uid' => $userId]
            );
            return array_map(fn($r) => (int) $r['annonceId'], $rows);
        } catch (\Throwable) {
            return [];
        }
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

    #[Route('/wishlist', name: 'tenant_wishlist', methods: ['GET'])]
    public function wishlist(Connection $connection): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        $this->ensureWishlistTable($connection);
        $this->refreshStatuts($connection);

        $annonces = [];
        try {
            $annonces = $connection->fetchAllAssociative(
                'SELECT a.id, a.titre, a.description, a.prix, a.statut, a.date_disponibilite AS dateDisponibilite,
                        a.photo_principale, a.adresse,
                        a.type AS bien_type, a.surface AS superficie, a.chambres AS nombreChambres,
                        w.dateAjout
                 FROM wishlist w
                 INNER JOIN annonce a ON a.id = w.annonceId
                 WHERE w.utilisateurId = :uid
                 ORDER BY w.dateAjout DESC',
                ['uid' => (int) $user->getId()]
            );
        } catch (\Throwable) {}

        $wishlistIds = [];
        foreach ($annonces as &$a) {
            $photoPrincipale = (string) ($a['photo_principale'] ?? '');
            $paths = $photoPrincipale !== '' ? array_filter(array_map('trim', explode(',', $photoPrincipale))) : [];
            $a['images'] = $paths;
            $a['cover'] = $paths[0] ?? null;
            $a['adresse_text'] = $a['adresse'] ?? '';
            $a['statut'] = strtolower((string) ($a['statut'] ?? ''));
            $a['jours_restants'] = null;
            $a['heures_restantes'] = null;
            $a['minutes_restantes'] = null;
            $a['total_seconds'] = null;
            if ($a['statut'] === 'bientot' && !empty($a['dateDisponibilite'])) {
                $dispo = $this->parseDateDispo((string) $a['dateDisponibilite']);
                if ($dispo) {
                    $diffSec = max(0, $dispo->getTimestamp() - (new \DateTime())->getTimestamp());
                    $a['jours_restants'] = intdiv($diffSec, 86400);
                    $a['heures_restantes'] = intdiv($diffSec % 86400, 3600);
                    $a['minutes_restantes'] = intdiv($diffSec % 3600, 60);
                    $a['total_seconds'] = $diffSec;
                }
            }
            $wishlistIds[] = (int) $a['id'];
        }
        unset($a);

        return $this->render('locataire/wishlist.html.twig', [
            'annonces' => $annonces,
            'wishlist_ids' => $wishlistIds,
        ]);
    }

    #[Route('/wishlist/toggle/{id}', name: 'tenant_wishlist_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function wishlistToggle(int $id, Request $request, Connection $connection): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'auth' => false], 401);
            }
            return $this->redirectToRoute('app_login');
        }
        $this->ensureWishlistTable($connection);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('tenant_wishlist_toggle', $token)) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'csrf' => false], 400);
            }
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('tenant_catalogue');
        }

        $uid = (int) $user->getId();
        $exists = false;
        try {
            $exists = (bool) $connection->fetchOne(
                'SELECT 1 FROM wishlist WHERE utilisateurId = :uid AND annonceId = :aid',
                ['uid' => $uid, 'aid' => $id]
            );
        } catch (\Throwable) {}

        $added = false;
        try {
            if ($exists) {
                $connection->executeStatement(
                    'DELETE FROM wishlist WHERE utilisateurId = :uid AND annonceId = :aid',
                    ['uid' => $uid, 'aid' => $id]
                );
                $added = false;
            } else {
                $connection->executeStatement(
                    'INSERT INTO wishlist (utilisateurId, annonceId) VALUES (:uid, :aid)',
                    ['uid' => $uid, 'aid' => $id]
                );
                $added = true;
            }
        } catch (\Throwable $e) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
            }
            $this->addFlash('error', 'Action wishlist impossible.');
            return $this->redirectToRoute('tenant_catalogue');
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true, 'added' => $added]);
        }
        $this->addFlash('success', $added ? 'Ajouté à votre wishlist.' : 'Retiré de votre wishlist.');
        return $this->redirectToRoute('tenant_catalogue');
    }



    #[Route('/annonce/slug/{slug}', name: 'tenant_annonce_detail_slug', requirements: ['slug' => '[a-z0-9][a-z0-9\-]*'])]
    public function annonceDetailBySlug(string $slug, Connection $connection, NotificationService $notificationService): Response
    {
        try {
            $id = $connection->fetchOne('SELECT id FROM annonce WHERE slug = :s LIMIT 1', ['s' => $slug]);
        } catch (\Throwable) {
            $id = false;
        }
        if (!$id) {
            throw $this->createNotFoundException('Annonce introuvable pour ce slug.');
        }
        return $this->annonceDetail((int) $id, $connection, $notificationService);
    }

    #[Route('/annonce/{id}', name: 'tenant_annonce_detail', requirements: ['id' => '\d+'])]
    public function annonceDetail(int $id, Connection $connection, NotificationService $notificationService): Response
    {
        $this->refreshStatuts($connection);

        $viewerId = $this->getUser() instanceof Utilisateur ? (int) $this->getUser()->getId() : null;
        $notificationService->trackAnnonceView($id, $viewerId);

        $annonce = null;
        try {
            $annonce = $connection->fetchAssociative(
                'SELECT a.*, a.date_disponibilite AS dateDisponibilite,
                        a.type AS bien_type, a.surface AS superficie, a.chambres AS nombreChambres,
                        u.nom AS proprietaire_nom, u.email AS proprietaire_email, u.telephone AS proprietaire_telephone
                 FROM annonce a
                 LEFT JOIN utilisateur u ON u.id = a.proprietaireId
                 WHERE a.id = :id',
                ['id' => $id]
            );
        } catch (\Throwable) {
            $annonce = null;
        }

        if (!$annonce) {
            $this->addFlash('error', "Annonce introuvable.");
            return $this->redirectToRoute('tenant_catalogue');
        }

        $photoPrincipale = (string) ($annonce['photo_principale'] ?? '');
        $annonce['images'] = $photoPrincipale !== '' ? array_filter(array_map('trim', explode(',', $photoPrincipale))) : [];
        $annonce['adresse_text'] = $annonce['adresse'] ?? '';
        $annonce['statut'] = strtolower((string) ($annonce['statut'] ?? ''));

        if ($annonce['statut'] === 'occupe') {
            $this->addFlash('error', "Cette annonce n'est pas disponible pour le moment.");
            return $this->redirectToRoute('tenant_catalogue');
        }

        $joursRestants = null;
        $heuresRestantes = null;
        $minutesRestantes = null;
        $totalSeconds = null;
        if ($annonce['statut'] === 'bientot' && !empty($annonce['dateDisponibilite'])) {
            $dispo = $this->parseDateDispo((string) $annonce['dateDisponibilite']);
            if ($dispo) {
                $diffSec = max(0, $dispo->getTimestamp() - (new \DateTime())->getTimestamp());
                $joursRestants = intdiv($diffSec, 86400);
                $heuresRestantes = intdiv($diffSec % 86400, 3600);
                $minutesRestantes = intdiv($diffSec % 3600, 60);
                $totalSeconds = $diffSec;
            }
        }
        $annonce['jours_restants'] = $joursRestants;
        $annonce['heures_restantes'] = $heuresRestantes;
        $annonce['minutes_restantes'] = $minutesRestantes;
        $annonce['total_seconds'] = $totalSeconds;

        $isFav = false;
        $userId = null;
        if ($this->getUser() instanceof Utilisateur) {
            $userId = (int) $this->getUser()->getId();
            $isFav = in_array($id, $this->getWishlistIds($connection, $userId), true);
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
        $allComments = [];
        if (count($avisList) > 0) {
            $avgNote = round(array_sum(array_column($avisList, 'note')) / count($avisList), 1);
            foreach ($avisList as &$av) {
                $score = $this->sentimentService->analyzeSentiment($av['commentaire']);
                $av['sentiment'] = $this->sentimentService->getSentimentBadge($score);
                $allComments[] = $av['commentaire'];
            }
            unset($av);
        }

        // Smart Topics Extraction
        $topics = $this->sentimentService->extractTopics($allComments);

        $userAvis = null;
        if ($userId) {
            foreach ($avisList as $av) {
                if ((int) $av['user_id'] === $userId) {
                    $userAvis = $av;
                    break;
                }
            }
        }

        // Expert Logic : Market Comparison
        $marketAnalysis = $this->budgetService->getMarketComparison(
            (string)($annonce['ville'] ?? ''),
            (float)($annonce['prix'] ?? 0)
        );

        // Security : Captcha Challenge
        $captcha = $this->budgetService->generateCaptchaChallenge();

        return $this->render('locataire/annonce_detail.html.twig', [
            'id' => $id,
            'a'  => $annonce,
            'is_fav' => $isFav,
            'avis_list' => $avisList,
            'avis_avg' => $avgNote,
            'avis_count' => count($avisList),
            'avis_topics' => $topics,
            'user_avis' => $userAvis,
            'market_analysis' => $marketAnalysis,
            'captcha' => $captcha
        ]);
    }

    #[Route('/annonce/{id}/avis', name: 'tenant_annonce_avis', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submitAvis(int $id, Request $request, Connection $connection, \App\Service\ModerationService $moderationService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            $this->addFlash('auth_error', 'Vous devez etre connecte pour laisser un avis.');
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        $csrf = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('avis_annonce_' . $id, $csrf)) {
            $this->addFlash('auth_error', 'Session invalide. Veuillez reessayer.');
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        $note = (int) $request->request->get('note', 0);
        $commentaire = trim((string) $request->request->get('commentaire', ''));

        if ($note < 1 || $note > 5) {
            $this->addFlash('auth_error', 'La note doit etre entre 1 et 5 etoiles.');
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        if ($commentaire === '') {
            $this->addFlash('auth_error', 'Veuillez ecrire un commentaire.');
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        if (mb_strlen($commentaire) < 5) {
            $this->addFlash('auth_error', 'Le commentaire doit contenir au moins 5 caracteres.');
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        if (mb_strlen($commentaire) > 1000) {
            $this->addFlash('auth_error', 'Le commentaire ne doit pas depasser 1000 caracteres.');
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        if ($commentaire !== strip_tags($commentaire)) {
            $this->addFlash('auth_error', 'Le commentaire ne peut pas contenir de balises HTML.');
            return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
        }

        $annonce = $connection->fetchAssociative('SELECT id FROM annonce WHERE id = :id', ['id' => $id]);
        if (!$annonce) {
            $this->addFlash('auth_error', 'Annonce introuvable.');
            return $this->redirectToRoute('tenant_catalogue');
        }

        $this->ensureAvisTableExists($connection);
        $userId = (int) $user->getId();

        $existing = $connection->fetchAssociative(
            'SELECT id FROM avis WHERE annonce_id = :aid AND user_id = :uid',
            ['aid' => $id, 'uid' => $userId]
        );

        // Profanity moderation — API Ninjas. If flagged, the avis is saved but masked.
        $isMasked = $moderationService->containsProfanity($commentaire) ? 1 : 0;

        if ($existing) {
            $connection->executeStatement(
                'UPDATE avis SET note = :note, commentaire = :comm, is_masked = :masked, date_creation = NOW() WHERE annonce_id = :aid AND user_id = :uid',
                ['note' => $note, 'comm' => $commentaire, 'masked' => $isMasked, 'aid' => $id, 'uid' => $userId]
            );
            $this->addFlash('auth_success', 'Votre avis a ete mis a jour.');
        } else {
            $connection->insert('avis', [
                'annonce_id' => $id,
                'user_id' => $userId,
                'note' => $note,
                'commentaire' => $commentaire,
                'is_masked' => $isMasked,
            ]);
            $this->addFlash('auth_success', 'Merci pour votre avis !');
        }

        return $this->redirectToRoute('tenant_annonce_detail', ['id' => $id]);
    }

    // NOTE: Les méthodes visites() et reservations() sont maintenant dans
    // VisiteController et ReservationController dédiés.
    // Routes: /locataire/visites et /locataire/reservations

    #[Route('/contrats', name: 'tenant_contrats')]
    public function contrats(
        Request $request,
        ContratRepository $contratRepo,
        ContratExpirationAiService $contratExpirationAi,
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $statutParam = $request->query->get('statut');
        $statutFilter = is_string($statutParam) && $statutParam !== '' ? $statutParam : null;
        $search = (string) $request->query->get('search', '');

        $contrats = $contratRepo->findByLocataire(
            (int) $user->getId(),
            $statutFilter,
            $search !== '' ? $search : null,
        );

        $contratsExpiration = $contratExpirationAi->collectExpiringSoon($contrats);
        $messageExpirationIa = null;
        if ($contratsExpiration !== []) {
            $messageExpirationIa = $contratExpirationAi->generateReminderText($user, $contratsExpiration);
        }

        return $this->render('locataire/contrats.html.twig', [
            'contrats' => $contrats,
            'user' => $user,
            'contrats_expiration' => $contratsExpiration,
            'message_expiration_ia' => $messageExpirationIa,
            'message_expiration_fallback' => $contratsExpiration !== []
                ? $contratExpirationAi->buildFallbackMessage($user, $contratsExpiration)
                : '',
        ]);
    }

    #[Route('/finances', name: 'tenant_finances')]
    public function finances(
        DashboardLocataireService $dashboardService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();
        $nomLocataire = (string) $user->getNom();

        // Utiliser le service Dashboard pour construire toutes les données
        $dashboard = $dashboardService->buildDashboard($locataireId, $nomLocataire);

        return $this->render('locataire/finances/dashboard.html.twig', [
            'dashboard' => $dashboard,
        ]);
    }

    #[Route('/finances/loyers', name: 'tenant_finances_loyers')]
    public function financesLoyers(
        Request $request,
        LoyerLocataireService $loyerService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();

        // Récupérer tous les logements du locataire
        $logements = $loyerService->getLogementsByLocataire($locataireId);

        // Sélectionner le logement actif (par défaut le premier, ou celui passé en paramètre)
        $selectedContratId = $request->query->getInt('logement');
        if ($selectedContratId === 0 && !empty($logements)) {
            $selectedContratId = (int) $logements[0]['contrat_id'];
        }

        // Récupérer les loyers pour le contrat sélectionné
        $loyers = $selectedContratId > 0
            ? $loyerService->getLoyersByContrat($selectedContratId)
            : [];

        // Groupage par statut (comme dans JavaFX)
        $enRetard = array_filter($loyers, fn($l) => $l['statut'] === 'EN_RETARD');
        $enAttente = array_filter($loyers, fn($l) => $l['statut'] === 'EN_ATTENTE');
        $payes = array_slice(
            array_filter($loyers, fn($l) => $l['statut'] === 'PAYE'),
            0,
            6
        );

        // Stats pour le contrat sélectionné
        $stats = $selectedContratId > 0
            ? $loyerService->getStatsForContrat($selectedContratId)
            : [
                'total_paiements' => 0,
                'paiements_effectues' => 0,
                'paiements_en_retard' => 0,
                'paiements_a_venir' => 0,
                'total_penalites' => 0,
                'montant_paye_total' => 0,
                'montant_restant' => 0,
            ];

        // Logement sélectionné pour affichage
        $selectedLogement = null;
        foreach ($logements as $log) {
            if ((int) $log['contrat_id'] === $selectedContratId) {
                $selectedLogement = $log;
                break;
            }
        }

        return $this->render('locataire/finances/loyers.html.twig', [
            'logements' => $logements,
            'selectedContratId' => $selectedContratId,
            'selectedLogement' => $selectedLogement,
            'enRetard' => $enRetard,
            'enAttente' => $enAttente,
            'payes' => $payes,
            'stats' => $stats,
            'alertCount' => count($enRetard),
        ]);
    }

    #[Route('/finances/loyers/{id}/recu', name: 'tenant_finances_loyers_recu')]
    public function genererRecuLoyer(
        int $id,
        LoyerLocataireService $loyerService,
        \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface $params
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();
        $loyer = $loyerService->getById($id, $locataireId);

        if (!$loyer || $loyer['statut'] !== 'PAYE') {
            $this->addFlash('error', 'Reçu indisponible ou loyer non payé.');
            return $this->redirectToRoute('tenant_finances_loyers');
        }

        $locataireInfo = $loyerService->getLocataireInfo($locataireId);
        $propInfo = $loyerService->getProprietaireByContrat((int)$loyer['contrat_id']);
        
        $logements = $loyerService->getLogementsByLocataire($locataireId);
        $logement = null;
        foreach ($logements as $log) {
            if ((int)$log['contrat_id'] === (int)$loyer['contrat_id']) {
                $logement = $log;
                break;
            }
        }

        // Configuration Dompdf
        $pdfOptions = new \Dompdf\Options();
        $pdfOptions->set('defaultFont', 'Arial');
        $pdfOptions->set('isRemoteEnabled', true);
        
        $dompdf = new \Dompdf\Dompdf($pdfOptions);
        
        // Base64 Logo for MPDF/Dompdf compatibility (bypass path issues)
        $imagePath = (is_string($dir = $params->get('kernel.project_dir')) ? $dir : '') . '/public/images/logo.png';
        $base64Logo = '';
        // DOMPDF needs GD extension to parse PNGs. If GD is missing, fallback to text logo.
        if (extension_loaded('gd') && file_exists($imagePath)) {
            $type = pathinfo($imagePath, PATHINFO_EXTENSION);
            $data = file_get_contents($imagePath);
            $base64Logo = 'data:image/' . $type . ';base64,' . base64_encode((string)$data);
        }

        $html = $this->renderView('locataire/finances/pdf/recu_loyer_pdf.html.twig', [
            'loyer' => $loyer,
            'locataire' => $locataireInfo,
            'proprietaire' => $propInfo,
            'logement' => $logement,
            'logo_base64' => $base64Logo,
            'date_generation' => new \DateTime()
        ]);
        
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'Reçu_Loyer_' . ($loyer['periode'] ?? 'Periode') . '.pdf';

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    #[Route('/finances/charges', name: 'tenant_finances_charges')]
    public function financesCharges(
        Request $request,
        ChargeLocataireService $chargeService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();

        // Récupérer les logements pour le sélecteur
        $logements = $chargeService->getLogementsByLocataire($locataireId);

        // Logement sélectionné (null = vue globale)
        $selectedContratId = $request->query->get('logement');
        $contractIds = [];

        if ($selectedContratId === null) {
            foreach ($logements as $log) {
                $contractIds[] = (int) $log['contrat_id'];
            }
        } else {
            $contractIds = [(int) $selectedContratId];
        }

        // Récupération des charges
        $charges = $chargeService->getChargesByContrats($contractIds);

        // Groupage par statut
        $unpaid = array_filter($charges, fn($c) => $c['statut_paiement'] !== 'PAYE');
        $paidAll = array_filter($charges, fn($c) => $c['statut_paiement'] === 'PAYE');

        // Filtres pour historique payé
        $typeFilter = $request->query->get('type', 'TOUS');
        $periodeFilter = $request->query->get('periode', '');
        $minRaw = $request->query->get('min');
        $maxRaw = $request->query->get('max');
        $min = ($minRaw !== null && $minRaw !== '') ? (float)$minRaw : null;
        $max = ($maxRaw !== null && $maxRaw !== '') ? (float)$maxRaw : null;
        $limit = (int) $request->query->get('limit', 5);
        $page = (int) $request->query->get('page', 1);

        // Application des filtres sur charges payées
        $paidFiltered = array_filter($paidAll, function ($c) use ($typeFilter, $periodeFilter, $min, $max) {
            if ($typeFilter !== 'TOUS' && ($c['type_charge'] ?? 'AUTRE') !== $typeFilter) {
                return false;
            }
            if ($periodeFilter && !str_contains((string)($c['periode'] ?? ''), (string)$periodeFilter)) {
                return false;
            }
            if ($min !== null && ($c['montant'] ?? 0) < $min) {
                return false;
            }
            if ($max !== null && ($c['montant'] ?? 0) > $max) {
                return false;
            }
            return true;
        });
        $paidFiltered = array_values($paidFiltered);

        // Pagination Pagerfanta pour historique payé
        $adapter = new \Pagerfanta\Adapter\ArrayAdapter($paidFiltered);
        $pagerfanta = new \Pagerfanta\Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($limit);
        $pagerfanta->setCurrentPage($page);

        // Stats
        $stats = $chargeService->getStatsForContrats($contractIds);

        // Données graphique
        $chartData = $chargeService->getEvolutionData($contractIds);

        // --- MOTEUR D'ALERTES (UI) ---
        $anomalyAlerts = $chargeService->getAnomalyAlertsForContrats($contractIds);

        return $this->render('locataire/finances/charges.html.twig', [
            'logements' => $logements,
            'selectedContratId' => $selectedContratId,
            'charges' => $charges,
            'unpaid' => $unpaid,
            'paid' => $pagerfanta->getCurrentPageResults(),
            'paidAll' => $paidAll,
            'stats' => $stats,
            'chartData' => $chartData,
            'anomaly_alerts' => $anomalyAlerts,
            'validTypes' => ChargeLocataireService::VALID_TYPES,
            'pager' => $pagerfanta,
            'typeFilter' => $typeFilter,
            'periodeFilter' => $periodeFilter,
            'min' => $min,
            'max' => $max,
            'limit' => $limit,
            'currentPage' => $page,
            'nbFiltered' => count($paidFiltered),
        ]);
    }

    #[Route('/finances/charges/export', name: 'tenant_finances_charges_export')]
    public function financesChargesExport(
        Request $request,
        ChargeLocataireService $chargeService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();
        $selectedContratId = $request->query->get('logement');
        $logements = $chargeService->getLogementsByLocataire($locataireId);
        
        $contractIds = [];
        if ($selectedContratId === null) {
            foreach ($logements as $log) {
                $contractIds[] = (int) $log['contrat_id'];
            }
        } else {
            $contractIds = [(int) $selectedContratId];
        }

        $charges = $chargeService->getChargesByContrats($contractIds);
        $paidAll = array_filter($charges, fn($c) => $c['statut_paiement'] === 'PAYE');

        // Appliquer les mêmes filtres
        $typeFilter = $request->query->get('type', 'TOUS');
        $periodeFilter = $request->query->get('periode', '');
        $minRaw = $request->query->get('min');
        $maxRaw = $request->query->get('max');
        $min = ($minRaw !== null && $minRaw !== '') ? (float)$minRaw : null;
        $max = ($maxRaw !== null && $maxRaw !== '') ? (float)$maxRaw : null;

        $filtered = array_filter($paidAll, function ($c) use ($typeFilter, $periodeFilter, $min, $max) {
            if ($typeFilter !== 'TOUS' && ($c['type_charge'] ?? 'AUTRE') !== $typeFilter) return false;
            if ($periodeFilter && !str_contains((string)($c['periode'] ?? ''), (string)$periodeFilter)) return false;
            if ($min !== null && ($c['montant'] ?? 0) < $min) return false;
            if ($max !== null && ($c['montant'] ?? 0) > $max) return false;
            return true;
        });

        // Préparer données export
        $data = [];
        foreach ($filtered as $c) {
            $data[] = [
                $c['type_charge'] ?? 'AUTRE',
                $c['periode'] ?? '-',
                $c['montant'] ?? 0,
                $c['derniere_date_paiement'] ? date('d/m/Y', strtotime($c['derniere_date_paiement'])) : '-',
                $c['logement_titre'] ?? '-',
            ];
        }

        $source = new \Sonata\Exporter\Source\ArraySourceIterator([
            ['Type', 'Periode', 'Montant (TND)', 'Date Paiement', 'Logement'],
            ...$data
        ]);

        $writer = new \Sonata\Exporter\Writer\XlsWriter('php://output');

        $response = new Response();
        $response->headers->set('Content-Type', 'application/vnd.ms-excel');
        $response->headers->set('Content-Disposition', 'attachment; filename="charges_paiements_'.date('Y-m-d').'.xls"');
        $response->headers->set('Cache-Control', 'max-age=0');

        ob_start();
        $writer->open();
        foreach ($source as $row) {
            $writer->write($row);
        }
        $writer->close();
        $content = ob_get_clean();

        $response->setContent((string)$content);
        return $response;
    }

    #[Route('/finances/charges/{id}/payer', name: 'tenant_finances_charge_payer', methods: ['POST'])]
    public function payerCharge(
        int $id,
        Request $request,
        ChargeLocataireService $chargeService,
        \App\Service\Validation\FinanceValidationService $validationService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();

        // Vérifier CSRF
        $token = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('payer_charge_' . $id, $token)) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('tenant_finances_charges');
        }

        $charge = $chargeService->getChargeById($id, $locataireId);
        if (!$charge) {
            $this->addFlash('error', 'Charge introuvable ou accès non autorisé.');
            return $this->redirectToRoute('tenant_finances_charges');
        }

        // Validation du fichier uploadé (preuve de paiement)
        $uploadedFile = $request->files->get('justificatif');
        if ($uploadedFile) {
            $fileValidation = $validationService->validateFile($uploadedFile, false);
            if (!$fileValidation['valid']) {
                $this->addFlash('error', 'Fichier invalide: ' . $validationService->formatErrors($fileValidation['errors']));
                return $this->redirectToRoute('tenant_finances_charges');
            }
        }

        // Validation des notes
        $notes = (string) $request->request->get('notes', '');
        if ($notes) {
            $textValidation = $validationService->validateText($notes, false, 500);
            if (!$textValidation['valid']) {
                $this->addFlash('error', 'Notes invalides: ' . $validationService->formatErrors($textValidation['errors']));
                return $this->redirectToRoute('tenant_finances_charges');
            }
        }

        $montant = (float) ($charge['montant_a_payer'] ?? $charge['montant']);
        $success = $chargeService->marquerCommePaye(
            $id,
            $locataireId,
            $montant,
            'MANUEL',
            'Paiement manuel par locataire',
            'Paiement effectué depuis l\'espace locataire'
        );

        if ($success) {
            // Envoyer email au propriétaire
            $locataireInfo = [
                'nom' => $user->getNom(),
                'email' => $user->getEmail(),
            ];
            $chargeService->sendPaymentConfirmationEmail($charge, $locataireInfo, $montant, 'MANUEL');
            $this->addFlash('success', 'Paiement enregistré avec succès !');
        } else {
            $this->addFlash('error', 'Erreur lors de l\'enregistrement du paiement.');
        }

        return $this->redirectToRoute('tenant_finances_charges');
    }

    #[Route('/finances/charges/{id}/modifier', name: 'tenant_finances_charge_modifier', methods: ['POST'])]
    public function modifierCharge(
        int $id,
        Request $request,
        ChargeLocataireService $chargeService,
        \App\Service\Validation\FinanceValidationService $validationService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();

        // Vérifier CSRF
        $token = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('modifier_charge_' . $id, $token)) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('tenant_finances_charges');
        }

        $charge = $chargeService->getChargeById($id, $locataireId);
        if (!$charge) {
            $this->addFlash('error', 'Charge introuvable ou accès non autorisé.');
            return $this->redirectToRoute('tenant_finances_charges');
        }

        // Validation des données avec FinanceValidationService
        $data = [
            'type_charge' => $request->request->get('type_charge'),
            'montant' => $request->request->get('montant'),
            'periode' => $request->request->get('periode'),
            'description' => $request->request->get('description'),
        ];
        
        $validation = $validationService->validateChargeCreate($data);
        if (!$validation['valid']) {
            $this->addFlash('error', 'Données invalides: ' . $validationService->formatErrors($validation['errors']));
            return $this->redirectToRoute('tenant_finances_charges');
        }
        
        // Conversion du montant en float après validation
        $data['montant'] = (float) $data['montant'];

        $success = $chargeService->modifierCharge($id, $locataireId, $data);

        if ($success) {
            $this->addFlash('success', 'Charge modifiée avec succès !');
        } else {
            $this->addFlash('error', 'Erreur lors de la modification de la charge.');
        }

        return $this->redirectToRoute('tenant_finances_charges');
    }

    #[Route('/finances/charges/{id}/supprimer', name: 'tenant_finances_charge_supprimer', methods: ['POST'])]
    public function supprimerCharge(
        int $id,
        Request $request,
        ChargeLocataireService $chargeService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();

        // Vérifier CSRF
        $token = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('supprimer_charge_' . $id, $token)) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('tenant_finances_charges');
        }

        $success = $chargeService->supprimerCharge($id, $locataireId);

        if ($success) {
            $this->addFlash('success', 'Charge supprimée avec succès !');
        } else {
            $this->addFlash('error', 'Erreur lors de la suppression de la charge.');
        }

        return $this->redirectToRoute('tenant_finances_charges');
    }

    #[Route('/finances/caution', name: 'tenant_finances_caution')]
    public function financesCaution(
        Request $request,
        CautionLocataireService $cautionService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();

        // Récupérer les logements pour le sélecteur
        $logements = $cautionService->getLogementsByLocataire($locataireId);

        // Logement sélectionné (null = vue globale)
        $selectedContratId = $request->query->get('logement');

        // Déterminer les données à afficher
        if ($selectedContratId === null) {
            // Vue globale: agréger toutes les cautions actives
            $allCautions = $cautionService->getAllByLocataire($locataireId);
            
            // Calculer les totaux
            $totals = [
                'initial' => 0,
                'retention' => 0,
                'rembourse' => 0,
                'disponible' => 0,
                'count' => count($allCautions),
            ];
            
            foreach ($allCautions as $c) {
                $totals['initial'] += (float)($c['montant_initial'] ?? 0);
                $totals['retention'] += (float)($c['montant_retention'] ?? 0);
                $totals['rembourse'] += (float)($c['montant_rembourse'] ?? 0);
                $totals['disponible'] += (float)($c['montant_disponible'] ?? 0);
            }
            
            $caution = null;
            $photos = [];
            $statut = 'TOTAL_ACTIF (' . $totals['count'] . ')';
            $isGlobal = true;
        } else {
            // Vue logement spécifique
            $caution = $cautionService->getByContrat((int) $selectedContratId);
            $totals = null;
            $isGlobal = false;
            
            if ($caution) {
                $statut = $caution['statut'] ?? 'INCONNU';
                $photos = $cautionService->getPhotosByCaution((int) $caution['id']);
            } else {
                $statut = 'AUCUNE_CAUTION';
                $photos = [];
            }
        }

        return $this->render('locataire/finances/caution.html.twig', [
            'logements' => $logements,
            'selectedContratId' => $selectedContratId,
            'caution' => $caution,
            'totals' => $totals,
            'statut' => $statut,
            'photos' => $photos,
            'isGlobal' => $isGlobal,
        ]);
    }

    #[Route('/finances/caution/{id}/rembourser', name: 'tenant_finances_caution_rembourser', methods: ['POST'])]
    public function rembourserCaution(
        int $id,
        Request $request,
        CautionLocataireService $cautionService,
        \App\Service\Validation\FinanceValidationService $validationService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();

        // Vérifier CSRF
        $token = (string) $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('rembourser_caution_' . $id, $token)) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('tenant_finances_caution');
        }

        // Vérifier que la caution appartient au locataire
        $caution = $cautionService->getByIdAndLocataire($id, $locataireId);
        if (!$caution) {
            $this->addFlash('error', 'Caution introuvable ou accès non autorisé.');
            return $this->redirectToRoute('tenant_finances_caution');
        }

        $montant = (float) $request->request->get('montant', 0);
        $disponible = (float) ($caution['montant_disponible'] ?? 0);

        // Validation du montant avec FinanceValidationService
        $validation = $validationService->validateRefundRequest($montant, $disponible);
        if (!$validation['valid']) {
            $this->addFlash('error', 'Montant invalide: ' . $validationService->formatErrors($validation['errors']));
            return $this->redirectToRoute('tenant_finances_caution');
        }

        $success = $cautionService->rembourser($id, $montant);

        if ($success) {
            $this->addFlash('success', 'Demande de remboursement de ' . number_format($montant, 3, ',', ' ') . ' TND enregistrée.');
        } else {
            $this->addFlash('error', 'Erreur lors de l\'enregistrement du remboursement.');
        }

        return $this->redirectToRoute('tenant_finances_caution');
    }

    #[Route('/finances/historique', name: 'tenant_finances_historique')]
    public function financesHistorique(
        Request $request,
        HistoryService $historyService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();

        // Logements pour le switcher
        $logements = $historyService->getLogements($locataireId);

        // Logement sélectionné
        $selectedContratId = $request->query->get('logement') ? (int) $request->query->get('logement') : null;

        // Récupération de l'historique unifié (loyers + charges)
        $history = $historyService->getUnifiedHistory($locataireId, $selectedContratId);

        // Filtres
        $typeFilter = $request->query->get('type', 'TOUS');
        $statutFilter = $request->query->get('statut', 'TOUS');
        $searchRef = strtolower((string)$request->query->get('ref', ''));
        $minRaw = $request->query->get('min');
        $maxRaw = $request->query->get('max');
        $min = ($minRaw !== null && $minRaw !== '') ? (float)$minRaw : null;
        $max = ($maxRaw !== null && $maxRaw !== '') ? (float)$maxRaw : null;
        $limit = (int) $request->query->get('limit', 5);
        $page = (int) $request->query->get('page', 1);

        // Application des filtres
        $filtered = array_filter($history, function ($record) use ($typeFilter, $statutFilter, $searchRef, $min, $max) {
            if ($typeFilter !== 'TOUS' && $record->type !== $typeFilter) {
                return false;
            }
            if ($statutFilter !== 'TOUS' && $record->statut !== $statutFilter) {
                return false;
            }
            if ($searchRef && stripos($record->reference ?? '', $searchRef) === false) {
                return false;
            }
            if ($min !== null && $record->montantTotal < $min) {
                return false;
            }
            if ($max !== null && $record->montantTotal > $max) {
                return false;
            }
            return true;
        });

        // Réindexer le tableau
        $filtered = array_values($filtered);

        // Calcul total filtré
        $totalFiltre = array_reduce($filtered, function ($sum, $record) {
            return $sum + $record->montantTotal;
        }, 0.0);

        // Pagination avec Pagerfanta
        $adapter = new \Pagerfanta\Adapter\ArrayAdapter($filtered);
        $pagerfanta = new \Pagerfanta\Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($limit);
        $pagerfanta->setCurrentPage($page);

        // Stats globales
        $stats = $historyService->getStats($locataireId);

        return $this->render('locataire/finances/historique.html.twig', [
            'logements' => $logements,
            'selectedContratId' => $selectedContratId,
            'history' => $pagerfanta->getCurrentPageResults(),
            'allHistory' => $history,
            'stats' => $stats,
            'totalFiltre' => $totalFiltre,
            'nbFiltered' => count($filtered),
            'typeFilter' => $typeFilter,
            'statutFilter' => $statutFilter,
            'searchRef' => $request->query->get('ref', ''),
            'min' => $min,
            'max' => $max,
            'limit' => $limit,
            'pager' => $pagerfanta,
            'currentPage' => $page,
        ]);
    }

    #[Route('/finances/historique/export', name: 'tenant_finances_historique_export')]
    public function financesHistoriqueExport(
        Request $request,
        HistoryService $historyService
    ): Response {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $locataireId = (int) $user->getId();
        $selectedContratId = $request->query->get('logement') ? (int) $request->query->get('logement') : null;
        $history = $historyService->getUnifiedHistory($locataireId, $selectedContratId);

        // Appliquer les mêmes filtres
        $typeFilter = $request->query->get('type', 'TOUS');
        $statutFilter = $request->query->get('statut', 'TOUS');
        $searchRef = strtolower((string)$request->query->get('ref', ''));
        $minRaw = $request->query->get('min');
        $maxRaw = $request->query->get('max');
        $min = ($minRaw !== null && $minRaw !== '') ? (float)$minRaw : null;
        $max = ($maxRaw !== null && $maxRaw !== '') ? (float)$maxRaw : null;

        $filtered = array_filter($history, function ($record) use ($typeFilter, $statutFilter, $searchRef, $min, $max) {
            if ($typeFilter !== 'TOUS' && $record->type !== $typeFilter) return false;
            if ($statutFilter !== 'TOUS' && $record->statut !== $statutFilter) return false;
            if ($searchRef && stripos($record->reference ?? '', $searchRef) === false) return false;
            if ($min !== null && $record->montantTotal < $min) return false;
            if ($max !== null && $record->montantTotal > $max) return false;
            return true;
        });

        // Préparer les données pour l'export
        $data = [];
        foreach ($filtered as $record) {
            $data[] = [
                $record->datePaiement ? $record->datePaiement->format('d/m/Y H:i') : '-',
                $record->type,
                $record->periode ? $record->periode->format('M Y') : '-',
                $record->montantTotal,
                $record->methode ?: 'N/A',
                $record->reference ?: '-',
                $record->statut,
            ];
        }

        // Créer le writer Excel avec Sonata Exporter
        $source = new \Sonata\Exporter\Source\ArraySourceIterator([
            ['Date', 'Type', 'Periode', 'Montant (TND)', 'Methode', 'Reference', 'Statut'],
            ...$data
        ]);

        $writer = new \Sonata\Exporter\Writer\XlsWriter('php://output');

        $response = new Response();
        $response->headers->set('Content-Type', 'application/vnd.ms-excel');
        $response->headers->set('Content-Disposition', 'attachment; filename="historique_paiements_'.date('Y-m-d').'.xls"');
        $response->headers->set('Cache-Control', 'max-age=0');

        ob_start();
        $writer->open();
        foreach ($source as $row) {
            $writer->write($row);
        }
        $writer->close();
        $content = ob_get_clean();

        $response->setContent((string)$content);
        return $response;
    }

    #[Route('/finances/historique/quittance/{id}', name: 'tenant_finances_historique_quittance')]
    public function downloadQuittance(int $id, HistoryService $historyService): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $fichier = $historyService->getQuittanceUrl($id);

        if (!$fichier) {
            $this->addFlash('error', 'Quittance non trouvée.');
            return $this->redirectToRoute('tenant_finances_historique');
        }

        // Vérifier si fichier physique ou URL
        if (str_starts_with($fichier, 'http://') || str_starts_with($fichier, 'https://')) {
            return $this->redirect($fichier);
        }

        // Chemin relatif au répertoire public
        $filePath = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/public/uploads/quittances/' . $fichier;

        if (!file_exists($filePath)) {
            $this->addFlash('error', 'Fichier quittance introuvable.');
            return $this->redirectToRoute('tenant_finances_historique');
        }

        return $this->file($filePath, 'quittance_' . $id . '.pdf');
    }

    // Route /reclamations gérée par ReclamationController::listeLocataire (name: tenant_reclamations)

    #[Route('/profil', name: 'tenant_profil', methods: ['GET', 'POST'])]
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
    ): Response
    {
        $authenticatedUser = $this->getUser();
        if (!$authenticatedUser instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $user = $utilisateurRepository->find((int) $authenticatedUser->getId());
        if (!$user instanceof Utilisateur) {
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
                    return $this->redirectToRoute('tenant_profil');
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

                return $this->redirectToRoute('tenant_profil');
            }

            if ($action === 'toggle_2fa') {
                $csrfToken = (string) $request->request->get('_csrf_token', '');
                if (!$this->isCsrfTokenValid('toggle_2fa_'.$user->getId(), $csrfToken)) {
                    $this->addFlash('auth_error', 'Session invalide. Veuillez réessayer.');
                    return $this->redirectToRoute('tenant_profil');
                }

                $enabled = !$user->isTwoFactorEnabled();
                $user->setTwoFactorEnabled($enabled);
                $entityManager->flush();

                $this->addFlash('auth_success', $enabled ? 'Vérification 2FA activée.' : 'Vérification 2FA désactivée.');
                return $this->redirectToRoute('tenant_profil');
            }

            if ($action === 'update_profile') {
                $firstName = trim((string) $request->request->get('prenom', ''));
                $lastName = trim((string) $request->request->get('nom', ''));
                $email = strtolower(trim((string) $request->request->get('email', '')));
                $telephone = trim((string) $request->request->get('telephone', ''));

                if ($firstName === '' || $lastName === '' || $email === '' || $telephone === '') {
                    $this->addFlash('auth_error', 'Veuillez remplir tous les champs du profil.');
                    return $this->redirectToRoute('tenant_profil');
                }

                if (!$validationService->isValidEmail($email)) {
                    $this->addFlash('auth_error', 'Veuillez saisir une adresse email valide.');
                    return $this->redirectToRoute('tenant_profil');
                }

                if (!$validationService->isValidTunisiaPhone($telephone)) {
                    $this->addFlash('auth_error', 'Veuillez saisir un numéro de téléphone tunisien valide (8 chiffres).');
                    return $this->redirectToRoute('tenant_profil');
                }

                $telephoneE164 = $validationService->toTunisiaE164($telephone);
                $userId = (int) $user->getId();
                $currentEmail = strtolower(trim((string) $user->getEmail()));

                if ($utilisateurRepository->emailExistsForAnotherUser($email, $userId)) {
                    $this->addFlash('auth_error', 'Cette adresse email est déjà utilisée.');
                    return $this->redirectToRoute('tenant_profil');
                }

                if ($utilisateurRepository->phoneExistsForAnotherUser($telephone, $telephoneE164, $userId)) {
                    $this->addFlash('auth_error', 'Ce numéro de téléphone est déjà utilisé.');
                    return $this->redirectToRoute('tenant_profil');
                }

                if ($email !== $currentEmail) {
                    if (!$authOtpService->createEmailChangeOtp($user, $email)) {
                        $this->addFlash('auth_error', 'Impossible d\'envoyer le code OTP sur votre ancien email. Veuillez réessayer.');
                        return $this->redirectToRoute('tenant_profil');
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
                    return $this->redirectToRoute('tenant_profil');
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

                return $this->redirectToRoute('tenant_profil');
            }

            if ($action === 'change_password') {
                $currentPassword = (string) $request->request->get('current_password', '');
                $newPassword = (string) $request->request->get('new_password', '');
                $confirmPassword = (string) $request->request->get('confirm_password', '');

                if (trim($currentPassword) === '' || trim($newPassword) === '' || trim($confirmPassword) === '') {
                    $this->addFlash('auth_error', 'Veuillez remplir tous les champs du mot de passe.');
                    return $this->redirectToRoute('tenant_profil');
                }

                if (!$this->isCurrentPasswordValid($user, $currentPassword)) {
                    $this->addFlash('auth_error', 'Mot de passe actuel incorrect.');
                    return $this->redirectToRoute('tenant_profil');
                }

                if (!$validationService->isValidPassword($newPassword)) {
                    $this->addFlash('auth_error', 'Le nouveau mot de passe doit contenir au moins 6 caracteres, une minuscule, une majuscule et un chiffre.');
                    return $this->redirectToRoute('tenant_profil');
                }

                if ($newPassword !== $confirmPassword) {
                    $this->addFlash('auth_error', 'La confirmation du nouveau mot de passe est incorrecte.');
                    return $this->redirectToRoute('tenant_profil');
                }

                if (hash_equals($currentPassword, $newPassword)) {
                    $this->addFlash('auth_error', 'Le nouveau mot de passe doit etre different de l actuel.');
                    return $this->redirectToRoute('tenant_profil');
                }

                $user->setMotDePasse($passwordHasher->hashPassword($user, $newPassword));
                $entityManager->flush();
                $userSecurityStateService->notePasswordChanged($user);
                $securityNotificationService->sendPasswordChanged($user);

                $this->addFlash('auth_success', 'Mot de passe mis à jour avec succès.');
                return $this->redirectToRoute('tenant_profil');
            }

            if ($action === 'delete_profile') {
                $csrfToken = (string) $request->request->get('_csrf_token', '');
                if (!$this->isCsrfTokenValid('delete_profile_'.$user->getId(), $csrfToken)) {
                    $this->addFlash('auth_error', 'Session invalide. Veuillez réessayer.');
                    return $this->redirectToRoute('tenant_profil');
                }

                try {
                    $profileImageStorage->deleteProfileImage($user);
                    $entityManager->remove($user);
                    $entityManager->flush();
                } catch (ForeignKeyConstraintViolationException) {
                    $this->addFlash('auth_error', 'Suppression impossible : ce compte est lié à des données existantes.');
                    return $this->redirectToRoute('tenant_profil');
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
                    return $this->redirectToRoute('tenant_profil');
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

        return $this->render('locataire/profil.html.twig', [
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
                'signature' => $user->getSignature(),
            ],
            'email_change_pending' => $pendingEmailChange,
            'phone_verification_pending' => $pendingPhoneVerification,
            'profile_image_directory' => $profileImageStorage->getConfiguredDirectory(),
        ]);
    }

    #[Route('/profil/telephone', name: 'tenant_profil_phone_verification', methods: ['POST'])]
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
            return $this->redirectToRoute('tenant_profil');
        }

        $action = (string) $request->request->get('action', 'start_phone_verification');

        if ($action === 'cancel_phone_verification') {
            $this->clearPendingPhoneVerification($request);
            $this->addFlash('auth_success', 'La vérification du numéro a été annulée.');
            return $this->redirectToRoute('tenant_profil');
        }

        $phoneE164 = trim((string) ($user->getTelephoneE164() ?? ''));
        $phoneDisplay = trim((string) ($user->getTelephone() ?? ''));
        if ($phoneE164 === '' || $phoneDisplay === '') {
            $this->addFlash('auth_error', 'Veuillez enregistrer un numéro de téléphone valide avant de le vérifier.');
            return $this->redirectToRoute('tenant_profil');
        }

        if ($action === 'verify_phone_code') {
            $code = (string) $request->request->get('phone_verification_code', '');
            try {
                $approved = $phoneVerificationService->checkVerification($phoneE164, $code);
            } catch (\RuntimeException $exception) {
                $this->addFlash('auth_error', $exception->getMessage());
                return $this->redirectToRoute('tenant_profil');
            }

            if (!$approved) {
                $this->addFlash('auth_error', 'Code SMS invalide ou expiré.');
                return $this->redirectToRoute('tenant_profil');
            }

            $user->setTelephoneVerified(true);
            $entityManager->flush();
            $this->clearPendingPhoneVerification($request);
            $this->addFlash('auth_success', 'Numéro de téléphone vérifié avec succès.');
            return $this->redirectToRoute('tenant_profil');
        }

        if ($action === 'resend_phone_verification') {
            if ($user->isTelephoneVerified()) {
                $this->addFlash('auth_success', 'Votre numéro de téléphone est déjà vérifié.');
                return $this->redirectToRoute('tenant_profil');
            }
            try {
                $phoneVerificationService->startVerification($phoneE164);
                $this->addFlash('auth_success', 'Un nouveau code de vérification a été envoyé par SMS.');
            } catch (\RuntimeException $exception) {
                $this->addFlash('auth_error', $exception->getMessage());
            }
            return $this->redirectToRoute('tenant_profil');
        }

        if ($user->isTelephoneVerified()) {
            $this->addFlash('auth_success', 'Votre numéro de téléphone est déjà vérifié.');
            return $this->redirectToRoute('tenant_profil');
        }

        try {
            $phoneVerificationService->startVerification($phoneE164);
        } catch (\RuntimeException $exception) {
            $this->addFlash('auth_error', $exception->getMessage());
            return $this->redirectToRoute('tenant_profil');
        }

        $request->getSession()->set(self::PHONE_VERIFICATION_SESSION_KEY, [
            'user_id' => (int) $user->getId(),
            'telephone' => $phoneDisplay,
            'telephone_e164' => $phoneE164,
        ]);

        $this->addFlash('auth_success', 'Un code de vérification a été envoyé par SMS.');
        return $this->redirectToRoute('tenant_profil');
    }

    #[Route('/profil/email', name: 'tenant_profil_email_change', methods: ['POST'])]
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
            return $this->redirectToRoute('tenant_profil');
        }

        $pending = $this->getPendingEmailChange($request, $user);
        if ($pending === null) {
            $this->addFlash('auth_error', 'Aucune demande de changement d\'email en attente.');
            return $this->redirectToRoute('tenant_profil');
        }

        $action = (string) $request->request->get('action', 'verify_email_change_otp');

        if ($action === 'cancel_email_change_otp') {
            $this->clearPendingEmailChange($request);
            $this->addFlash('auth_success', 'La demande de changement d\'email a été annulée.');
            return $this->redirectToRoute('tenant_profil');
        }

        if ($action === 'resend_email_change_otp') {
            $status = $authOtpService->getEmailChangeOtpStatus($user, (string) $pending['email']);
            if (!$status['can_resend'] || !$authOtpService->resendEmailChangeOtp($user, (string) $pending['email'])) {
                $message = ((int) ($status['seconds_remaining'] ?? 0) > 0 && (int) ($status['resend_count'] ?? 0) >= (int) ($status['max_resends'] ?? AuthOtpService::OTP_MAX_RESENDS))
                    ? 'Vous avez atteint la limite de 5 renvois pour ce code OTP.'
                    : 'Impossible de renvoyer le code pour le moment.';
                $this->addFlash('auth_error', $message);
                return $this->redirectToRoute('tenant_profil');
            }

            $updatedStatus = $authOtpService->getEmailChangeOtpStatus($user, (string) $pending['email']);
            $pending['expires_at'] = $updatedStatus['expires_at'];
            $pending['seconds_remaining'] = $updatedStatus['seconds_remaining'];
            $pending['resend_count'] = $updatedStatus['resend_count'];
            $pending['max_resends'] = $updatedStatus['max_resends'];
            $request->getSession()->set(self::EMAIL_CHANGE_SESSION_KEY, $pending);

            $this->addFlash('auth_success', 'Un nouveau code OTP a été envoyé à votre ancien email.');
            return $this->redirectToRoute('tenant_profil');
        }

        $otp = (string) $request->request->get('otp_code', '');
        if (!$authOtpService->verifyEmailChangeOtp($user, (string) $pending['email'], $otp)) {
            $this->addFlash('auth_error', 'Code OTP invalide ou expiré.');
            return $this->redirectToRoute('tenant_profil');
        }

        $pendingEmail = strtolower(trim((string) $pending['email']));
        $pendingPhone = trim((string) $pending['telephone']);
        $pendingPhoneE164 = trim((string) $pending['telephone_e164']);

        if ($utilisateurRepository->emailExistsForAnotherUser($pendingEmail, (int) $user->getId())) {
            $this->addFlash('auth_error', 'Cette adresse email est déjà utilisée. Veuillez recommencer.');
            return $this->redirectToRoute('tenant_profil');
        }

        if ($utilisateurRepository->phoneExistsForAnotherUser($pendingPhone, $pendingPhoneE164, (int) $user->getId())) {
            $this->addFlash('auth_error', 'Ce numéro de téléphone est déjà utilisé. Veuillez recommencer.');
            return $this->redirectToRoute('tenant_profil');
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

        return $this->redirectToRoute('tenant_profil');
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

    /**
     * Réinitialise les compteurs de tentatives de signature contrat (session),
     * pour que le profil puisse enregistrer une nouvelle référence sans rester bloqué à 3/3.
     */
    private function clearSignatureVerificationAttempts(Request $request): void
    {
        $session = $request->getSession();
        foreach (array_keys($session->all()) as $key) {
            if (str_starts_with($key, 'sig_attempts_')) {
                $session->remove($key);
            }
        }
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

    #[Route('/signature/save', name: 'tenant_signature_save', methods: ['POST'])]
    public function saveSignature(Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $data = (string) $request->request->get('signature_data', '');
        if ($data === '' || !str_starts_with($data, 'data:image/')) {
            return new JsonResponse(['error' => 'Données de signature invalides'], 400);
        }

        $user->setSignature($data);
        $em->flush();
        $this->clearSignatureVerificationAttempts($request);

        return new JsonResponse(['success' => true, 'message' => 'Signature enregistrée.']);
    }

    #[Route('/signature/delete', name: 'tenant_signature_delete', methods: ['POST'])]
    public function deleteSignature(Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return new JsonResponse(['error' => 'Non authentifié'], 401);
        }

        $user->setSignature(null);
        $em->flush();
        $this->clearSignatureVerificationAttempts($request);

        return new JsonResponse(['success' => true]);
    }

}
