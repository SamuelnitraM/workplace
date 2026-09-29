<?php

namespace App\Controller;

use App\Entity\User;
use App\Feed\FeedService;
use App\Gamification\BadgeCatalog;
use App\Repository\CategoryRepository;
use App\Repository\GalleryPhotoRepository;
use App\Repository\PostRepository;
use App\Repository\ThreadRepository;
use App\Repository\UserRepository;
use App\Service\OnboardingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /** Below this number of members, the counters are hidden (unflattering social proof). */
    public const SOCIAL_PROOF_MIN_MEMBERS = 100;

    /** Photos of the « latest creations of the community » carousel. */
    public const SHOWCASE_PHOTOS = 10;

    /** Trends carousel: most liked photos of the last days (completed with the most liked of all time). */
    public const TRENDING_PHOTOS = 10;
    public const TRENDING_PERIOD = '-7 days';

    /** Forum posts of the « latest activity » block. */
    private const LATEST_POSTS = 5;

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

        // Statistics: shown only once the community is large enough
        $membersCount = $userRepository->count([]);
        $stats = null;
        if ($membersCount >= self::SOCIAL_PROOF_MIN_MEMBERS) {
            $stats = [
                'Membres' => $membersCount,
                'Sujets' => $threadRepository->count([]),
                // Replies = forum posts except the first post of each thread
                'Réponses' => $postRepository->count(['isFirst' => false]),
            ];
        }

        // Carousels: latest visible photos and trends (owner joined) + aggregated counters
        $showcasePhotos = $galleryPhotoRepository->findLatestVisible(self::SHOWCASE_PHOTOS);
        $trendingPhotos = array_column($galleryPhotoRepository->findTrending(new \DateTimeImmutable(self::TRENDING_PERIOD), self::TRENDING_PHOTOS), 'photo');
        $photoStats = $galleryPhotoRepository->getStatsForPhotos(array_merge($showcasePhotos, $trendingPhotos));

        // Root categories only
        $categories = $categoryRepository->findBy(['parent' => null], ['position' => 'ASC']);

        // Latest activity: the 5 latest forum posts, with their author and thread (single query)
        $latestPosts = $postRepository->createQueryBuilder('p')
            ->addSelect('a', 't')
            ->innerJoin('p.author', 'a')
            ->innerJoin('p.thread', 't')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(self::LATEST_POSTS)
            ->getQuery()
            ->getResult();

        // Personal space (signed-in member)
        $userDashboard = null;
        if ($user instanceof User) {
            // News feed: « all » by default, « community » while the member has neither friend nor group
            $hasNetwork = $feed->hasNetwork($user);
            $filter = FeedService::normalizeFilter($request->query->getString('fil'))
                ?? ($hasNetwork ? FeedService::DEFAULT_FILTER : 'communaute');

            $userDashboard = [
                'myThreads' => $threadRepository->findBy(['author' => $user], ['createdAt' => 'DESC'], 3),
                // « First steps » (one query): hidden once everything is checked
                'checklist' => $onboarding->checklist($user),
                'feed' => $feed->page($user, $filter),
                'hasNetwork' => $hasNetwork,
            ];
        }

        return $this->render('home/index.html.twig', [
            'stats' => $stats,
            'earlyMemberLimit' => BadgeCatalog::EARLY_MEMBER_LIMIT,
            'showcasePhotos' => $showcasePhotos,
            'trendingPhotos' => $trendingPhotos,
            'photoStats' => $photoStats,
            'categories' => $categories,
            'latestPosts' => $latestPosts,
            'userDashboard' => $userDashboard,
            'feedFilters' => FeedService::FILTERS,
        ]);
    }
}
