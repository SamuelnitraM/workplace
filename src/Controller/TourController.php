<?php

namespace App\Controller;

use App\Tour\TourCatalog;
use App\Tour\TourLauncher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Tutorial: the list of the guided tours, each one started on the page of its feature. */
#[IsGranted('ROLE_USER')]
class TourController extends AbstractController
{
    #[Route('/didacticiel', name: 'app_tour_index', methods: ['GET'])]
    public function index(TourCatalog $catalog, TourLauncher $launcher): Response
    {
        $tours = array_map(
            static fn ($tour): array => ['tour' => $tour, 'url' => $launcher->urlOf($tour)],
            array_values($catalog->all()),
        );
        return $this->render('tour/index.html.twig', ['tours' => $tours]);
    }
}
