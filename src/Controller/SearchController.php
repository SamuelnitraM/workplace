<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\GroupRepository;
use App\Repository\ThreadRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Site search of the header (templates/_partials/shell/_search.html.twig): members, public groups,
 * forum categories and subcategories, forum threads.
 */
#[Route('/search', name: 'app_search_')]
class SearchController extends AbstractController
{
    private const MIN_QUERY_LENGTH = 2;
    private const MAX_QUERY_LENGTH = 100;
    private const FORUM_RESULTS = 8;

    #[Route('/api', name: 'api', methods: ['GET'])]
    public function search(
        Request $request,
        UserRepository $userRepository,
        GroupRepository $groupRepository,
        CategoryRepository $categoryRepository,
        ThreadRepository $threadRepository,
    ): JsonResponse {
        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return new JsonResponse(['users' => [], 'groups' => [], 'categories' => [], 'threads' => []]);
        }
        $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);
        $users = [];
        foreach ($userRepository->searchByUsername($query) as $user) {
            $users[] = [
                'username' => $user->getUsername(),
                'avatar' => $user->getAvatar() ? '/uploads/avatars/' . $user->getAvatar() : null,
                'url' => $this->generateUrl('app_profil_show', ['username' => $user->getUsername()]),
            ];
        }
        $groups = [];
        foreach ($groupRepository->searchByName($query) as $group) {
            $groups[] = [
                'name' => $group->getName(),
                'url' => $this->generateUrl('app_group_show', ['slug' => $group->getSlug()]),
            ];
        }
        $categories = [];
        foreach ($categoryRepository->searchByName($query, self::FORUM_RESULTS) as $category) {
            $categories[] = [
                'name' => $category->getName(),
                'context' => $category->getParent()?->getName(),
                'url' => $this->generateUrl('app_forum_category', ['slug' => $category->getSlug()]),
            ];
        }
        $threads = [];
        foreach ($threadRepository->searchByTitle($query, self::FORUM_RESULTS) as $thread) {
            $threads[] = [
                'title' => $thread->getTitle(),
                'context' => $thread->getCategory()?->getName(),
                'url' => $this->generateUrl('app_thread_show', ['slug' => $thread->getSlug()]),
            ];
        }
        return new JsonResponse(['users' => $users, 'groups' => $groups, 'categories' => $categories, 'threads' => $threads]);
    }
}
