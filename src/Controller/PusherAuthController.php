<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\GroupChannelRepository;
use App\Repository\PrivateConversationRepository;
use App\Security\Voter\ConversationVoter;
use App\Security\Voter\GroupChannelVoter;
use App\Service\PusherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Autorise l'abonnement aux canaux privés Pusher (private-*).
 * Sans cet endpoint, n'importe qui disposant de la clé publique pourrait écouter
 * les conversations privées et les salons de groupe.
 */
#[IsGranted('ROLE_USER')]
class PusherAuthController extends AbstractController
{
    #[Route('/pusher/auth', name: 'app_pusher_auth', methods: ['POST'])]
    public function auth(
        Request $request,
        #[CurrentUser] User $user,
        PusherService $pusher,
        PrivateConversationRepository $conversationRepository,
        GroupChannelRepository $groupChannelRepository,
    ): Response {
        $socketId = (string) $request->request->get('socket_id', '');
        $channelName = (string) $request->request->get('channel_name', '');

        if (!preg_match('/^\d+\.\d+$/', $socketId) || !$this->canSubscribe(
            $user, $channelName, $conversationRepository, $groupChannelRepository
        )) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        return new Response($pusher->authorizeChannel($channelName, $socketId), 200, [
            'Content-Type' => 'application/json',
        ]);
    }

    private function canSubscribe(
        User $user,
        string $channelName,
        PrivateConversationRepository $conversationRepository,
        GroupChannelRepository $groupChannelRepository,
    ): bool {
        if (preg_match('/^private-user-(\d+)$/', $channelName, $m)) {
            return (int) $m[1] === $user->getId();
        }

        if (preg_match('/^private-conversation-(\d+)$/', $channelName, $m)) {
            $conversation = $conversationRepository->find((int) $m[1]);

            return $conversation !== null && $this->isGranted(ConversationVoter::VIEW, $conversation);
        }

        if (preg_match('/^private-group-channel-(\d+)$/', $channelName, $m)) {
            $channel = $groupChannelRepository->find((int) $m[1]);

            return $channel !== null && $this->isGranted(GroupChannelVoter::READ, $channel);
        }

        return false;
    }
}
