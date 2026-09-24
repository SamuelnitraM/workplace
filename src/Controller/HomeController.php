<?php

namespace App\Controller;

use App\Entity\User;
use App\Feed\FeedService;
use Symfony\Component\HttpFoundation\Request;
use App\Gamification\BadgeCatalog;
use App\Repository\CategoryRepository;
use App\Repository\GalleryPhotoRepository;
use App\Repository\PostRepository;
use App\Repository\ThreadRepository;
use App\Repository\UserRepository;
use App\Service\OnboardingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /** En dessous de ce nombre de membres, les compteurs sont masqués (preuve sociale peu flatteuse). */
    public const SOCIAL_PROOF_MIN_MEMBERS = 100;

    /** Nombre de photos affichées dans la vitrine « Dernières créations de la communauté ». */
    public const SHOWCASE_PHOTOS = 12;

    /** Trends: most liked photos of the last days. */
    public const TRENDING_PHOTOS = 6;
    public const TRENDING_PERIOD = '-7 days';

    #[Route('/', name: 'app_home')]
    public function index(
        CategoryRepository $categoryRepository,
        UserRepository $userRepository,
        PostRepository $postRepository,
        ThreadRepository $threadRepository,
        GalleryPhotoRepository $galleryPhotoRepository,
        OnboardingService $onboarding,
        FeedService $feed,
        Request $request,
    ): Response {
        $user = $this->getUser();

        // Statistiques : affichées seulement une fois la communauté suffisamment grande
        $membersCount = $userRepository->count([]);
        $stats = null;
        if ($membersCount >= self::SOCIAL_PROOF_MIN_MEMBERS) {
            $stats = [
                'Membres' => $membersCount,
                'Sujets' => $threadRepository->count([]),
                // Réponses = messages du forum hors premier message de chaque sujet
                'Réponses' => $postRepository->count(['isFirst' => false]),
            ];
        }

        // Vitrine : dernières photos visibles (propriétaire joint) + compteurs agrégés
        $showcasePhotos = $galleryPhotoRepository->findLatestVisible(self::SHOWCASE_PHOTOS);
        $showcaseStats = $galleryPhotoRepository->getStatsForPhotos($showcasePhotos);
        $trendingPhotos = $galleryPhotoRepository->findMostLikedSince(new \DateTimeImmutable(self::TRENDING_PERIOD), self::TRENDING_PHOTOS);

        // Catégories racines uniquement
        $categories = $categoryRepository->findBy(['parent' => null], ['position' => 'ASC']);

        // Dernières activités : les 5 derniers messages du forum
        $latestPosts = $postRepository->findBy([], ['createdAt' => 'DESC'], 5);

        // Espace personnalisé (si connecté)
        $userDashboard = null;
        if ($user instanceof User) {
            // Fil d'actualité : « tout » par défaut, « communauté » tant que le membre n'a ni ami ni groupe
            $hasNetwork = $feed->hasNetwork($user);
            $filter = FeedService::normalizeFilter($request->query->getString('fil'))
                ?? ($hasNetwork ? FeedService::DEFAULT_FILTER : 'communaute');

            $userDashboard = [
                'myThreads' => $threadRepository->findBy(['author' => $user], ['createdAt' => 'DESC'], 3),
                // « Premiers pas » (une requête) : masqué une fois tout coché
                'checklist' => $onboarding->checklist($user),
                'feed' => $feed->page($user, $filter),
                'hasNetwork' => $hasNetwork,
            ];
        }

        return $this->render('home/index.html.twig', [
            'stats' => $stats,
            'earlyMemberLimit' => BadgeCatalog::EARLY_MEMBER_LIMIT,
            'showcasePhotos' => $showcasePhotos,
            'showcaseStats' => $showcaseStats,
            'trendingPhotos' => $trendingPhotos,
            'categories' => $categories,
            'latestPosts' => $latestPosts,
            'userDashboard' => $userDashboard,
            'feedFilters' => FeedService::FILTERS,
        ]);
    }
}
