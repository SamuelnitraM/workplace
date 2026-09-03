<?php

namespace App\Controller;

use App\Entity\PrivateConversation;
use App\Entity\PrivateMessage;
use App\Repository\FriendshipRepository;
use App\Repository\PrivateConversationRepository;
use App\Repository\UserRepository;
use App\Service\PusherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/messages', name: 'app_message_')]
class PrivateMessageController extends AbstractController
{
        private PusherService $pusher;

    public function __construct(PusherService $pusher)
    {
        $this->pusher = $pusher;
    }

    // ─── Page liste des conversations ─────────────────────────
    #[Route('/', name: 'index')]
    public function index(PrivateConversationRepository $conversationRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $conversations = $conversationRepository->findUserConversations($user);

        return $this->render('private_message/index.html.twig', [
            'conversations' => $conversations,
        ]);
    }

    // ─── Page conversation avec un user ───────────────────────
    #[Route('/{username}', name: 'show')]
    public function show(
        string $username,
        UserRepository $userRepository,
        PrivateConversationRepository $conversationRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$targetUser) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        if ($targetUser === $currentUser) {
            return $this->redirectToRoute('app_message_index');
        }

        $conversation = $conversationRepository->findBetween($currentUser, $targetUser);

        if (!$conversation) {
            $conversation = new PrivateConversation();
            $conversation->setParticipant1($currentUser);
            $conversation->setParticipant2($targetUser);
            $em->persist($conversation);
            $em->flush();
        }

        $this->markAsRead($conversation, $currentUser, $em);

        $messages = $conversation->getMessages()->toArray();
        usort($messages, fn($a, $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        return $this->render('private_message/show.html.twig', [
            'conversation' => $conversation,
            'messages' => $messages,
            'targetUser' => $targetUser,
        ]);
    }

    // ─── Envoyer un message (page dédiée) ─────────────────────
    #[Route('/{username}/send', name: 'send', methods: ['POST'])]
    public function send(
        string $username,
        Request $request,
        UserRepository $userRepository,
        PrivateConversationRepository $conversationRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$targetUser) {
            throw $this->createNotFoundException();
        }

        $conversation = $this->getOrCreateConversation($currentUser, $targetUser, $conversationRepository, $em);

        $content = trim($request->request->get('content', ''));
        if (!empty($content)) {
            $this->createAndPublishMessage($content, $currentUser, $conversation, $em);
        }

        return $this->redirectToRoute('app_message_show', ['username' => $username]);
    }

    // ─── AJAX : liste des conversations ───────────────────────
    #[Route('/ajax/conversations', name: 'ajax_conversations', methods: ['GET'])]
    public function ajaxConversations(PrivateConversationRepository $conversationRepository): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $conversations = $conversationRepository->findUserConversations($user);

        $data = [];
        foreach ($conversations as $conversation) {
            $other = $conversation->getParticipant1() === $user
                ? $conversation->getParticipant2()
                : $conversation->getParticipant1();

            $lastMessage = $conversation->getMessages()->last();

            $data[] = [
                'id' => $conversation->getId(),
                'username' => $other->getUsername(),
                'avatar' => $other->getAvatar(),
                'lastMessage' => $lastMessage ? mb_substr($lastMessage->getContent(), 0, 40) : '',
                'updatedAt' => $conversation->getUpdatedAt()?->format('d/m H:i') ?? '',
                'unread' => $this->countUnread($conversation, $user),
            ];
        }

        return new JsonResponse($data);
    }

    // ─── AJAX : messages d'une conversation ───────────────────
    #[Route('/ajax/messages/{username}', name: 'ajax_messages', methods: ['GET'])]
    public function ajaxMessages(
        string $username,
        UserRepository $userRepository,
        PrivateConversationRepository $conversationRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$targetUser) {
            return new JsonResponse(['error' => 'Utilisateur introuvable'], 404);
        }

        $conversation = $conversationRepository->findBetween($currentUser, $targetUser);

        if (!$conversation) {
            return new JsonResponse([
                'conversationId' => null,
                'messages' => [],
            ]);
        }

        $this->markAsRead($conversation, $currentUser, $em);

        $messages = $conversation->getMessages()->toArray();
        usort($messages, fn($a, $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        $data = array_map(fn($msg) => [
            'id' => $msg->getId(),
            'content' => $msg->getContent(),
            'author' => $msg->getAuthor()->getUsername(),
            'avatar' => $msg->getAuthor()->getAvatar(),
            'createdAt' => $msg->getCreatedAt()->format('d/m H:i'),
            'isCurrentUser' => $msg->getAuthor() === $currentUser,
        ], $messages);

        return new JsonResponse([
            'conversationId' => $conversation->getId(),
            'messages' => $data,
        ]);
    }

    // ─── AJAX : envoyer un message ────────────────────────────
    #[Route('/ajax/send/{username}', name: 'ajax_send', methods: ['POST'])]
    public function ajaxSend(
        string $username,
        Request $request,
        UserRepository $userRepository,
        PrivateConversationRepository $conversationRepository,
        FriendshipRepository $friendshipRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$targetUser) {
            return new JsonResponse(['error' => 'Utilisateur introuvable'], 404);
        }

        // Vérifier amitié
        $friendship = $friendshipRepository->findExisting($currentUser, $targetUser);
        if (!$friendship || $friendship->getStatus() !== 'accepted') {
            return new JsonResponse(['error' => 'Vous devez être amis pour envoyer un message.'], 403);
        }

        $content = trim($request->request->get('content', ''));
        if (empty($content)) {
            return new JsonResponse(['error' => 'Message vide'], 400);
        }

        $conversation = $this->getOrCreateConversation($currentUser, $targetUser, $conversationRepository, $em);
        $message = $this->createAndPublishMessage($content, $currentUser, $conversation, $em);

        return new JsonResponse([
            'id' => $message->getId(),
            'content' => $message->getContent(),
            'author' => $currentUser->getUsername(),
            'avatar' => $currentUser->getAvatar(),
            'createdAt' => $message->getCreatedAt()->format('d/m H:i'),
            'isCurrentUser' => true,
            'conversationId' => $conversation->getId(),
        ]);
    }

    // ─── AJAX : IDs des conversations pour Mercure ────────────
    #[Route('/ajax/conversation-ids', name: 'ajax_conversation_ids', methods: ['GET'])]
    public function ajaxConversationIds(PrivateConversationRepository $conversationRepository): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $conversations = $conversationRepository->findUserConversations($user);
        $ids = array_map(fn($c) => $c->getId(), $conversations);

        return new JsonResponse($ids);
    }

    // ─── Helpers privés ───────────────────────────────────────

    private function getOrCreateConversation($user1, $user2, $repo, $em): PrivateConversation
    {
        $conversation = $repo->findBetween($user1, $user2);
        if (!$conversation) {
            $conversation = new PrivateConversation();
            $conversation->setParticipant1($user1);
            $conversation->setParticipant2($user2);
            $em->persist($conversation);
            $em->flush();
        }
        return $conversation;
    }

    private function createAndPublishMessage(string $content, $author, PrivateConversation $conversation, EntityManagerInterface $em): PrivateMessage
    {
        $message = new PrivateMessage();
        $message->setContent($content);
        $message->setAuthor($author);
        $message->setConversation($conversation);
        $conversation->setUpdatedAt(new \DateTimeImmutable());

        $em->persist($message);
        $em->flush();

        // Publier via Pusher
        $this->pusher->sendMessage(
            sprintf('conversation-%d', $conversation->getId()),
            'new-message',
            [
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'author' => $author->getUsername(),
                'avatar' => $author->getAvatar(),
                'createdAt' => $message->getCreatedAt()->format('d/m H:i'),
                'conversationId' => $conversation->getId(),
                'isCurrentUser' => false,
            ]
        );

        return $message;
    }

    private function markAsRead(PrivateConversation $conversation, $user, EntityManagerInterface $em): void
    {
        $changed = false;
        foreach ($conversation->getMessages() as $message) {
            if ($message->getAuthor() !== $user && !$message->isRead()) {
                $message->setIsRead(true);
                $changed = true;
            }
        }
        if ($changed) {
            $em->flush();
        }
    }

    private function countUnread(PrivateConversation $conversation, $user): int
    {
        $count = 0;
        foreach ($conversation->getMessages() as $message) {
            if ($message->getAuthor() !== $user && !$message->isRead()) {
                $count++;
            }
        }
        return $count;
    }
}