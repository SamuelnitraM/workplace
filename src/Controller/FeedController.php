<?php

namespace App\Controller;

use App\Entity\User;
use App\Feed\FeedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pages suivantes du fil d'actualité (bouton « Voir plus » de l'accueil, chargé dans un Turbo Frame).
 * La première page est rendue par HomeController.
 */
final class FeedController extends AbstractController
{
    #[Route('/fil', name: 'app_feed_page', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function page(Request $request, FeedService $feed): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $filter = FeedService::normalizeFilter($request->query->getString('fil')) ?? FeedService::DEFAULT_FILTER;
        $cursor = $request->query->getString('apres') ?: null;

        return $this->render('feed/page.html.twig', [
            'feed' => $feed->page($user, $filter, $cursor),
            'cursor' => $cursor,
            // Sans Turbo (lien ouvert directement) : page complète
            'standalone' => !$request->headers->has('Turbo-Frame'),
        ]);
    }
}
