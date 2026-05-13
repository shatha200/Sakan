<?php

namespace App\Controller;

use App\Entity\CautionRetenuePhoto;
use App\Entity\Utilisateur;
use App\Repository\CautionRepository;
use App\Service\CautionService;
use App\Service\CustomVisionService;
use App\Service\GeminiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/proprietaire/cautions')]
class CautionController extends AbstractController
{
    // ─────────────────────────────────────────────
    // Récupère l'ID du propriétaire connecté via Symfony Security
    // (même pattern que OwnerController / LocataireController)
    // ─────────────────────────────────────────────

    private function getProprietaireId(): ?int
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return null;
        }
        return (int) $user->getId();
    }

    private function requireAuth(): ?JsonResponse
    {
        if (!$this->getProprietaireId()) {
            return new JsonResponse(['error' => 'Non autorisé. Veuillez vous connecter.'], 401);
        }
        return null;
    }

    private function redirectIfNotAuth(): ?Response
    {
        if (!$this->getProprietaireId()) {
            return $this->redirectToRoute('app_login');
        }
        return null;
    }

    // ─────────────────────────────────────────────
    // VUES principales
    // ─────────────────────────────────────────────

    #[Route('/depots-actifs', name: 'caution_depots_actifs', methods: ['GET'])]
    public function depotsActifs(CautionRepository $rep): Response
    {
        if ($redirect = $this->redirectIfNotAuth()) return $redirect;
        $pid = $this->getProprietaireId();

        return $this->render('owner/cautions/depots_actifs.html.twig', [
            'cautions'    => $rep->findCautionsDetenues((int)$pid),
            'totalDetenu' => $rep->getTotalDetenu((int)$pid),
        ]);
    }

    #[Route('/a-regulariser', name: 'caution_a_regulariser', methods: ['GET'])]
    public function aRegulariser(CautionRepository $rep): Response
    {
        if ($redirect = $this->redirectIfNotAuth()) return $redirect;
        $pid = $this->getProprietaireId();

        $cautions = $rep->findCautionsARembourser((int)$pid);
        return $this->render('owner/cautions/a_regulariser.html.twig', [
            'cautions'         => $cautions,
            'totalARembourser' => $rep->getTotalARembourser((int)$pid),
            'count'            => count($cautions),
        ]);
    }

    #[Route('/archivage', name: 'caution_archivage', methods: ['GET'])]
    public function archivage(Request $request, CautionRepository $rep): Response
    {
        if ($redirect = $this->redirectIfNotAuth()) return $redirect;
        $pid = $this->getProprietaireId();

        // Récupérer toutes les cautions historiques
        $allCautions = $rep->findCautionsHistorique((int)$pid);
        
        // Filtres
        $search = $request->query->get('search', '');
        $statutFilter = $request->query->get('statut', '');
        $limit = (int) $request->query->get('limit', 10);
        $page = (int) $request->query->get('page', 1);
        
        // Application des filtres PHP
        $filtered = array_filter($allCautions, function($c) use ($search, $statutFilter) {
            $searchLower = strtolower((string)$search);
            $statut = strtoupper((string)($c['statut_caution'] ?? ''));
            
            // Filtre par statut
            if ($statutFilter && $statut !== strtoupper((string)$statutFilter)) {
                return false;
            }
            
            // Filtre par recherche (locataire, bien)
            if ($search) {
                $locataire = strtolower($c['nom_locataire'] ?? '');
                $bien = strtolower($c['nom_bien'] ?? '');
                if (!str_contains($locataire, $searchLower) && !str_contains($bien, $searchLower)) {
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
        
        // Stats sur les résultats filtrés
        $totalRembourse = 0;
        $totalRetenu = 0;
        foreach ($filtered as $c) {
            $totalRembourse += $c['montant_rembourse'] ?? 0;
            $totalRetenu += $c['montant_retention'] ?? 0;
        }

        return $this->render('owner/cautions/archivage.html.twig', [
            'cautions' => $pagerfanta->getCurrentPageResults(),
            'pager' => $pagerfanta,
            'search' => $search,
            'statutFilter' => $statutFilter,
            'limit' => $limit,
            'countTotal' => count($filtered),
            'totalRembourse' => $totalRembourse,
            'totalRetenu' => $totalRetenu,
        ]);
    }

    // ─────────────────────────────────────────────
    // API — Finaliser contrat
    // ─────────────────────────────────────────────

    #[Route('/api/finaliser/{id}', name: 'caution_finaliser', methods: ['POST'])]
    public function finaliser(int $id, CautionService $service): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        $success = $service->terminateContract($id);
        return new JsonResponse([
            'success' => $success,
            'message' => $success ? 'Contrat finalisé avec succès.' : 'Erreur : contrat introuvable.',
        ]);
    }

    // ─────────────────────────────────────────────
    // API — Audit Intelligent de Liquidation (NOUVEAU)
    // Appelé en AJAX dès que le propriétaire ouvre le panneau de remboursement.
    // ─────────────────────────────────────────────

    #[Route('/api/audit/{cautionId}', name: 'caution_audit_financier', methods: ['GET'])]
    public function auditFinancier(int $cautionId, \App\Service\CautionAuditService $auditService): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        try {
            $audit = $auditService->calculateSmartAudit($cautionId);

            // Retirer les informations sensibles (email) de la réponse JSON frontend
            unset($audit['locataire_email']);

            return new JsonResponse([
                'success' => true,
                'audit'   => $audit,
            ]);
        } catch (\Throwable $e) {
            error_log('[CautionController] auditFinancier error: ' . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors du calcul de l\'audit.',
            ], 500);
        }
    }

    // ─────────────────────────────────────────────
    // API — Panel retenue (chargement AJAX)
    // ─────────────────────────────────────────────

    #[Route('/api/panel/{cautionId}', name: 'caution_panel_retenue', methods: ['GET'])]
    public function panelRetenue(int $cautionId, CautionRepository $rep): Response
    {
        if (!$this->getProprietaireId()) {
            return new Response('Non autorisé', 401);
        }

        $details = $rep->findCautionDetails($cautionId);
        $caution = !empty($details) ? $details[0] : null;

        return $this->render('caution/_panel_retenue.html.twig', ['caution' => $caution]);
    }

    // ─────────────────────────────────────────────
    // API — Confirmer retenue
    // ─────────────────────────────────────────────

    #[Route('/api/retenue/confirmer', name: 'caution_confirmer_retenue', methods: ['POST'])]
    public function confirmerRetenue(Request $request, CautionService $service): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        /** @var array<string, mixed> $data */
        $data = json_decode($request->getContent(), true) ?? [];
        $success = $service->saveRetentionComplete(
            (int)($data['caution_id'] ?? 0),
            (string)($data['retention'] ?? '0'),
            (string)($data['description'] ?? '')
        );

        return new JsonResponse(['success' => $success]);
    }

    // ─────────────────────────────────────────────
    // API — Upload photo + analyse Gemini
    // ─────────────────────────────────────────────

    #[Route('/api/upload-photo/{cautionId}', name: 'caution_upload_photo', methods: ['POST'])]
    public function uploadPhoto(int $cautionId, Request $request, GeminiService $gemini, CustomVisionService $customVision, EntityManagerInterface $em): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        $file = $request->files->get('photo');
        if (!$file) return new JsonResponse(['error' => 'Aucun fichier reçu'], 400);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return new JsonResponse(['error' => 'Format invalide (jpeg/png/gif/webp uniquement)'], 400);
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return new JsonResponse(['error' => 'Fichier trop lourd (max 5 MB)'], 400);
        }

        $conn = $em->getConnection();
        $contratId = $conn->fetchOne('SELECT contrat_id FROM caution WHERE id = ?', [$cautionId]);
        if (!$contratId) return new JsonResponse(['error' => 'Caution introuvable'], 404);

        $uploadDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . "/public/uploads/cautions/contrat_{$contratId}";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $filename   = uniqid('photo_') . '.' . $file->guessExtension();
        $file->move($uploadDir, $filename);
        $fichierUrl = "/uploads/cautions/contrat_{$contratId}/{$filename}";
        $fullPath   = $uploadDir . '/' . $filename;

        // ── Étape 1 : modèle custom YOLOv8 (silencieux si absent) ──────────────
        $content = file_get_contents($fullPath);
        $imageBase64  = base64_encode($content !== false ? $content : '');
        $mimeResult   = mime_content_type($fullPath);
        $mimeType     = $mimeResult !== false ? $mimeResult : 'application/octet-stream';
        $visionResult = $customVision->analyserImage($imageBase64, $mimeType);
        $tagsFormates = $customVision->formaterTagsPourPrompt($visionResult['tags']);

        // ── Étape 2 : analyse Gemini enrichie (ou standard si pas de tags) ─────
        $analyse = null;
        $analyseError = null;
        try {
            $analyse = $gemini->analyserPhoto($imageBase64, $mimeType, $visionResult['tags'], $tagsFormates);
        } catch (\Throwable $e) {
            error_log('[CautionController] Gemini analysis failed: ' . $e->getMessage());
            $analyseError = $e->getMessage();

            // ── Smart fallback : si YOLO a détecté quelque chose, on valorise ses résultats ──
            if (!empty($visionResult['tags'])) {
                // Traduire les tags en labels français
                $labelsFr = array_map(fn($t) => $t['label_fr'], $visionResult['tags']);

                // Dériver la gravité depuis la confiance du tag le mieux classé
                $topConf = $visionResult['tags'][0]['confidence'] ?? 0;
                $gravite = match(true) {
                    $topConf >= 0.80 => 'IMPORTANT',
                    $topConf >= 0.60 => 'MODERE',
                    default          => 'MINEUR',
                };

                // Construire une description lisible en français
                $descBase = 'Détections YOLOv8 Sakan : ' . implode(', ', $labelsFr);
                $descNote = ' (description détaillée indisponible momentanément — Gemini sera relancé à la prochaine tentative)';

                $analyse = [
                    'type_dommage'       => $visionResult['type_dommage_suggere'],
                    'mots_cles'          => $labelsFr,
                    'gravite'            => $gravite,
                    'description_courte' => 'Détections YOLOv8 Sakan : ' . implode(', ', $labelsFr) . '.',
                    'montant_estime_min' => 0,
                    'montant_estime_max' => 0,
                    'source'             => 'yolo_only',
                    'error'              => null,
                ];
            } else {
                // Ni YOLO ni Gemini → fallback minimal lisible
                $analyse = [
                    'type_dommage'       => 'AUTRE',
                    'mots_cles'          => [],
                    'gravite'            => 'AUCUN',
                    'description_courte' => 'Photo sauvegardée. Veuillez décrire les dommages manuellement.',
                    'montant_estime_min' => 0,
                    'montant_estime_max' => 0,
                    'source'             => 'manual',
                    'error'              => null,
                ];
            }
        } // fin catch Gemini

        $photo = new CautionRetenuePhoto();
        $photo->setCautionId($cautionId);
        $photo->setFichierUrl($fichierUrl);
        $photo->setNomFichier($filename);
        $photo->setTypeDommage($analyse['type_dommage'] ?? 'AUTRE');

        // Mots-clés : les tags YOLO en priorité (JSON structuré), sinon labels fallback
        if (!empty($visionResult['tags'])) {
            $encoded = json_encode($visionResult['tags'], JSON_UNESCAPED_UNICODE);
            $photo->setMotsClesGemini($encoded !== false ? $encoded : null);
            $labelsFrStr = implode(', ', array_map(fn($t) => $t['label_fr'], $visionResult['tags']));
            $photo->setMotsClesValides($labelsFrStr);
        } else {
            $photo->setMotsClesGemini(null);
            $photo->setMotsClesValides(is_array($analyse['mots_cles'] ?? null) ? implode(', ', $analyse['mots_cles']) : null);
        }

        // Stocker la description pro structurée
        $descriptionFr = $analyse['description_courte'] ?? '';
        $photo->setAnalyseGemini($descriptionFr);

        $photo->setGraviteGemini($analyse['gravite'] ?? 'AUCUN');
        $photo->setMontantEstime('0'); // Pas d'estimation financière IA
        $photo->setDateAjout(new \DateTime());

        try {
            $em->persist($photo);
            $em->flush();
        } catch (\Throwable $e) {
            error_log('[CautionController] Failed to save photo entity: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Erreur sauvegarde base de données: ' . $e->getMessage()], 500);
        }

        // Source finale pour le frontend
        $source = $analyse['source'] ?? ($visionResult['model_available'] ? 'sakan_ai_complet' : 'sakan_ai_texte');

        return new JsonResponse([
            'success'        => true,
            'photo_id'       => $photo->getId(),
            'url'            => $fichierUrl,
            'analyse'        => array_merge($analyse, [
                'description_fr' => $descriptionFr,
                'source'         => $source,
            ]),
            'model_custom'   => $visionResult['model_available'],
            'tags_detectes'  => $visionResult['tags'],
        ]);
    }

    // ─────────────────────────────────────────────
    // STRIPE — Initier session de paiement (v20 API)
    // ─────────────────────────────────────────────

    #[Route('/api/rembourser/initier', name: 'caution_initier_remboursement', methods: ['POST'])]
    public function initierRemboursement(Request $request): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        /** @var array<string, mixed> $data */
        $data      = json_decode($request->getContent(), true) ?? [];
        $cautionId = (int)($data['caution_id'] ?? 0);
        $amount    = (float)($data['amount'] ?? 0);

        error_log("[CautionController] initierRemboursement called: cautionId={$cautionId}, amount={$amount}");

        if ($amount <= 0) {
            error_log("[CautionController] Invalid amount: {$amount}");
            return new JsonResponse(['error' => 'Montant invalide (doit être > 0)'], 400);
        }

        if (!class_exists(\Stripe\StripeClient::class)) {
            error_log('[CautionController] Stripe PHP SDK not installed. Run: composer require stripe/stripe-php');
            return new JsonResponse(['error' => 'Stripe SDK non installé. Exécutez: composer require stripe/stripe-php'], 500);
        }

        // Récupérer la clé Stripe depuis les variables d'environnement
        $stripeKey = $_ENV['STRIPE_SECRET_KEY'] ?? $_ENV['STRIPE_API_KEY'] ?? '';

        // Debug: loguer les clés disponibles (masquées pour sécurité)
        $envKeys = array_keys($_ENV);
        $stripeKeysFound = array_filter($envKeys, fn($k) => stripos($k, 'STRIPE') !== false);
        error_log('[CautionController] Stripe keys found in ENV: ' . implode(', ', $stripeKeysFound));

        if (empty($stripeKey)) {
            error_log('[CautionController] STRIPE_SECRET_KEY not configured');
            return new JsonResponse([
                'error' => 'Clé Stripe non configurée. Ajoutez STRIPE_SECRET_KEY=sk_test_... dans .env.local',
                'debug' => 'Keys found: ' . implode(', ', $stripeKeysFound)
            ], 500);
        }

        // Vérifier le format de la clé
        if (!str_starts_with($stripeKey, 'sk_test_') && !str_starts_with($stripeKey, 'sk_live_')) {
            error_log('[CautionController] STRIPE_SECRET_KEY format invalid: ' . substr($stripeKey, 0, 10) . '...');
            return new JsonResponse(['error' => 'Format de clé Stripe invalide. Doit commencer par sk_test_ ou sk_live_'], 500);
        }

        error_log('[CautionController] Stripe key configured correctly (starts with: ' . substr($stripeKey, 0, 7) . '...)');

        try {
            $stripe = new \Stripe\StripeClient($stripeKey);

            // URL Stripe avec placeholder pour session_id (Stripe remplace automatiquement)
            $successUrl = $this->generateUrl('caution_stripe_success', [], UrlGeneratorInterface::ABSOLUTE_URL)
                . '?session_id={CHECKOUT_SESSION_ID}&caution_id=' . $cautionId;
            $cancelUrl  = $this->generateUrl('caution_stripe_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL)
                . '?caution_id=' . $cautionId;

            error_log("[CautionController] Stripe URLs - success: {$successUrl}, cancel: {$cancelUrl}");

            $session = $stripe->checkout->sessions->create([
                'mode'        => 'payment',
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
                'line_items'  => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => 'usd',
                        'unit_amount'  => (int)round($amount * 100),
                        'product_data' => [
                            'name'        => "Remboursement caution #$cautionId",
                            'description' => "Dépôt de garantie – Sakan Gestion Locative",
                        ],
                    ],
                ]],
                'payment_method_types' => ['card'],
                'metadata' => [
                    'caution_id' => (string)$cautionId,
                    'amount_tnd' => (string)$amount,
                    'source'     => 'sakan_symfony',
                ],
            ]);

            error_log("[CautionController] Stripe session created: " . $session->id);
            return new JsonResponse(['url' => $session->url, 'session_id' => $session->id]);
        } catch (\Exception $e) {
            error_log('[CautionController] Stripe error: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Erreur Stripe : ' . $e->getMessage()], 500);
        } catch (\Throwable $e) {
            error_log('[CautionController] Unexpected error: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Erreur inattendue : ' . $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────
    // STRIPE — Succès paiement → confirmer en DB + email
    // ─────────────────────────────────────────────

    #[Route('/stripe/success', name: 'caution_stripe_success', methods: ['GET'])]
    // Route avec paramètres optionnels pour gérer les retours Stripe
    public function stripeSuccess(Request $request, CautionService $service): Response
    {
        if ($redirect = $this->redirectIfNotAuth()) return $redirect;

        $sessionId = $request->query->get('session_id', '');
        $cautionId = (int)$request->query->get('caution_id', 0);

        if (!$sessionId || !$cautionId) {
            $this->addFlash('warning', 'Paramètres de retour Stripe invalides.');
            return $this->redirectToRoute('caution_a_regulariser');
        }

        $stripeKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';

        try {
            $stripe  = new \Stripe\StripeClient($stripeKey);
            $session = $stripe->checkout->sessions->retrieve((string)$sessionId);

            // Récupérer le montant TND original depuis la metadata Stripe
            // (on ne peut pas diviser amount_total car Stripe est en USD centimes)
            $amountPaid = (float)($session->metadata->amount_tnd ?? ($session->amount_total / 100));
            $ok = $service->confirmRefundPayment($cautionId, (string)$amountPaid, (string)$sessionId);

            if ($ok) {
                $this->addFlash('success', '✅ Remboursement confirmé ! Un reçu a été généré et un email a été envoyé au locataire.');
            } else {
                $this->addFlash('warning', 'Paiement Stripe enregistré, mais erreur lors de la mise à jour en base.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de la validation Stripe : ' . $e->getMessage());
        }

        return $this->redirectToRoute('caution_a_regulariser');
    }

    // ─────────────────────────────────────────────
    // STRIPE — Annulation paiement
    // ─────────────────────────────────────────────

    #[Route('/stripe/cancel', name: 'caution_stripe_cancel', methods: ['GET'])]
    // Route avec paramètre optionnel caution_id
    public function stripeCancel(Request $request): Response
    {
        $this->addFlash('warning', '⚠️ Paiement annulé. Aucune somme n\'a été débitée.');
        return $this->redirectToRoute('caution_a_regulariser');
    }
}
