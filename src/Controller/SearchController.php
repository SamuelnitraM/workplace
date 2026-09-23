<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\GroupRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/search', name: 'app_search_')]
class SearchController extends AbstractController
{
    #[Route('/api', name: 'api', methods: ['GET'])]
    public function search(Request $request, UserRepository $userRepository, GroupRepository $groupRepository): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));

        if (mb_strlen($query) < 2) {
            return new JsonResponse([
                'users' => [],
                'groups' => []
            ]);
        }

        $query = mb_substr($query, 0, 100);
        $users = $userRepository->searchByUsername($query);
        $groups = $groupRepository->searchByName($query);

        $userData = [];
        foreach ($users as $user) {
            $userData[] = [
                'username' => $user->getUsername(),
                'avatar' => $user->getAvatar() ? '/uploads/avatars/' . $user->getAvatar() : null,
                'url' => $this->generateUrl('app_profil_show', ['username' => $user->getUsername()]),
                'type' => 'user'
            ];
        }

        $groupData = [];
        foreach ($groups as $group) {
            $groupData[] = [
                'name' => $group->getName(),
                'url' => $this->generateUrl('app_group_show', ['slug' => $group->getSlug()]),
                'type' => 'group'
            ];
        }

        return new JsonResponse([
            'users' => $userData,
            'groups' => $groupData
        ]);
    }
}
