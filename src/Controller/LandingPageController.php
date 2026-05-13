<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use App\Repository\AnnonceRepository;

class LandingPageController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(AnnonceRepository $annonceRepository): Response
    {
        $annonces = $annonceRepository->findBy(
            ['statut' => 'DISPONIBLE'],
            ['id' => 'DESC'],
            4
        );
        
        return $this->render('landing/index.html.twig', [
            'annonces' => $annonces,
        ]);
    }
}
