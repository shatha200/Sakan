<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Service\LoyerLocataireService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * poru commit work
 * LocataireLoYerApiController — API AJAX pour le module "Mes Loyers" locataire.
 *
 * FLUX MÉTIER (identique à l'app Java) :
 *   - "Payer maintenant" → Stripe Checkout (paiement par carte en ligne)
 *   - Callback Stripe success → UPDATE statut='PAYE' + email proprio + email locataire
 *   - PAS d'upload de preuve (contrairement aux Charges)
 *
 * Routes :
 *   POST /locataire/loyers/{id}/stripe/checkout → createCheckout
 *   GET  /locataire/loyers/{id}/stripe/success  → stripeSuccess (callback)
 *   GET  /locataire/loyers/{id}/stripe/cancel   → stripeCancel
 *   POST /locataire/loyers/{id}/payer-direct    → payerDirect (hors-ligne, optionnel)
 *   GET  /locataire/loyers/{id}/quittance       → viewQuittance
 *   GET  /locataire/loyers/{id}/info            → getLoyerInfo (AJAX)
 */
#[Route('/locataire/loyers')]
class LocataireLoYerApiController extends AbstractController
{
    private function getLocataireId(): ?int
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return null;
        }
        return (int) $user->getId();
    }

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

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Non autorisé. Veuillez vous connecter.'], 401);
    }

    // ─────────────────────────────────────────────────────────────────
    // STRIPE CHECKOUT — Créer session
    // Identique à: stripeService.createCheckoutSession(...) en Java
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/stripe/checkout', name: 'locataire_loyer_stripe_checkout', methods: ['POST'])]
    public function createCheckout(
        int                    $id,
        Request                $request,
        LoyerLocataireService  $service
    ): JsonResponse {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        // Récupérer le loyer (avec vérification sécurité)
        $loyer = $service->getById($id, $locataireId);
        if (!$loyer) {
            return new JsonResponse(['error' => 'Loyer introuvable ou accès non autorisé'], 403);
        }

        // Vérifier que le loyer n'est pas déjà payé
        if ($loyer['statut'] === 'PAYE') {
            return new JsonResponse(['error' => 'Ce loyer est déjà payé.'], 400);
        }

        $montant     = (float)$loyer['total'];
        $periode     = ($parsed = $this->parsePeriode($loyer['periode'] ?? null))
            ? $parsed->format('F Y')
            : ($loyer['periode'] ?? 'N/A');
        $description = 'Loyer ' . $periode
            . ($loyer['penalite'] > 0 ? ' (incl. pénalité)' : '');

        $baseUrl    = $request->getSchemeAndHttpHost();
        $successUrl = $baseUrl . $this->generateUrl('locataire_loyer_stripe_success', ['id' => $id])
            . '?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl  = $baseUrl . $this->generateUrl('locataire_loyer_stripe_cancel',  ['id' => $id]);

        $result = $service->createStripeCheckoutSession(
            $id,
            $locataireId,
            $montant,
            $description,
            $successUrl,
            $cancelUrl
        );

        if (isset($result['error'])) {
            return new JsonResponse(['error' => $result['error']], 400);
        }

        return new JsonResponse([
            'success'      => true,
            'checkout_url' => $result['checkout_url'],
            'session_id'   => $result['session_id'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // STRIPE SUCCESS — Callback après paiement réussi
    // Identique à: onPaymentSuccess(transactionId) en Java
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/stripe/success', name: 'locataire_loyer_stripe_success', methods: ['GET'])]
    public function stripeSuccess(
        int                    $id,
        Request                $request,
        LoyerLocataireService  $service
    ): Response {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) {
            return $this->redirectToRoute('tenant_finances_loyers');
        }

        $sessionId = $request->query->get('session_id', '');
        $success   = false;

        if ($sessionId) {
            // 1. Vérifier la session Stripe (payment_status == paid)
            $session = $service->retrieveStripeSession((string)$sessionId);

            if ($session && ($session['payment_status'] ?? '') === 'paid') {
                // 2. Récupérer le loyer avant update (pour email)
                $loyer = $service->getById($id, $locataireId);

                if ($loyer && $loyer['statut'] !== 'PAYE') {
                    // 3. UPDATE statut='PAYE' + référence Stripe (comme Java: loyerService.payerLoyer)
                    $success = $service->payerLoyer(
                        $id,
                        $locataireId,
                        'CARTE',
                        (string)$sessionId
                    );

                    if ($success) {
                        // 4. Loyer rechargé avec données à jour
                        $loyerPaye = $service->getById($id, $locataireId);

                        // 5. Email au propriétaire (comme Java: emailService.notifierPaiementLoyer)
                        $proprietaire = $service->getProprietaireByContrat((int)$loyer['contrat_id']);
                        $locataire    = $service->getLocataireInfo($locataireId);

                        if ($proprietaire && $locataire) {
                            $service->sendPaiementLoyerEmail($loyerPaye ?? $loyer, $locataire, $proprietaire);
                        }

                        // 6. Email de confirmation au locataire
                        $quittanceUrl = $service->getQuittanceUrl($id);
                        if ($locataire) {
                            $service->sendConfirmationLocataireEmail(
                                $loyerPaye ?? $loyer,
                                $locataire,
                                $quittanceUrl
                            );
                        }
                    }
                } elseif ($loyer && $loyer['statut'] === 'PAYE') {
                    $success = true; // Déjà traité (idempotent)
                }
            }
        }

        $this->addFlash(
            $success ? 'success' : 'error',
            $success
                ? '✅ Paiement Stripe confirmé ! Un email de confirmation a été envoyé.'
                : '❌ Impossible de confirmer le paiement. Contactez le support.'
        );

        return $this->redirectToRoute('tenant_finances_loyers', [
            'logement'       => $this->getLoyerContratId($id, $service, $locataireId),
            'paiement_id'    => $id,
            'stripe_success' => $success ? '1' : '0',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // STRIPE CANCEL — Utilisateur a annulé le paiement
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/stripe/cancel', name: 'locataire_loyer_stripe_cancel', methods: ['GET'])]
    public function stripeCancel(int $id, LoyerLocataireService $service): Response
    {
        $locataireId = $this->getLocataireId();
        $this->addFlash('info', '⚠️ Paiement annulé. Vous pouvez réessayer quand vous voulez.');

        return $this->redirectToRoute('tenant_finances_loyers', [
            'logement' => $this->getLoyerContratId($id, $service, $locataireId ?? 0),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // PAYER HORS-LIGNE (optionnel — fallback sans Stripe)
    // Pour les cas où Stripe n'est pas disponible
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/payer-direct', name: 'locataire_loyer_payer_direct', methods: ['POST'])]
    public function payerDirect(
        int                    $id,
        Request                $request,
        LoyerLocataireService  $service
    ): JsonResponse {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        $loyer = $service->getById($id, $locataireId);
        if (!$loyer) {
            return new JsonResponse(['error' => 'Loyer introuvable ou accès non autorisé'], 403);
        }

        if ($loyer['statut'] === 'PAYE') {
            return new JsonResponse(['error' => 'Loyer déjà payé'], 400);
        }

        $methode   = $request->request->get('methode', 'VIREMENT');
        $reference = $request->request->get('reference', '');

        $ok = $service->payerLoyer($id, $locataireId, (string)$methode, (string)$reference);

        if ($ok) {
            $locataire   = $service->getLocataireInfo($locataireId);
            $proprietaire = $service->getProprietaireByContrat((int)$loyer['contrat_id']);
            $loyerPaye   = $service->getById($id, $locataireId);

            if ($proprietaire && $locataire && $loyerPaye) {
                $service->sendPaiementLoyerEmail($loyerPaye, $locataire, $proprietaire);
                $service->sendConfirmationLocataireEmail($loyerPaye, $locataire, null);
            }
        }

        return new JsonResponse([
            'success' => $ok,
            'message' => $ok ? 'Loyer marqué comme payé.' : 'Erreur lors de l\'enregistrement.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // VOIR QUITTANCE — Redirige vers l'URL Supabase/locale
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/quittance', name: 'locataire_loyer_quittance', methods: ['GET'])]
    public function viewQuittance(int $id, LoyerLocataireService $service): Response
    {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) {
            return $this->redirectToRoute('tenant_finances_loyers');
        }

        $loyer = $service->getById($id, $locataireId);
        if (!$loyer || $loyer['statut'] !== 'PAYE') {
            $this->addFlash('error', 'Loyer non payé ou introuvable.');
            return $this->redirectToRoute('tenant_finances_loyers');
        }

        $url = $service->getQuittanceUrl($id);
        if (!$url) {
            $this->addFlash('info', 'Quittance non encore disponible.');
            return $this->redirectToRoute('tenant_finances_loyers');
        }

        // Si c'est une URL externe (Supabase), rediriger directement
        if (str_starts_with($url, 'http')) {
            return $this->redirect($url);
        }

        // Sinon, proxy local
        return $this->redirect('/uploads/factures/' . basename($url));
    }

    // ─────────────────────────────────────────────────────────────────
    // INFO LOYER — Retourne les détails JSON (AJAX pour modal)
    // ─────────────────────────────────────────────────────────────────
    #[Route('/{id}/info', name: 'locataire_loyer_info', methods: ['GET'])]
    public function getLoyerInfo(int $id, LoyerLocataireService $service): JsonResponse
    {
        $locataireId = $this->getLocataireId();
        if (!$locataireId) return $this->unauthorized();

        $loyer = $service->getById($id, $locataireId);
        if (!$loyer) {
            return new JsonResponse(['error' => 'Loyer introuvable'], 404);
        }

        return new JsonResponse([
            'success' => true,
            'loyer'   => [
                'id'                  => $loyer['id'],
                'statut'              => $loyer['statut'],
                'montant'             => $loyer['montant'],
                'penalite'            => $loyer['penalite'],
                'total'               => $loyer['total'],
                'periode'             => isset($loyer['periode'])
                    ? (new \DateTime($loyer['periode']))->format('F Y')
                    : 'N/A',
                'date_echeance'       => $loyer['date_echeance']
                    ? (new \DateTime($loyer['date_echeance']))->format('d/m/Y')
                    : null,
                'date_paiement'       => $loyer['date_paiement']
                    ? (new \DateTime($loyer['date_paiement']))->format('d/m/Y H:i')
                    : null,
                'methode_paiement'    => $loyer['methode_paiement'],
                'reference_transaction' => $loyer['reference_transaction'],
                'quittance_url'       => $service->getQuittanceUrl((int)$loyer['id']),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    private function getLoyerContratId(int $loyerId, LoyerLocataireService $service, int $locataireId): int
    {
        $loyer = $service->getById($loyerId, $locataireId);
        return $loyer ? (int)$loyer['contrat_id'] : 0;
    }
}
