<?php

namespace App\Controller;

use App\Entity\PrivateConversation;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\PrivateConversationRepository;
use App\Repository\PrivateMessageRepository;
use App\Repository\UserRepository;
use App\Security\SubmissionThrottle;
use App\Security\ThrottledAction;
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
    public const CSRF_TOKEN_ID = 'private_message';
    private const MAX_LENGTH = 2000;

    public function __construct(
        private PusherService $pusher,
        private PrivateConversationRepository $conversationRepository,
        private PrivateMessageRepository $messageRepository,
        private FriendshipRepository $friendshipRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private SubmissionThrottle $throttle,
    ) {}

    // ─── Page liste des conversations ─────────────────────────
    #[Route('/', name: 'index')]
    public function index(): Response
    {
        $user = $this->currentUser();
        $conversations = $this->conversationRepository->findUserConversations($user);

        return $this->render('private_message/index.html.twig', [
            'conversations' => $conversations,
            'lastMessages' => $this->messageRepository->findLastMessages($conversations),
            'unreadCounts' => $this->messageRepository->countUnreadByConversation($user),
        ]);
    }

    // ─── Page conversation avec un user ───────────────────────
    #[Route('/{username}', name: 'show', methods: ['GET'])]
    public function show(string $username): Response
    {
        $currentUser = $this->currentUser();
        $targetUser = $this->findTargetUser($username);

        if ($targetUser === $currentUser) {
            return $this->redirectToRoute('app_message_index');
        }

        // La conversation n'est créée qu'au premier message envoyé
        $conversation = $this->conversationRepository->findBetween($currentUser, $targetUser);
        $canSend = $this->friendshipRepository->areFriends($currentUser, $targetUser);

        if (!$conversation && !$canSend) {
            $this->addFlash('error', 'Vous devez être amis pour échanger des messages.');
            return $this->redirectToRoute('app_profil_show', ['username' => $targetUser->getUsername()]);
        }

        if ($conversation) {
            $this->messageRepository->markConversationAsRead($conversation, $currentUser);
        }

        return $this->render('private_message/show.html.twig', [
            'conversation' => $conversation,
            'messages' => $conversation ? $conversation->getMessages()->toArray() : [],
            'targetUser' => $targetUser,
            'canSend' => $canSend,
        ]);
    }

    // ─── Envoyer un message (page dédiée) ─────────────────────
    #[Route('/{username}/send', name: 'send', methods: ['POST'])]
    public function send(string $username, Request $request): Response
    {
        $currentUser = $this->currentUser();
        $targetUser = $this->findTargetUser($username);

        $error = $this->validateSend($request, $currentUser, $targetUser);
        if ($error === null) {
            $message = $this->createAndPublishMessage($this->content($request), $currentUser, $targetUser);
        }

        if ($request->isXmlHttpRequest()) {
            return $error !== null
                ? new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST)
                : new JsonResponse($this->serializeMessage($message, $currentUser));
        }

        if ($error !== null) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('app_message_show', ['username' => $targetUser->getUsername()]);
    }

    // ─── AJAX : liste des conversations ───────────────────────
    #[Route('/ajax/conversations', name: 'ajax_conversations', methods: ['GET'])]
    public function ajaxConversations(): JsonResponse
    {
        $user = $this->currentUser();
        $conversations = $this->conversationRepository->findUserConversations($user);
        $lastMessages = $this->messageRepository->findLastMessages($conversations);
        $unreadCounts = $this->messageRepository->countUnreadByConversation($user);

        $data = [];
        foreach ($conversations as $conversation) {
            $other = $conversation->getOtherParticipant($user);
            $lastMessage = $lastMessages[$conversation->getId()] ?? null;

            $data[] = [
                'id' => $conversation->getId(),
                'username' => $other->getUsername(),
                'avatar' => $other->getAvatar(),
                'lastMessage' => $lastMessage ? mb_substr($lastMessage->getContent(), 0, 40) : '',
                'updatedAt' => $conversation->getUpdatedAt()?->format('d/m H:i') ?? '',
                'unread' => $unreadCounts[$conversation->getId()] ?? 0,
            ];
        }

        return new JsonResponse($data);
    }

    // ─── AJAX : messages d'une conversation ───────────────────
    #[Route('/ajax/messages/{username}', name: 'ajax_messages', methods: ['GET'])]
    public function ajaxMessages(string $username): JsonResponse
    {
        $currentUser = $this->currentUser();
        $targetUser = $this->userRepository->findOneBy(['username' => $username]);

        if (!$targetUser) {
            return new JsonResponse(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $conversation = $this->conversationRepository->findBetween($currentUser, $targetUser);
        if (!$conversation) {
            return new JsonResponse(['conversationId' => null, 'messages' => []]);
        }

        $this->messageRepository->markConversationAsRead($conversation, $currentUser);

        return new JsonResponse([
            'conversationId' => $conversation->getId(),
            'messages' => array_map(
                fn (PrivateMessage $msg) => $this->serializeMessage($msg, $currentUser),
                $conversation->getMessages()->toArray()
            ),
        ]);
    }

    // ─── AJAX : envoyer un message ────────────────────────────
    #[Route('/ajax/send/{username}', name: 'ajax_send', methods: ['POST'])]
    public function ajaxSend(string $username, Request $request): JsonResponse
    {
        $currentUser = $this->currentUser();
        $targetUser = $this->userRepository->findOneBy(['username' => $username]);

        if (!$targetUser) {
            return new JsonResponse(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        $error = $this->validateSend($request, $currentUser, $targetUser);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $message = $this->createAndPublishMessage($this->content($request), $currentUser, $targetUser);

        return new JsonResponse($this->serializeMessage($message, $currentUser));
    }

    // ─── AJAX : marquer une conversation comme lue ────────────
    #[Route('/ajax/read/{id}', name: 'ajax_read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ajaxRead(int $id, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $conversation = $this->conversationRepository->find($id);

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $this->csrfToken($request))) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }
        if (!$conversation || !$conversation->hasParticipant($user)) {
            return new JsonResponse(['error' => 'Conversation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $this->messageRepository->markConversationAsRead($conversation, $user);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/ajax/notification-context', name: 'ajax_notification_context', methods: ['GET'])]
    public function ajaxNotificationContext(): JsonResponse
    {
        $user = $this->currentUser();

        // Les demandes d'ami, invitations, etc. passent par le centre de notifications (/notifications/recent) :
        // seuls les messages privés non lus sont comptés ici (messenger).
        return new JsonResponse([
            'unreadMessages' => $this->messageRepository->countUnreadFor($user),
        ]);
    }

    // ─── Helpers privés ───────────────────────────────────────

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function findTargetUser(string $username): User
    {
        $user = $this->userRepository->findOneBy(['username' => $username]);
        if (!$user) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        return $user;
    }

    private function csrfToken(Request $request): ?string
    {
        return $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
    }

    private function content(Request $request): string
    {
        return trim($request->request->getString('content'));
    }

    /** Retourne un message d'erreur, ou null si l'envoi est autorisé. */
    private function validateSend(Request $request, User $author, User $target): ?string
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $this->csrfToken($request))) {
            return 'Jeton CSRF invalide, rechargez la page.';
        }
        if ($author === $target) {
            return 'Vous ne pouvez pas vous écrire à vous-même.';
        }
        if (!$this->friendshipRepository->areFriends($author, $target)) {
            return 'Vous devez être amis pour envoyer un message.';
        }

        $content = $this->content($request);
        if ($content === '') {
            return 'Message vide.';
        }
        if (mb_strlen($content) > self::MAX_LENGTH) {
            return sprintf('Message trop long (%d caractères maximum).', self::MAX_LENGTH);
        }
        if (!$this->throttle->tryConsumeForUser(ThrottledAction::PrivateMessage, $author)) {
            return ThrottledAction::PrivateMessage->refusalMessage();
        }

        return null;
    }

    private function createAndPublishMessage(string $content, User $author, User $target): PrivateMessage
    {
        $conversation = $this->conversationRepository->findBetween($author, $target);
        if (!$conversation) {
            $conversation = new PrivateConversation();
            $conversation->setParticipant1($author);
            $conversation->setParticipant2($target);
            $this->em->persist($conversation);
        }

        $message = new PrivateMessage();
        $message->setContent($content);
        $message->setAuthor($author);
        $message->setConversation($conversation);
        $conversation->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($message);
        $this->em->flush();

        $payload = $this->serializeMessage($message, null);

        // Page de conversation ouverte (les deux participants)
        $this->pusher->sendMessage(PusherService::conversationChannel($conversation->getId()), 'new-message', $payload);
        // Notification globale du destinataire (messenger, badges), même pour une nouvelle conversation
        $this->pusher->sendMessage(PusherService::userChannel($target->getId()), 'private-message', $payload);

        return $message;
    }

    private function serializeMessage(PrivateMessage $message, ?User $viewer): array
    {
        return [
            'id' => $message->getId(),
            'content' => $message->getContent(),
            'author' => $message->getAuthor()->getUsername(),
            'authorId' => $message->getAuthor()->getId(),
            'avatar' => $message->getAuthor()->getAvatar(),
            'createdAt' => $message->getCreatedAt()->format('d/m H:i'),
            'conversationId' => $message->getConversation()->getId(),
            'isCurrentUser' => $viewer !== null && $message->getAuthor() === $viewer,
        ];
    }
}
