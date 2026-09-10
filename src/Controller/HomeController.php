<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\PostRepository;
use App\Repository\ThreadRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        CategoryRepository $categoryRepository,
        UserRepository $userRepository,
        PostRepository $postRepository,
        ThreadRepository $threadRepository
    ): Response {
        $user = $this->getUser();

        // Idée 2: Statistiques
        $stats = [
            'users_count' => $userRepository->count([]),
            'threads_count' => $threadRepository->count([]),
            'posts_count' => $postRepository->count([]),
        ];

        // Idée 3: Catégories
        $categories = $categoryRepository->findBy([], ['position' => 'ASC']);

        // Idée 4: Dernières activités
        // On récupère les 5 derniers posts
        $latestPosts = $postRepository->findBy([], ['createdAt' => 'DESC'], 5);

        // Idée 5: Espace personnalisé (si connecté)
        $userDashboard = null;
        if ($user) {
            // Exemple: threads créés par l'utilisateur
            $userThreads = $threadRepository->findBy(['author' => $user], ['createdAt' => 'DESC'], 3);
            $userDashboard = [
                'myThreads' => $userThreads,
            ];
        }

        return $this->render('home/index.html.twig', [
            'stats' => $stats,
            'categories' => $categories,
            'latestPosts' => $latestPosts,
            'userDashboard' => $userDashboard,
        ]);
    }
}
