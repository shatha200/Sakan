<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Repository\ChargesMensuellesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/proprietaire/charges')]
class ChargeController extends AbstractController
{
    // Types de charges avec icônes FA et labels — identique Java
    public const TYPES = [
        'ELECTRICITE'   => ['label' => 'Électricité',          'icon' => 'fa-bolt',              'color' => '#f59e0b'],
        'EAU'           => ['label' => 'Eau',                  'icon' => 'fa-droplet',            'color' => '#3b82f6'],
        'INTERNET'      => ['label' => 'Internet',             'icon' => 'fa-wifi',               'color' => '#8b5cf6'],
        'GAZ'           => ['label' => 'Gaz',                  'icon' => 'fa-fire-flame-simple',  'color' => '#ef4444'],
        'CHAUFFAGE'     => ['label' => 'Chauffage',            'icon' => 'fa-temperature-high',   'color' => '#f97316'],
        'ORDURES'       => ['label' => 'Ordures ménagères',    'icon' => 'fa-trash',              'color' => '#6b7280'],
        'CHARGES_COPRO' => ['label' => 'Charges copropriété', 'icon' => 'fa-building',            'color' => '#0ea5e9'],
        'ENTRETIEN'     => ['label' => 'Entretien',            'icon' => 'fa-screwdriver-wrench', 'color' => '#10b981'],
        'AUTRE'         => ['label' => 'Autre',                'icon' => 'fa-circle-question',    'color' => '#9ca3af'],
    ];

    // ─────────────────────────────────────────────────────────────
    // Auth — même pattern que OwnerController / CautionController
    // ─────────────────────────────────────────────────────────────
    private function getProprietaireId(): ?int
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return null;
        }
        return (int) $user->getId();
    }

    private function redirectIfNotAuth(): ?Response
    {
        if (!$this->getProprietaireId()) {
            return $this->redirectToRoute('app_login');
        }
        return null;
    }

    private function requireAuth(): ?JsonResponse
    {
        if (!$this->getProprietaireId()) {
            return new JsonResponse(['error' => 'Non autorisé. Veuillez vous connecter.'], 401);
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // VUES PRINCIPALES
    // ═══════════════════════════════════════════════════════════════

    // ─────────────────────────────────────────────────────────────
    // VUE — Frais de gestion (charges actives + mois en cours)
    // Equivalent ChargesMensuellesController.java
    // ─────────────────────────────────────────────────────────────
    #[Route('/frais-gestion', name: 'charge_frais_gestion', methods: ['GET'])]
    public function fraisGestion(ChargesMensuellesRepository $repo, \App\Service\ChargeLocataireService $chargeLocataireService): Response
    {
        if ($redirect = $this->redirectIfNotAuth()) return $redirect;
        $pid = $this->getProprietaireId();

        $charges = $repo->findChargesMensuelles((int)$pid);

        // Calculer les KPIs pour le header
        $totalMontant  = array_sum(array_column($charges, 'montant'));
        $totalImpaye   = array_sum(array_map(
            fn($c) => $c['statut_paiement'] !== 'PAYE' ? (float)($c['reste_a_payer'] ?? 0) : 0,
            $charges
        ));
        $nbNonPaye  = count(array_filter($charges, fn($c) => $c['statut_paiement'] === 'NON_PAYE'));
        $nbPartiel  = count(array_filter($charges, fn($c) => $c['statut_paiement'] === 'PARTIEL'));
        $nbPaye     = count(array_filter($charges, fn($c) => $c['statut_paiement'] === 'PAYE'));

        // Récupérer les contrats actifs pour le formulaire d'ajout
        $contratsActifs = $repo->findContratsActifs((int)$pid);

        // --- MOTEUR D'ALERTES (UI) ---
        $cautionRiskAlerts = $chargeLocataireService->getCautionRiskOwnerAlerts((int)$pid);

        return $this->render('owner/charges/frais_gestion.html.twig', [
            'charges'             => $charges,
            'types'               => self::TYPES,
            'contratsActifs'      => $contratsActifs,
            'totalMontant'        => $totalMontant,
            'totalImpaye'         => $totalImpaye,
            'nbNonPaye'           => $nbNonPaye,
            'nbPartiel'           => $nbPartiel,
            'nbPaye'              => $nbPaye,
            'caution_risk_alerts' => $cautionRiskAlerts,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // VUE — Frais annuels / Historique cycle annuel
    // Equivalent ChargesHistoriqueController.java
    // ─────────────────────────────────────────────────────────────
    #[Route('/frais-annuels', name: 'charge_frais_annuels', methods: ['GET'])]
    public function fraisAnnuels(Request $request, ChargesMensuellesRepository $repo): Response
    {
        if ($redirect = $this->redirectIfNotAuth()) return $redirect;
        $pid  = $this->getProprietaireId();
        $year = $request->query->getInt('year', (int)date('Y'));

        $charges = $repo->findChargesHistorique((int)$pid, $year);
        $kpis    = $repo->getKpis((int)$pid, $year);

        // Années disponibles (5 dernières)
        $years   = range((int)date('Y'), (int)date('Y') - 4);

        // Biens distincts pour le switcher (comme Java renderHousingSwitcher)
        $biens   = array_values(array_unique(array_column($charges, 'nom_bien')));

        // Types distincts pour le filtre
        $typesPresents = array_values(array_unique(array_column($charges, 'type_charge')));

        // Grouper par bien (LinkedHashMap Java → PHP array ordonné)
        $grouped = [];
        foreach ($charges as $c) {
            $grouped[$c['nom_bien']][] = $c;
        }

        return $this->render('owner/charges/frais_annuels.html.twig', [
            'charges'       => $charges,
            'grouped'       => $grouped,
            'types'         => self::TYPES,
            'typesPresents' => $typesPresents,
            'biens'         => $biens,
            'kpis'          => $kpis,
            'year'          => $year,
            'years'         => $years,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // API ENDPOINTS
    // ═══════════════════════════════════════════════════════════════

    // ─────────────────────────────────────────────────────────────
    // API — Marquer une charge comme payée (transaction DB)
    // ─────────────────────────────────────────────────────────────
    #[Route('/api/payer/{chargeId}', name: 'charge_marquer_paye', methods: ['POST'])]
    public function marquerPaye(int $chargeId, Request $request, ChargesMensuellesRepository $repo): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        $data      = json_decode($request->getContent(), true) ?? [];
        $montant   = (string)($data['montant']   ?? 0);
        $methode   = (string)($data['methode']   ?? 'MANUAL');
        $reference = (string)($data['reference'] ?? '');

        $ok = $repo->marquerPaye($chargeId, $montant, $methode, $reference);

        return new JsonResponse([
            'success' => $ok,
            'message' => $ok ? 'Charge marquée comme payée.' : 'Erreur lors de la mise à jour.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // API — Ajouter une nouvelle charge
    // ─────────────────────────────────────────────────────────────
    #[Route('/api/ajouter', name: 'charge_ajouter', methods: ['POST'])]
    public function ajouter(Request $request, ChargesMensuellesRepository $repo): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['contrat_id']) || empty($data['type_charge']) || empty($data['montant'])) {
            return new JsonResponse(['error' => 'Champs obligatoires manquants (contrat_id, type_charge, montant).'], 400);
        }

        if (!isset(self::TYPES[$data['type_charge']])) {
            return new JsonResponse(['error' => 'Type de charge invalide.'], 400);
        }

        $id = $repo->ajouterCharge($data);

        return new JsonResponse([
            'success' => $id !== false,
            'id'      => $id,
            'message' => $id !== false ? 'Charge ajoutée avec succès.' : 'Erreur lors de l\'ajout.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // API — Upload facture (PDF / image)
    // ─────────────────────────────────────────────────────────────
    #[Route('/api/upload-facture/{chargeId}', name: 'charge_upload_facture', methods: ['POST'])]
    public function uploadFacture(int $chargeId, Request $request, ChargesMensuellesRepository $repo): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        $file = $request->files->get('facture');
        if (!$file) {
            return new JsonResponse(['error' => 'Aucun fichier reçu'], 400);
        }

        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        $allowedExts  = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $ext          = strtolower($file->guessExtension() ?? '');

        if (!in_array($file->getMimeType(), $allowedMimes) || !in_array($ext, $allowedExts)) {
            return new JsonResponse(['error' => 'Format invalide (PDF/JPG/PNG uniquement)'], 400);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            return new JsonResponse(['error' => 'Fichier trop lourd (max 10 MB)'], 400);
        }

        $uploadDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/public/uploads/factures';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $filename = time() . '_charge' . $chargeId . '.' . $ext;
        $file->move($uploadDir, $filename);

        $ok  = $repo->updateFichierFacture($chargeId, $filename);
        $url = '/uploads/factures/' . $filename;

        return new JsonResponse([
            'success'  => $ok,
            'filename' => $filename,
            'url'      => $url,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // API — Génération auto charges Internet mois prochain
    // ─────────────────────────────────────────────────────────────
    #[Route('/api/generer-internet', name: 'charge_generer_internet', methods: ['POST'])]
    public function genererInternet(ChargesMensuellesRepository $repo): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        $nb = $repo->genererInternetMoisProchain();

        return new JsonResponse([
            'success' => true,
            'created' => $nb,
            'message' => "$nb charge(s) internet générée(s) pour le mois prochain.",
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // API — Contrats actifs (pour le formulaire d'ajout)
    // ─────────────────────────────────────────────────────────────
    #[Route('/api/contrats-actifs', name: 'charge_contrats_actifs', methods: ['GET'])]
    public function contratsActifs(ChargesMensuellesRepository $repo): JsonResponse
    {
        if ($err = $this->requireAuth()) return $err;

        return new JsonResponse($repo->findContratsActifs((int)$this->getProprietaireId()));
    }
}
