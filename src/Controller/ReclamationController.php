<?php

namespace App\Controller;

use App\Entity\Reclamation;
use App\Entity\ReclamationImage;
use App\Form\ReclamationFormType;
use App\Repository\ReclamationRepository;
use App\Repository\ReclamationTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Knp\Component\Pager\PaginatorInterface;
use App\Service\SightengineService;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/locataire/reclamations')]
class ReclamationController extends AbstractController
{
    private \Symfony\Component\Mailer\MailerInterface $mailer;
    private SightengineService $sightengine;

    public function __construct(SightengineService $sightengine, \Symfony\Component\Mailer\MailerInterface $mailer)
    {
        $this->sightengine = $sightengine;
        $this->mailer = $mailer;
    }
    #[Route('/', name: 'tenant_reclamations', methods: ['GET'])]
    public function index(Request $request, ReclamationRepository $reclamationRepository, ReclamationTypeRepository $typeRepository, PaginatorInterface $paginator, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $search = trim((string) $request->query->get('q', ''));
        $type = trim((string) $request->query->get('type', ''));
        $status = trim((string) $request->query->get('status', ''));

        $query = $reclamationRepository->getFilteredReclamationsQuery($user->getId(), $search, $type, $status);
        $allResults = $query->getResult();
        
        $reclamations = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            5
        );
        
        $stats = [
            'total' => count($allResults),
            'en_attente' => count(array_filter($allResults, fn($r) => $r->getStatut() === 'EN_ATTENTE')),
            'en_cours' => count(array_filter($allResults, fn($r) => $r->getStatut() === 'EN_COURS')),
            'resolus' => count(array_filter($allResults, fn($r) => $r->getStatut() === 'RESOLU')),
        ];

        $hasActiveContracts = !empty($entityManager->getRepository(\App\Entity\Contrat::class)->findBy(['locataire' => $user]));

        return $this->render('reclamation/index.html.twig', [
            'reclamations' => $reclamations,
            'stats' => $stats,
            'types' => $typeRepository->findAll(),
            'currentSearch' => $search,
            'currentType' => $type,
            'currentStatus' => $status,
            'hasActiveContracts' => $hasActiveContracts,
        ]);
    }

    #[Route('/new', name: 'tenant_reclamation_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\Utilisateur) {
            return $this->redirectToRoute('app_login');
        }

        $reclamation = new Reclamation();
        $reclamation->setLocataireId((int) $user->getId());
        
        // Find the active contracts for this tenant to allow selection
        $contrats = $entityManager->getRepository(\App\Entity\Contrat::class)->findBy(['locataire' => $user]);
        
        if (empty($contrats)) {
            $this->addFlash('error', 'Vous devez avoir au moins un contrat actif pour soumettre une réclamation.');
            return $this->redirectToRoute('tenant_reclamations');
        }

        $form = $this->createForm(ReclamationFormType::class, $reclamation, [
            'contrats' => $contrats,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $description = (string) $reclamation->getDescription();
            
            // Text Moderation
            if (!$this->sightengine->isTextSafe($description)) {
                $form->get('description')->addError(new FormError('Contenu inapproprié, veuillez réessayer.'));
                return $this->render('reclamation/new.html.twig', [
                    'reclamation' => $reclamation,
                    'form' => $form->createView(),
                ]);
            }

            $isAutre = $form->get('isAutre')->getData();
            if ($isAutre) {
                $reclamation->setTypeAutre($form->get('typeAutre')->getData());
            }

            // Handle Multi-Images
            $imageFiles = $form->get('images')->getData();
            if ($imageFiles) {
                foreach ($imageFiles as $imageFile) {
                    $image = new ReclamationImage();
                    $content = file_get_contents($imageFile->getPathname());
                    if (is_string($content)) {
                        $image->setImageData($content);
                    }
                    $image->setFileName($imageFile->getClientOriginalName());
                    $image->setCreatedAt(new \DateTimeImmutable());
                    $reclamation->addImage($image);
                }
            }

            $entityManager->persist($reclamation);
            $entityManager->flush();

            // Email Notification to the specific Owner via Symfony Mailer
            try {
                $targetOwner = null;
                if ($reclamation->getContratId()) {
                    $contrat = $entityManager->getRepository(\App\Entity\Contrat::class)->find($reclamation->getContratId());
                    if ($contrat && $contrat->getAnnonce() && $contrat->getAnnonce()->getProprietaire()) {
                        $targetOwner = $contrat->getAnnonce()->getProprietaire();
                    }
                }
                
                if ($targetOwner && $targetOwner->getEmail()) {
                    $email = (new \Symfony\Component\Mime\Email())
                        ->from('sakan@esprit.tn')
                        ->to($targetOwner->getEmail())
                        ->subject("🔔 Nouvelle réclamation")
                        ->html(sprintf(
                            "<h1>Nouvelle réclamation reçue</h1>
                            <p>Le locataire <strong>%s</strong> a soumis une nouvelle réclamation.</p>
                            <p><strong>Description :</strong> %s</p>
                            <p><a href='http://127.0.0.1:8000/proprietaire/reclamations/show/%d'>Voir le détail dans le tableau de bord</a></p>",
                            $user->getNom(),
                            nl2br((string) $reclamation->getDescription()),
                            $reclamation->getId()
                        ));
                    $this->mailer->send($email);
                }
            } catch (\Exception $e) {
                error_log("Mailer Error: " . $e->getMessage());
            }

            $this->addFlash('success', 'Votre réclamation a été soumise avec succès.');
            return $this->redirectToRoute('tenant_reclamations');
        }

        return $this->render('reclamation/new.html.twig', [
            'reclamation' => $reclamation,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'tenant_reclamation_show', methods: ['GET'])]
    public function show(Reclamation $reclamation): Response
    {
        return $this->render('reclamation/show.html.twig', [
            'reclamation' => $reclamation,
            'contrat' => $reclamation->getContrat(),
        ]);
    }

    #[Route('/{id}/edit', name: 'tenant_reclamation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Reclamation $reclamation, EntityManagerInterface $entityManager): Response
    {
        if ($reclamation->getStatut() === 'RESOLU') {
            $this->addFlash('error', 'Vous ne pouvez pas modifier une réclamation déjà résolue.');
            return $this->redirectToRoute('tenant_reclamations');
        }

        $user = $this->getUser();
        $contrats = $entityManager->getRepository(\App\Entity\Contrat::class)->findBy(['locataire' => $user]);

        $form = $this->createForm(ReclamationFormType::class, $reclamation, [
            'contrats' => $contrats,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $description = (string) $reclamation->getDescription();

            // Text Moderation
            if (!$this->sightengine->isTextSafe($description)) {
                $form->get('description')->addError(new FormError('Contenu inapproprié, veuillez réessayer.'));
                return $this->render('reclamation/edit.html.twig', [
                    'reclamation' => $reclamation,
                    'form' => $form->createView(),
                ]);
            }

            $isAutre = $form->get('isAutre')->getData();
            if ($isAutre) {
                $reclamation->setTypeAutre($form->get('typeAutre')->getData());
            }

            $entityManager->flush();

            $this->addFlash('success', 'Modification enregistrée.');
            return $this->redirectToRoute('tenant_reclamations');
        }

        return $this->render('reclamation/edit.html.twig', [
            'reclamation' => $reclamation,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'tenant_reclamation_delete', methods: ['POST'])]
    public function delete(Request $request, Reclamation $reclamation, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$reclamation->getId(), $request->get('_token'))) {
            $entityManager->remove($reclamation);
            $entityManager->flush();
            $this->addFlash('success', 'Réclamation supprimée.');
        }

        return $this->redirectToRoute('tenant_reclamations');
    }
}
