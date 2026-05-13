<?php

namespace App\Controller;

use App\Repository\AdminCautionRepository;
use App\Repository\AdminChargeRepository;
use App\Repository\AdminLoyerRepository;
use App\Repository\AdminStripeRepository;
use App\Service\AdminFinanceAlerteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/finance')]
class AdminFinanceController extends AbstractController
{
    // ═══════════════════════════════════════════════════════════════════
    // BADGE D'ALERTES (appelé via render controller depuis base_admin)
    // ═══════════════════════════════════════════════════════════════════

    public function alertsBadge(AdminFinanceAlerteService $alertService): Response
    {
        $count = $alertService->countAlertes();
        if ($count > 0) {
            $display = $count > 9 ? '9+' : (string)$count;
            return new Response(
                '<span class="admin-dot" style="display:flex;align-items:center;justify-content:center;'
                . 'font-size:9px;color:white;font-weight:bold;width:16px;height:16px;top:3px;right:3px;">'
                . $display . '</span>'
            );
        }
        return new Response('<span class="admin-dot"></span>');
    }

    // ═══════════════════════════════════════════════════════════════════
    // DASHBOARD GLOBAL
    // ═══════════════════════════════════════════════════════════════════

    #[Route('', name: 'admin_finance_dashboard', methods: ['GET'])]
    public function dashboard(
        AdminLoyerRepository $loyerRepo,
        AdminChargeRepository $chargeRepo,
        AdminCautionRepository $cautionRepo
    ): Response {
        $kpisLoyers  = $loyerRepo->getKpis();
        $kpisCharges = $chargeRepo->getKpis();
        $kpisCautions = $cautionRepo->getKpis();
        $retards  = $loyerRepo->findEnRetard();
        $impayees = $chargeRepo->getTopImpayees(5);

        $totalAttendu = ($kpisLoyers['total_montant'] ?? 0) + ($kpisCharges['total_montant'] ?? 0);
        $totalPaye    = ($kpisLoyers['montant_paye'] ?? 0) + ($kpisCharges['montant_paye'] ?? 0);
        $tauxPaiement = $totalAttendu > 0 ? round(($totalPaye / $totalAttendu) * 100, 1) : 0;

        return $this->render('admin/finance/dashboard.html.twig', [
            'kpis_loyers'   => $kpisLoyers,
            'kpis_charges'  => $kpisCharges,
            'kpis_cautions' => $kpisCautions,
            'taux_paiement' => $tauxPaiement,
            'top_retards'   => array_slice($retards, 0, 5),
            'top_impayees'  => $impayees,
            'evolution'     => $loyerRepo->getEvolution12Mois(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // MODULE LOYERS
    // ═══════════════════════════════════════════════════════════════════

    #[Route('/loyers', name: 'admin_finance_loyers', methods: ['GET'])]
    public function loyers(Request $request, AdminLoyerRepository $repo): Response
    {
        $filters = [
            'statut'  => $request->query->get('statut', 'TOUS'),
            'search'  => $request->query->get('search', ''),
            'periode' => $request->query->get('periode', ''),
        ];

        return $this->render('admin/finance/loyer/liste.html.twig', [
            'kpis'    => $repo->getKpis(),
            'loyers'  => $repo->findAll($filters),
            'retards' => $repo->findEnRetard(),
            'filters' => $filters,
        ]);
    }

    #[Route('/loyers/creer', name: 'admin_finance_loyer_create', methods: ['GET', 'POST'])]
    public function loyerCreate(Request $request, AdminLoyerRepository $repo): Response
    {
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $this->container->get('doctrine')->getConnection();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_loyer_create', (string)$request->request->get('_csrf_token'))) {
                $this->addFlash('auth_error', 'Token CSRF invalide.');
                return $this->redirectToRoute('admin_finance_loyer_create');
            }

            $mois = str_pad((string)$request->request->get('periode_mois', '1'), 2, '0', STR_PAD_LEFT);
            $annee = $request->request->get('periode_annee', date('Y'));

            $data = [
                'contrat_id'    => $request->request->get('contrat_id'),
                'periode'       => $annee . '-' . $mois,
                'montant'       => $request->request->get('montant', 0),
                'date_echeance' => $request->request->get('date_echeance'),
                'statut'        => $request->request->get('statut', 'EN_ATTENTE'),
                'penalite'      => $request->request->get('penalite', 0),
                'notes'         => $request->request->get('notes'),
            ];

            try {
                $id = $repo->creer($data);
                $this->addFlash('auth_success', 'Loyer créé avec succès.');
                return $this->redirectToRoute('admin_finance_loyer_detail', ['id' => $id]);
            } catch (\Exception $e) {
                $this->addFlash('auth_error', 'Erreur lors de la création : ' . $e->getMessage());
            }
        }

        $contrats = $conn->fetchAllAssociative("
            SELECT c.id, a.titre AS bien_titre, ul.nom AS locataire_nom, ul.email AS locataire_email
            FROM contrat c
            LEFT JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur ul ON c.locataireId = ul.id
            WHERE c.date_fin IS NULL OR c.date_fin >= CURRENT_DATE()
            ORDER BY c.id DESC LIMIT 100
        ");

        return $this->render('admin/finance/loyer/creer.html.twig', [
            'contrats' => $contrats,
        ]);
    }

    #[Route('/loyers/{id}', name: 'admin_finance_loyer_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function loyerDetail(int $id, AdminLoyerRepository $repo): Response
    {
        $loyer = $repo->findDetail($id);
        if (!$loyer) {
            $this->addFlash('auth_error', 'Loyer introuvable.');
            return $this->redirectToRoute('admin_finance_loyers');
        }

        return $this->render('admin/finance/loyer/detail.html.twig', [
            'loyer' => $loyer,
        ]);
    }

    #[Route('/loyers/{id}/forcer-paiement', name: 'admin_finance_loyer_forcer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function loyerForcerPaiement(int $id, Request $request, AdminLoyerRepository $repo): Response
    {
        if (!$this->isCsrfTokenValid('admin_loyer_force_' . $id, (string)$request->request->get('_csrf_token'))) {
            $this->addFlash('auth_error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_finance_loyer_detail', ['id' => $id]);
        }

        $ok = $repo->forcerPaiement(
            $id,
            (string)$request->request->get('methode', 'MANUEL'),
            (string)$request->request->get('reference') ?: null,
            (string)$request->request->get('date_paiement', date('Y-m-d')) ?: null
        );

        $this->addFlash(
            $ok ? 'auth_success' : 'auth_error',
            $ok ? 'Paiement loyer enregistré avec succès.' : 'Erreur lors de la mise à jour.'
        );

        return $this->redirectToRoute('admin_finance_loyer_detail', ['id' => $id]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // MODULE CHARGES
    // ═══════════════════════════════════════════════════════════════════

    #[Route('/charges', name: 'admin_finance_charges', methods: ['GET'])]
    public function charges(Request $request, AdminChargeRepository $repo): Response
    {
        $filters = [
            'statut' => $request->query->get('statut', 'TOUS'),
            'type'   => $request->query->get('type', 'TOUS'),
            'search' => $request->query->get('search', ''),
        ];

        return $this->render('admin/finance/charge/liste.html.twig', [
            'kpis'     => $repo->getKpis(),
            'charges'  => $repo->findAll($filters),
            'impayees' => $repo->findImpayees(),
            'filters'  => $filters,
        ]);
    }

    #[Route('/charges/creer', name: 'admin_finance_charge_create', methods: ['GET', 'POST'])]
    public function chargeCreate(Request $request, AdminChargeRepository $repo, SluggerInterface $slugger): Response
    {
        /** @var \Doctrine\DBAL\Connection $conn */
        $conn = $this->container->get('doctrine')->getConnection();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_charge_create', (string)$request->request->get('_csrf_token'))) {
                $this->addFlash('auth_error', 'Token CSRF invalide.');
                return $this->redirectToRoute('admin_finance_charge_create');
            }

            $mois  = str_pad((string)$request->request->get('periode_mois', '1'), 2, '0', STR_PAD_LEFT);
            $annee = $request->request->get('periode_annee', date('Y'));

            $data = [
                'contrat_id'      => $request->request->get('contrat_id'),
                'type_charge'     => $request->request->get('type_charge'),
                'periode'         => $annee . '-' . $mois,
                'montant_a_payer' => $request->request->get('montant', 0),
                'statut_paiement' => $request->request->get('statut', 'NON_PAYE'),
                'notes'           => $request->request->get('notes'),
            ];

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $file */
            $file = $request->files->get('facture');
            if ($file) {
                $safeFilename = $slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
                $newFilename  = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();
                try {
                    $file->move((is_string($dir = $this->getParameter('charges_directory')) ? $dir : ''), $newFilename);
                    $data['fichier_facture'] = $newFilename;
                } catch (FileException) {
                    $this->addFlash('auth_error', "Erreur lors de l'upload de la facture.");
                }
            }

            try {
                $id = $repo->creer($data);
                $this->addFlash('auth_success', 'Charge créée avec succès.');
                return $this->redirectToRoute('admin_finance_charge_detail', ['id' => $id]);
            } catch (\Exception $e) {
                $this->addFlash('auth_error', 'Erreur création : ' . $e->getMessage());
            }
        }

        $contrats = $conn->fetchAllAssociative("
            SELECT c.id, a.titre AS bien_titre, ul.nom AS locataire_nom
            FROM contrat c
            JOIN annonce a ON c.annonceId = a.id
            JOIN utilisateur ul ON c.locataireId = ul.id
            ORDER BY c.id DESC LIMIT 100
        ");

        return $this->render('admin/finance/charge/creer.html.twig', [
            'contrats' => $contrats,
        ]);
    }

    #[Route('/charges/{id}', name: 'admin_finance_charge_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function chargeDetail(int $id, AdminChargeRepository $repo): Response
    {
        $charge = $repo->findDetail($id);
        if (!$charge) {
            $this->addFlash('auth_error', 'Charge introuvable.');
            return $this->redirectToRoute('admin_finance_charges');
        }

        return $this->render('admin/finance/charge/detail.html.twig', [
            'charge' => $charge,
        ]);
    }

    #[Route('/charges/{id}/marquer-paye', name: 'admin_finance_charge_payer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function chargeMarquerPaye(int $id, Request $request, AdminChargeRepository $repo): Response
    {
        if (!$this->isCsrfTokenValid('admin_charge_paye_' . $id, (string)$request->request->get('_csrf_token'))) {
            $this->addFlash('auth_error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_finance_charge_detail', ['id' => $id]);
        }

        $ok = $repo->marquerPaye(
            $id,
            (string)$request->request->get('methode', 'MANUEL'),
            (string)$request->request->get('reference') ?: null,
            (string)$request->request->get('date_paiement', date('Y-m-d')) ?: null
        );

        $this->addFlash(
            $ok ? 'auth_success' : 'auth_error',
            $ok ? 'Charge marquée comme payée.' : 'Erreur lors de la mise à jour.'
        );

        return $this->redirectToRoute('admin_finance_charge_detail', ['id' => $id]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // MODULE CAUTIONS
    // ═══════════════════════════════════════════════════════════════════

    #[Route('/cautions', name: 'admin_finance_cautions', methods: ['GET'])]
    public function cautions(Request $request, AdminCautionRepository $repo): Response
    {
        $filters = [
            'statut' => $request->query->get('statut', 'TOUS'),
            'search' => $request->query->get('search', ''),
        ];

        return $this->render('admin/finance/caution/liste.html.twig', [
            'kpis'     => $repo->getKpis(),
            'cautions' => $repo->findAll($filters),
            'filters'  => $filters,
        ]);
    }

    #[Route('/cautions/{id}', name: 'admin_finance_caution_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function cautionDetail(int $id, AdminCautionRepository $repo): Response
    {
        $caution = $repo->findDetail($id);
        if (!$caution) {
            $this->addFlash('auth_error', 'Caution introuvable.');
            return $this->redirectToRoute('admin_finance_cautions');
        }

        return $this->render('admin/finance/caution/detail.html.twig', [
            'caution' => $caution,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // MODULE RAPPORTS
    // ═══════════════════════════════════════════════════════════════════

    #[Route('/rapports', name: 'admin_finance_rapports', methods: ['GET'])]
    public function rapports(
        AdminLoyerRepository $loyerRepo,
        AdminChargeRepository $chargeRepo,
        AdminCautionRepository $cautionRepo
    ): Response {
        return $this->render('admin/finance/rapports/index.html.twig', [
            'kpis_loyers'   => $loyerRepo->getKpis(),
            'kpis_charges'  => $chargeRepo->getKpis(),
            'kpis_cautions' => $cautionRepo->getKpis(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // MODULE STRIPE (Lecture seule)
    // ═══════════════════════════════════════════════════════════════════

    #[Route('/stripe', name: 'admin_finance_stripe', methods: ['GET'])]
    public function stripe(Request $request, AdminStripeRepository $repo): Response
    {
        $filters = [
            'search' => $request->query->get('search', ''),
            'statut' => $request->query->get('statut', ''),
        ];

        return $this->render('admin/finance/stripe/liste.html.twig', [
            'kpis'         => $repo->getKpis(),
            'transactions' => $repo->findAll($filters),
            'filters'      => $filters,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // CONFIGURATION
    // ═══════════════════════════════════════════════════════════════════

    #[Route('/settings', name: 'admin_finance_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $configFile = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/var/finance_settings.json';

        $settings = [];
        if (file_exists($configFile)) {
            $settings = json_decode(is_string($content = file_get_contents($configFile)) ? $content : '', true) ?: [];
        }

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('admin_finance_settings', (string)$request->request->get('_csrf_token'))) {
                $settings = [
                    'jour_echeance_defaut'      => (int) $request->request->get('jour_echeance_defaut', 5),
                    'taux_penalite_defaut'       => (float) $request->request->get('taux_penalite_defaut', 2),
                    'delai_avant_penalite'       => (int) $request->request->get('delai_avant_penalite', 5),
                    'delai_legal_remboursement'  => (int) $request->request->get('delai_legal_remboursement', 2),
                    'alerte_avant_echeance'      => (int) $request->request->get('alerte_avant_echeance', 7),
                    'stripe_mode'               => $request->request->get('stripe_mode', 'sandbox'),
                ];
                file_put_contents($configFile, json_encode($settings, JSON_PRETTY_PRINT));
                $this->addFlash('auth_success', 'Configuration enregistrée.');
                return $this->redirectToRoute('admin_finance_settings');
            }
        }

        return $this->render('admin/finance/settings.html.twig', [
            'settings' => $settings,
        ]);
    }
}
