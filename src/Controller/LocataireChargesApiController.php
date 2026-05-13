<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\ChargeLocataireService;
use App\Service\GeminiInvoiceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * LocataireChargesApiController - API AJAX complete pour le module "Mes Charges" locataire.
 *
 * Utilise le systeme d'authentification Symfony ($this->getUser()) — identique a LocataireController.
 *
 * Routes :
 *   POST /locataire/charges/ajouter                -> creerCharge
 *   POST /locataire/charges/{id}/modifier          -> modifierCharge
 *   POST /locataire/charges/{id}/supprimer         -> supprimerCharge
 *   POST /locataire/charges/{id}/payer             -> marquerPaye (preuve upload + UPDATE statut=PAYE)
 *   POST /locataire/charges/{id}/upload-facture    -> uploadFacture
 *   POST /locataire/charges/analyser-facture       -> analyserFactureGemini
 *   GET  /locataire/charges/{id}/paiements         -> getPaiements
 */
#[Route('/locataire/charges')]
class LocataireChargesApiController extends AbstractController
{
    /**
     * Retourne l'ID du locataire connecte via le systeme Symfony Security.
     * Aligne avec LocataireController::financesCharges().
     */
    private function getLocataireId(): ?int
    {
        $user = $this->getUser();
        if ($user instanceof Utilisateur) {
            return (int) $user->getId();
        }
        return null;
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Non autorisé. Veuillez vous connecter.'], 401);
    }

    // ════════════════════════════════════════════════════════════════════════════════════════════
    // CRÉER une charge (avec fichier optionnel)
    // ════════════════════════════════════════════════════════════════════════════════════════════
    #[Route('/ajouter', name: 'locataire_charge_ajouter', methods: ['POST'])]
    public function creerCharge(
        Request               $request,
        ChargeLocataireService $service
    ): JsonResponse {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        $data = $request->request->all();

        // Vérification appartenance du contrat
        $contratId = (int)($data['contrat_id'] ?? 0);
        if (!$contratId || !$service->ownsContrat($contratId, $locataireId)) {
            return new JsonResponse(['error' => 'Contrat invalide ou non autorisé'], 403);
        }

        // Gestion upload fichier
        $file = $request->files->get('facture');
        $filename = null;
        if ($file) {
            $filename = $service->saveFactureFile(
                $file,
                0, // ID pas encore connu
                $data['type_charge'] ?? 'AUTRE',
                (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '')
            );
            if ($filename === false) {
                return new JsonResponse(['error' => 'Fichier invalide (PDF/JPG/PNG/WEBP, max 10 MB)'], 400);
            }
        }

        $data['fichier_facture'] = $filename;

        $id = $service->creerCharge($data);
        if ($id === false) {
            return new JsonResponse(['error' => 'Erreur lors de la création de la charge'], 500);
        }

        // ── MOTEUR D'ANOMALIE ─────────────────────────────────────────────────
        $typeChargeUpper = strtoupper($data['type_charge'] ?? 'AUTRE');
        $periode         = $data['periode'] ?? date('Y-m-01');
        $montantFloat    = (float)($data['montant'] ?? 0);
        $statutCharge    = strtoupper($data['statut_paiement'] ?? 'NON_PAYE');
        $service->checkAndNotifyAnomaly(
            $contratId,
            $typeChargeUpper,
            $montantFloat,
            $periode,
            $locataireId
        );
        // ── RISQUE CAUTION : alerte propriétaire si charge impayée ─────────────
        $service->checkCautionRisk($contratId, $statutCharge, $montantFloat, $typeChargeUpper);
        // ─────────────────────────────────────────────────────────────────────

        // Si fichier uploadé avec ID=0, renommer avec vrai ID
        if ($filename) {
            $newFilename = str_replace('_charge0', "_charge{$id}", $filename);
            $uploadDir   = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '') . '/public/uploads/factures/';
            if (file_exists($uploadDir . $filename)) {
                rename($uploadDir . $filename, $uploadDir . $newFilename);
                $service->updateFichierFacture($id, $locataireId, $newFilename);
            }
        }

        // Si la facture est marquée "déjà payée" lors de sa création
        if (strtoupper($data['statut_paiement'] ?? '') === 'PAYE') {
            $montant = (float)($data['montant'] ?? 0);
            $okPaiement = $service->marquerCommePaye($id, $locataireId, $montant, 'MANUEL', '', 'Déjà payée à l\'ajout');
            if ($okPaiement) {
                $charge = $service->getChargeById((int)$id, $locataireId);
                $locataire = $service->getLocataireInfo($locataireId);
                if ($charge && $locataire) {
                    $service->sendPaymentConfirmationEmail($charge, $locataire, $montant, 'AJOUT_PAYE');
                }
            }
        }

        return new JsonResponse([
            'success' => true,
            'id'      => $id,
            'message' => 'Charge créée avec succès.',
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════════════════════════
    // MODIFIER une charge
    // ════════════════════════════════════════════════════════════════════════════════════════════
    #[Route('/{id}/modifier', name: 'locataire_charge_modifier', methods: ['POST'])]
    public function modifierCharge(
        int                    $id,
        Request                $request,
        ChargeLocataireService $service
    ): JsonResponse {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        $existingCharge = $service->getChargeById($id, $locataireId);
        if (!$existingCharge) {
            return new JsonResponse(['error' => 'Charge introuvable ou accès non autorisé'], 403);
        }

        $data = $request->request->all();
        $file = $request->files->get('facture');
        $newFilename = null;
        $projectDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '');

        // Valider et stocker d'abord le nouveau fichier si fourni.
        if ($file) {
            $filename = $service->saveFactureFile(
                $file,
                $id,
                $data['type_charge'] ?? 'AUTRE',
                $projectDir
            );
            if ($filename === false) {
                return new JsonResponse(['error' => 'Fichier invalide (PDF/JPG/PNG/WEBP, max 10 MB)'], 400);
            }
            $newFilename = $filename;
        }

        $ok = $service->modifierCharge($id, $locataireId, $data);
        if (!$ok) {
            // Eviter de laisser un fichier orphelin si la modif DB échoue.
            if ($newFilename) {
                $newPath = $projectDir . '/public/uploads/factures/' . basename($newFilename);
                if (file_exists($newPath)) {
                    @unlink($newPath);
                }
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur ou accès non autorisé.',
            ]);
        }

        // ── MOTEUR D'ANOMALIE (modification) ──────────────────────────────────
        $typeChargeModif  = strtoupper($data['type_charge'] ?? ($existingCharge['type_charge'] ?? 'AUTRE'));
        $periodeModif     = $data['periode'] ?? ($existingCharge['periode'] ?? date('Y-m-01'));
        $montantModif     = (float)($data['montant'] ?? $existingCharge['montant'] ?? 0);
        $service->checkAndNotifyAnomaly(
            (int)($existingCharge['contrat_id'] ?? 0),
            $typeChargeModif,
            $montantModif,
            $periodeModif,
            $locataireId
        );
        // ── RISQUE CAUTION (Modification) ───────────────────────────────────
        $statutModif = strtoupper($data['statut_paiement'] ?? ($existingCharge['statut_paiement'] ?? 'NON_PAYE'));
        $service->checkCautionRisk(
            (int)($existingCharge['contrat_id'] ?? 0),
            $statutModif,
            $montantModif,
            $typeChargeModif
        );
        // ─────────────────────────────────────────────────────────────────────

        // Associer le nouveau fichier + supprimer l'ancien physique.
        if ($newFilename) {
            $updated = $service->updateFichierFacture($id, $locataireId, $newFilename);
            if (!$updated) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Modification enregistrée, mais impossible de mettre à jour la facture.',
                ], 500);
            }

            if (!empty($existingCharge['fichier_facture'])) {
                $oldBasename = basename((string)$existingCharge['fichier_facture']);
                if ($oldBasename !== basename($newFilename)) {
                    $oldPath = $projectDir . '/public/uploads/factures/' . $oldBasename;
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }
            }
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Charge modifiée avec succès.',
        ]);
    }
    #[Route('/{id}/supprimer', name: 'locataire_charge_supprimer', methods: ['POST'])]
    public function supprimerCharge(
        int                    $id,
        Request                $request,
        ChargeLocataireService $service
    ): JsonResponse {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        // Récupérer la charge pour supprimer le fichier physique
        $charge = $service->getChargeById($id, $locataireId);
        if (!$charge) {
            return new JsonResponse(['error' => 'Charge introuvable ou accès non autorisé'], 403);
        }

        // Supprimer fichier physique si présent
        if (!empty($charge['fichier_facture'])) {
            $filePath = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '')
                . '/public/uploads/factures/'
                . basename((string)$charge['fichier_facture']);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        $ok = $service->supprimerCharge($id, $locataireId);

        return new JsonResponse([
            'success'  => $ok,
            'redirect' => '/locataire/finances/charges',
            'message'  => $ok ? 'Charge supprimée avec succès.' : 'Erreur lors de la suppression.',
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════════════════════════
    // MARQUER COMME PAYÉ — upload preuve + UPDATE statut=PAYE
    // Identique à Java: updateChargePayee(ChargeMensuelle c, File newFile)
    // ════════════════════════════════════════════════════════════════════════════════════════════
    #[Route('/{id}/payer', name: 'locataire_charge_payer_manuel', methods: ['POST'])]
    public function marquerPaye(
        int                    $id,
        Request                $request,
        ChargeLocataireService $service
    ): JsonResponse {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        $charge = $service->getChargeById($id, $locataireId);
        if (!$charge) {
            return new JsonResponse(['error' => 'Charge introuvable ou accès non autorisé'], 403);
        }

        // Validation: preuve de paiement obligatoire (comme dans Java)
        $file = $request->files->get('preuve');
        if (!$file) {
            return new JsonResponse([
                'error'   => 'La preuve de paiement est obligatoire (photo du reçu ou PDF).',
                'success' => false,
            ], 400);
        }

        // Upload preuve → remplace l'ancien fichier (comme Java: delete old + save new)
        $oldFichier = $charge['fichier_facture'] ?? null;
        $filename   = $service->saveFactureFile(
            $file,
            $id,
            'PREUVE_' . $charge['type_charge'],
            (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '')
        );

        if ($filename === false) {
            return new JsonResponse(['error' => 'Fichier invalide (JPG/PNG/PDF, max 10 MB)'], 400);
        }

        // Supprimer l'ancien fichier si existant (comme Java)
        if ($oldFichier) {
            $oldPath = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '')
                . '/public/uploads/factures/'
                . basename((string)$oldFichier);
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // UPDATE fichier_facture avec la preuve
        $service->updateFichierFacture($id, $locataireId, $filename);

        $montant = (float)($charge['montant_a_payer'] ?? $charge['montant']);
        $notes   = $request->request->get('notes', 'Charge marquée comme payée par le locataire');

        // UPDATE statut_paiement = 'PAYE' (comme Java: UPDATE charges_mensuelles SET statut_paiement=PAYE)
        $ok = $service->marquerCommePaye($id, $locataireId, $montant, 'MANUEL', '', (string)$notes);

        if ($ok) {
            // Notification email (en plus de Java pour meilleure UX web)
            $locataire = $service->getLocataireInfo($locataireId);
            if ($locataire) {
                $service->sendPaymentConfirmationEmail($charge, $locataire, $montant, 'HORS_LIGNE', $filename);
            }
        }

        return new JsonResponse([
            'success' => $ok,
            'message' => $ok ? 'Charge marquée comme payée avec succès.' : 'Erreur lors de l\'enregistrement.',
        ]);
    }



    // ════════════════════════════════════════════════════════════════════════════════════════════
    // UPLOAD FACTURE (pour une charge existante)
    // ════════════════════════════════════════════════════════════════════════════════════════════
    #[Route('/{id}/upload-facture', name: 'locataire_charge_upload_facture', methods: ['POST'])]
    public function uploadFacture(
        int                    $id,
        Request                $request,
        ChargeLocataireService $service
    ): JsonResponse {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        $file = $request->files->get('facture');
        if (!$file) {
            return new JsonResponse(['error' => 'Aucun fichier reçu'], 400);
        }

        $charge = $service->getChargeById($id, $locataireId);
        if (!$charge) {
            return new JsonResponse(['error' => 'Charge introuvable ou accès non autorisé'], 403);
        }

        $filename = $service->saveFactureFile(
            $file,
            $id,
            $charge['type_charge'] ?? 'AUTRE',
            (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '')
        );

        if ($filename === false) {
            return new JsonResponse(['error' => 'Format invalide ou fichier trop lourd (max 10 MB)'], 400);
        }

        $ok  = $service->updateFichierFacture($id, $locataireId, $filename);
        $url = '/uploads/factures/' . $filename;

        return new JsonResponse([
            'success'  => $ok,
            'filename' => $filename,
            'url'      => $url,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════════════════════════
    // ANALYSER FACTURE avec Gemini AI
    // ════════════════════════════════════════════════════════════════════════════════════════════
    #[Route('/analyser-facture', name: 'locataire_charge_analyser_facture', methods: ['POST'])]
    public function analyserFactureGemini(
        Request               $request,
        GeminiInvoiceService  $gemini
    ): JsonResponse {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        $file = $request->files->get('facture');
        if (!$file) {
            return new JsonResponse(['error' => 'Aucun fichier reçu'], 400);
        }

        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $allowedExts  = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $ext          = strtolower($file->guessExtension() ?? '');
        $mimeType     = $file->getMimeType() ?? 'image/jpeg';

        if (!in_array($mimeType, $allowedMimes) || !in_array($ext, $allowedExts)) {
            return new JsonResponse(['error' => 'Format non supporté (PDF, JPG, PNG, WEBP)'], 400);
        }

        // Copie temporaire pour analyse
        $tmpPath = sys_get_temp_dir() . '/sakan_invoice_' . uniqid() . '.' . $ext;
        $file->move(dirname($tmpPath), basename($tmpPath));

        try {
            $result = $gemini->analyzeInvoice($tmpPath, $mimeType);
            @unlink($tmpPath);

            return new JsonResponse([
                'success' => true,
                'data'    => $result,
            ]);
        } catch (\Exception $e) {
            @unlink($tmpPath);
            error_log('[LocataireChargesApiController] Gemini: ' . $e->getMessage());
            return new JsonResponse([
                'error'   => 'Analyse impossible: ' . $e->getMessage(),
                'success' => false,
            ], 500);
        }
    }

    // ════════════════════════════════════════════════════════════════════════════════════════════
    // GET paiements d'une charge
    // ════════════════════════════════════════════════════════════════════════════════════════════
    #[Route('/{id}/paiements', name: 'locataire_charge_paiements', methods: ['GET'])]
    public function getPaiements(
        int                    $id,
        ChargeLocataireService $service
    ): JsonResponse {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        // Vérification accès
        if (!$service->getChargeById($id, $locataireId)) {
            return new JsonResponse(['error' => 'Accès non autorisé'], 403);
        }

        $paiements = $service->getPaiementsByCharge($id);

        return new JsonResponse([
            'success'   => true,
            'paiements' => $paiements,
            'count'     => count($paiements),
        ]);
    }
}
