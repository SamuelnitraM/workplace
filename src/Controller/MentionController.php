<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Member suggestions while typing "@pseudo" in any text field of the site
 * (forum editor, private messages, group chat: Stimulus controller "mention-suggest").
 */
#[IsGranted('ROLE_USER')]
final class MentionController extends AbstractController
{
    #[Route('/mentions', name: 'app_mention_suggestions', methods: ['GET'])]
    public function suggestions(Request $request, UserRepository $userRepository): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        if ($query === '' || !preg_match('/^[A-Za-z0-9_.-]{1,50}$/D', $query)) {
            return new JsonResponse(['users' => []]);
        }
        $users = array_map(static fn (User $user): array => [
            'username' => $user->getUsername(),
            'avatar' => $user->getAvatar() ? '/uploads/avatars/' . $user->getAvatar() : null,
        ], $userRepository->findMentionSuggestions($query));
        return new JsonResponse(['users' => $users]);
    }
}
