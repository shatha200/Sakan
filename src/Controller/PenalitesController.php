<?php

namespace App\Controller;

use App\Entity\ReglePenalite;
use App\Entity\Utilisateur;
use App\Repository\PaiementLoyerRepository;
use App\Repository\ReglePenaliteRepository;
use App\Service\PenaliteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/proprietaire/penalites')]
class PenalitesController extends AbstractController
{
    public function __construct(
        private PaiementLoyerRepository $paiementLoyerRepository,
        private ReglePenaliteRepository $reglePenaliteRepository,
        private PenaliteService $penaliteService,
        private EntityManagerInterface $em
    ) {}



    #[Route('/dashboard', name: 'owner_penalites_dashboard')]
    public function dashboard(Request $request): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        
        $proprietaireId = (int) $user->getId();

        // KPIs
        $kpis = [
            'totalRecouvre' => $this->paiementLoyerRepository->getTotalPenalitesRecouvrees($proprietaireId),
            'performanceMois' => $this->paiementLoyerRepository->getTotalPenalitesMois($proprietaireId),
            'retardsIdentifies' => $this->paiementLoyerRepository->getNombreRetardsMois($proprietaireId),
            'topRetardataire' => $this->paiementLoyerRepository->getTopRetardataire($proprietaireId)
        ];

        // Graphiques
        $evolution = $this->paiementLoyerRepository->getEvolutionEncaissements($proprietaireId);
        $repartition = $this->paiementLoyerRepository->getRepartitionParBien($proprietaireId);

        // Règles par bien
        $reglesParBien = $this->reglePenaliteRepository->findReglesParBien($proprietaireId);

        // Filtres pour le journal
        $search = $request->query->get('search', '');
        $dateFrom = $request->query->get('date_from', '');
        $dateTo = $request->query->get('date_to', '');
        $minPenaliteRaw = $request->query->get('min_penalite');
        $maxPenaliteRaw = $request->query->get('max_penalite');
        $minPenalite = ($minPenaliteRaw !== null && $minPenaliteRaw !== '') ? (float)$minPenaliteRaw : null;
        $maxPenalite = ($maxPenaliteRaw !== null && $maxPenaliteRaw !== '') ? (float)$maxPenaliteRaw : null;
        $limit = (int) $request->query->get('limit', 5);
        $page = (int) $request->query->get('page', 1);

        // Récupérer tout l'historique pour filtrage
        $allHistorique = $this->paiementLoyerRepository->getHistoriqueEncaissements($proprietaireId, null, 1, 10000);
        
        // Application des filtres PHP
        $filtered = array_filter($allHistorique, function($item) use ($search, $dateFrom, $dateTo, $minPenalite, $maxPenalite) {
            $searchLower = strtolower((string)$search);
            if ($search && !(
                str_contains(strtolower($item['propriete'] ?? ''), $searchLower) ||
                str_contains(strtolower($item['locataire'] ?? ''), $searchLower) ||
                str_contains(strtolower($item['periode'] ?? ''), $searchLower)
            )) {
                return false;
            }
            if ($dateFrom && ($item['date_paiement'] ?? '') < $dateFrom) {
                return false;
            }
            if ($dateTo && ($item['date_paiement'] ?? '') > $dateTo) {
                return false;
            }
            $penalite = (float)($item['penalite'] ?? 0);
            if ($minPenalite !== null && $penalite < $minPenalite) {
                return false;
            }
            if ($maxPenalite !== null && $penalite > $maxPenalite) {
                return false;
            }
            return true;
        });
        $filtered = array_values($filtered);

        // Pagination avec Pagerfanta
        $adapter = new \Pagerfanta\Adapter\ArrayAdapter($filtered);
        $pagerfanta = new \Pagerfanta\Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($limit);
        $pagerfanta->setCurrentPage($page);

        return $this->render('owner/penalites/dashboard.html.twig', [
            'kpis' => $kpis,
            'evolution' => $evolution,
            'repartition' => $repartition,
            'reglesParBien' => $reglesParBien,
            'historique' => $pagerfanta->getCurrentPageResults(),
            'pager' => $pagerfanta,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'minPenalite' => $minPenaliteRaw,
            'maxPenalite' => $maxPenaliteRaw,
            'limit' => $limit,
            'nbFiltered' => count($filtered)
        ]);
    }

    #[Route('/configuration', name: 'owner_penalites_configuration')]
    public function configuration(): Response
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->redirectToRoute('app_login');
        }
        
        $profils = $this->penaliteService->getAllProfils();
        $regleGlobale = $this->reglePenaliteRepository->findRegleGlobaleActive();

        return $this->render('owner/penalites/configuration.html.twig', [
            'profils' => $profils,
            'regleGlobale' => $regleGlobale
        ]);
    }

    #[Route('/api/simuler', name: 'api_penalites_simuler', methods: ['POST'])]
    public function simulerPenalite(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $loyer = (float) ($data['loyer'] ?? 600);
        $joursRetard = (int) ($data['joursRetard'] ?? 0);
        $profil = $data['profil'] ?? null;

        $resultat = $this->penaliteService->simulerPenalite($loyer, $joursRetard, null, $profil);

        return $this->json($resultat);
    }

    #[Route('/api/sauvegarder-regle', name: 'api_penalites_sauvegarder', methods: ['POST'])]
    public function sauvegarderRegle(Request $request): JsonResponse
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }
        
        $data = json_decode($request->getContent(), true);
        $profil = $data['profil'] ?? 'standard';
        
        // Si config personnalisée fournie (mode expert)
        if (isset($data['config']) && is_array($data['config'])) {
            $config = $data['config'];
            $config['nom'] = 'Configuration Personnalisée';
        } else {
            $config = $this->penaliteService->getProfilConfig($profil);
        }

        // Désactiver l'ancienne règle globale
        $this->reglePenaliteRepository->desactiverReglesGlobales();

        // Créer nouvelle règle
        $regle = new ReglePenalite();
        $regle->setContrat(null);
        $regle->setTypeRegle('RETARD_LOYER');
        $regle->setDelaiGraceJours($config['delaiGrace'] ?? $config['delai_grace'] ?? 5);
        $regle->setPenaliteFixe($config['montantFixe'] ?? $config['montant_fixe'] ?? 10.0);
        $regle->setPenalitePourcentage($config['pourcentage'] ?? $config['penalite_pourcentage'] ?? 2.5);
        $regle->setPlafondPourcentage($config['plafond'] ?? $config['plafond_pourcentage'] ?? 10.0);
        $regle->setActif(true);
        $regle->setDescription($config['nom'] ?? 'Règle personnalisée');

        $this->em->persist($regle);
        $this->em->flush();

        return $this->json(['success' => true, 'regle' => $regle->getId()]);
    }

    #[Route('/api/historique', name: 'api_penalites_historique', methods: ['GET'])]
    public function getHistorique(Request $request): JsonResponse
    {
        /** @var Utilisateur|null $user */
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }
        
        $proprietaireId = (int) $user->getId();
        $page = (int) $request->query->get('page', 1);
        $search = $request->query->get('search');
        $limit = 5;

        $historique = $this->paiementLoyerRepository->getHistoriqueEncaissements($proprietaireId, (string)$search, $page, $limit);
        $total = $this->paiementLoyerRepository->countHistoriqueEncaissements($proprietaireId, (string)$search);

        return $this->json([
            'historique' => $historique,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]);
    }
}
