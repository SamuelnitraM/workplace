<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\GroupRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/search', name: 'app_search_api')]
class SearchController extends AbstractController
{
    #[Route('/api', methods: ['GET'])]
    public function search(Request $request, UserRepository $userRepository, GroupRepository $groupRepository): JsonResponse
    {
        $query = $request->query->get('q', '');

        if (strlen($query) < 2) {
            return new JsonResponse([
                'users' => [],
                'groups' => []
            ]);
        }

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
