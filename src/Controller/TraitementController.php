<?php

namespace App\Controller;

use App\Entity\Reclamation;
use App\Entity\ReclamationImage;
use App\Entity\Traitement;
use App\Form\TraitementFormType;
use App\Repository\ReclamationRepository;
use App\Repository\TraitementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

#[Route('/proprietaire/reclamations')]
class TraitementController extends AbstractController
{
    private MailerInterface $mailer;
    private \App\Service\SightengineService $sightengine;

    public function __construct(
        MailerInterface $mailer,
        \App\Service\SightengineService $sightengine
    ) {
        $this->mailer = $mailer;
        $this->sightengine = $sightengine;
    }

    #[Route('/', name: 'owner_reclamations', methods: ['GET'])]
    public function index(Request $request, ReclamationRepository $reclamationRepository, EntityManagerInterface $entityManager, PaginatorInterface $paginator, ChartBuilderInterface $chartBuilder): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $search = trim((string) $request->query->get('q', ''));
        $type = trim((string) $request->query->get('type', ''));
        $status = trim((string) $request->query->get('status', ''));

        $query = $reclamationRepository->getFilteredReclamationsQuery(null, $search, $type, $status, $user->getId());
        $allReclamations = $query->getResult();
        
        $reclamations = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            5
        );
        
        // Fetch users to display names
        $users = $entityManager->getRepository(\App\Entity\Utilisateur::class)->findAll();
        $tenantNames = [];
        foreach ($users as $u) {
            $tenantNames[$u->getId()] = $u->getNom();
        }

        // Fetch types for filter dropdown
        $types = $entityManager->getRepository(\App\Entity\ReclamationType::class)->findAll();

        // Statistics
        $total = count($allReclamations);
        $pending = count(array_filter($allReclamations, fn($r) => $r->getStatut() === 'EN_ATTENTE'));
        $inProgress = count(array_filter($allReclamations, fn($r) => $r->getStatut() === 'EN_COURS'));
        $resolved = count(array_filter($allReclamations, fn($r) => $r->getStatut() === 'RESOLU'));

        // Chart 1: Resolved vs Pending Data
        $chartStatusData = [$resolved, $inProgress, $pending];

        // Chart 2: Reclamations per day Data (last 7 days)
        $days = [];
        $counts = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = (new \DateTime())->modify("-$i days");
            $dateStr = $date->format('Y-m-d');
            $days[] = $date->format('d/m');
            
            $count = 0;
            foreach ($allReclamations as $r) {
                if ($r->getDate() && $r->getDate()->format('Y-m-d') === $dateStr) {
                    $count++;
                }
            }
            $counts[] = $count;
        }




        return $this->render('traitement/index.html.twig', [
            'reclamations' => $reclamations,
            'tenantNames' => $tenantNames,
            'types' => $types,
            'currentSearch' => $search,
            'currentType' => $type,
            'currentStatus' => $status,
            'stats' => [
                'total' => $total,
                'pending' => $pending,
                'inProgress' => $inProgress,
                'resolved' => $resolved,
            ],
            'chartStatusData' => $chartStatusData,
            'chartDays' => $days,
            'chartCounts' => $counts,
        ]);
    }

    #[Route('/show/{id}', name: 'owner_reclamation_show', methods: ['GET'])]
    public function show(Reclamation $reclamation): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('traitement/show.html.twig', [
            'reclamation' => $reclamation,
        ]);
    }

    #[Route('/new/{id}', name: 'owner_reclamation_new', methods: ['GET', 'POST'])]
    public function new(Request $request, Reclamation $reclamation, EntityManagerInterface $entityManager, TraitementRepository $traitementRepository): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // Check if a traitement already exists
        $traitement = $entityManager->getRepository(Traitement::class)->findOneBy(['reclamationId' => $reclamation]);
        
        if (!$traitement) {
            $traitement = new Traitement();
            $traitement->setReclamationId($reclamation);
            $traitement->setDateTraitement(new \DateTime());
        }

        // Mark as being processed if it was pending
        if ($reclamation->getStatut() === 'EN_ATTENTE') {
            $reclamation->setStatut('EN_COURS');
            $entityManager->flush();
        }

        $form = $this->createForm(TraitementFormType::class, $traitement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $responseContent = $form->get('reponse')->getData();

            // AI Text Moderation
            if (!$this->sightengine->isTextSafe($responseContent)) {
                $form->get('reponse')->addError(new FormError('Contenu inapproprié, veuillez réessayer.'));
                return $this->render('traitement/new.html.twig', [
                    'reclamation' => $reclamation,
                    'traitement' => $traitement,
                    'form' => $form->createView(),
                ]);
            }

            $entityManager->persist($traitement);
            $entityManager->flush();

            // Email Notification to Tenant via Symfony Mailer
            try {
                $tenant = $entityManager->getRepository(\App\Entity\Utilisateur::class)->find($reclamation->getLocataireId());
                if ($tenant) {
                    $email = (new \Symfony\Component\Mime\Email())
                        ->from('sakan@esprit.tn')
                        ->to((string)$tenant->getEmail())
                        ->subject("Mise à jour de votre réclamation #" . $reclamation->getId())
                        ->html(sprintf(
                            "<h1>Votre réclamation est en cours de traitement</h1>
                            <p>Bonjour %s,</p>
                            <p>Un nouveau traitement a été ajouté à votre réclamation :</p>
                            <p><strong>Détails :</strong> %s</p>
                            <p>Merci de votre patience.</p>",
                            $tenant->getNom(),
                            nl2br((string)$traitement->getReponse())
                        ));
                    $this->mailer->send($email);
                }
            } catch (\Exception $e) {
                error_log("Mailer Error: " . $e->getMessage());
            }

            $this->addFlash('success', 'Réponse envoyée.');

            return $this->redirectToRoute('owner_reclamations');
        }

        return $this->render('traitement/new.html.twig', [
            'reclamation' => $reclamation,
            'traitement' => $traitement,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/verify-image/{id}', name: 'owner_reclamation_verify_image', methods: ['POST'])]
    public function verifyImage(ReclamationImage $image, EntityManagerInterface $entityManager): Response
    {
        $data = $image->getImageData();
        if (is_resource($data)) {
            rewind($data);
            $data = stream_get_contents($data);
        }
        
        $result = $this->sightengine->detectFakeImage(is_string($data) ? $data : '');
        
        $image->setFakeDetectionResult($result);
        $image->setFakeDetectionDate(new \DateTimeImmutable());
        $entityManager->flush();

        return $this->json(['status' => $result]);
    }



    #[Route('/resolve/{id}', name: 'owner_reclamation_resolve', methods: ['POST'])]
    public function resolve(Reclamation $reclamation, EntityManagerInterface $entityManager, Request $request): Response
    {
        if ($this->isCsrfTokenValid('resolve'.$reclamation->getId(), $request->get('_token'))) {
            $reclamation->setStatut('RESOLU');
            $entityManager->flush();

            // Email Notification to Tenant for RESOLUTION via Symfony Mailer
            try {
                $tenant = $entityManager->getRepository(\App\Entity\Utilisateur::class)->find($reclamation->getLocataireId());
                if ($tenant) {
                    $email = (new \Symfony\Component\Mime\Email())
                        ->from('sakan@esprit.tn')
                        ->to((string)$tenant->getEmail())
                        ->subject("Réclamation résolue #" . $reclamation->getId())
                        ->html(sprintf(
                            "<h1>Votre réclamation a été résolue</h1>
                            <p>Bonjour %s,</p>
                            <p>Bonne nouvelle ! Votre réclamation concernant <strong>%s</strong> a été marquée comme résolue.</p>
                            <p>N'hésitez pas à nous contacter si vous avez d'autres questions.</p>",
                            $tenant->getNom(),
                            $reclamation->getDescription()
                        ));
                    $this->mailer->send($email);
                }
            } catch (\Exception $e) {
                error_log("Mailer Error: " . $e->getMessage());
            }

            $this->addFlash('success', 'La réclamation a été marquée comme résolue.');
        }

        return $this->redirectToRoute('owner_reclamations');
    }
}