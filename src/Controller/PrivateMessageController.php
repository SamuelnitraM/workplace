<?php

namespace App\Controller;

use App\Entity\PrivateConversation;
use App\Entity\PrivateMessage;
use App\Repository\PrivateConversationRepository;
use App\Repository\PrivateMessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/messages', name: 'app_message_')]
class PrivateMessageController extends AbstractController
{
    // Liste des conversations
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

    // Ouvrir/créer une conversation avec un user
    #[Route('/{username}', name: 'show')]
    public function show(
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
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        if ($targetUser === $currentUser) {
            return $this->redirectToRoute('app_message_index');
        }

        // Chercher une conversation existante
        $conversation = $conversationRepository->findBetween($currentUser, $targetUser);

        // Créer une nouvelle conversation si elle n'existe pas
        if (!$conversation) {
            $conversation = new PrivateConversation();
            $conversation->setParticipant1($currentUser);
            $conversation->setParticipant2($targetUser);
            $em->persist($conversation);
            $em->flush();
        }

        // Marquer les messages comme lus
        foreach ($conversation->getMessages() as $message) {
            if ($message->getAuthor() !== $currentUser && !$message->isRead()) {
                $message->setIsRead(true);
            }
        }
        $em->flush();

        $messages = $conversation->getMessages()->toArray();
        usort($messages, fn($a, $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        return $this->render('private_message/show.html.twig', [
            'conversation' => $conversation,
            'messages' => $messages,
            'targetUser' => $targetUser,
        ]);
    }

    // Envoyer un message
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

        $conversation = $conversationRepository->findBetween($currentUser, $targetUser);

        if (!$conversation) {
            $conversation = new PrivateConversation();
            $conversation->setParticipant1($currentUser);
            $conversation->setParticipant2($targetUser);
            $em->persist($conversation);
            $em->flush();
        }

        $content = trim($request->request->get('content', ''));
        if (empty($content)) {
            return $this->redirectToRoute('app_message_show', ['username' => $username]);
        }

        $message = new PrivateMessage();
        $message->setContent($content);
        $message->setAuthor($currentUser);
        $message->setConversation($conversation);

        $conversation->setUpdatedAt(new \DateTimeImmutable());

        $em->persist($message);
        $em->flush();

        // Publier via Mercure
        $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJtZXJjdXJlIjp7InB1Ymxpc2giOlsiKiJdfX0.K4Dv2n1sqI9w7RLBXvxiKtlEp3q8cAfDGboMETVKd9w';
        $tokenProvider = new StaticTokenProvider($token);
        $mercureHub = new Hub('http://localhost:3000/.well-known/mercure', $tokenProvider);

        $update = new Update(
            sprintf('private/conversation/%d', $conversation->getId()),
            json_encode([
                'id' => $message->getId(),
                'content' => $message->getContent(),
                'author' => $currentUser->getUsername(),
                'avatar' => $currentUser->getAvatar(),
                'createdAt' => $message->getCreatedAt()->format('d/m H:i'),
            ])
        );

        $mercureHub->publish($update);

        return $this->redirectToRoute('app_message_show', ['username' => $username]);
    }
}