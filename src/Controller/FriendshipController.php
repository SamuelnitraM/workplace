<?php

namespace App\Controller;

use App\Entity\Friendship;
use App\Entity\Notification;
use App\Entity\User;
use App\Http\SafeReferer;
use App\Repository\FriendshipRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserBlockRepository;
use App\Repository\UserRepository;
use App\Service\MemberBlocker;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/friendship', name: 'app_friendship_')]
class FriendshipController extends AbstractController
{
    /** Friend suggestions shown next to the friend list. */
    public const SUGGESTIONS_LIMIT = 8;

    // Send a friend request
    #[Route('/request/{username}', name: 'request', methods: ['POST'])]
    public function request(
        string $username,
        UserRepository $userRepository,
        Request $request,
        FriendshipRepository $friendshipRepository,
        EntityManagerInterface $em,
        NotificationService $notifications,
        MemberBlocker $memberBlocker,
    ): Response {
        $targetUser = $this->findActionTarget($username, $request, $userRepository);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        if ($targetUser === $currentUser) {
            $this->addFlash('error', 'Tu ne peux pas t\'ajouter toi-même.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
        if ($memberBlocker->isBlockedEitherWay($currentUser, $targetUser)) {
            $this->addFlash('error', 'Impossible d\'envoyer une demande d\'ami à ce membre.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
        // A single request or friendship per pair of members
        if ($friendshipRepository->findExisting($currentUser, $targetUser)) {
            $this->addFlash('error', 'Une demande d\'ami existe déjà.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
        $friendship = new Friendship();
        $friendship->setRequester($currentUser);
        $friendship->setReceiver($targetUser);
        $em->persist($friendship);
        $em->flush();
        $notifications->notify(
            $targetUser,
            Notification::TYPE_FRIEND_REQUEST,
            $currentUser,
            [],
            $this->generateUrl('app_friendship_list'),
            self::friendRequestKey($currentUser),
        );
        $this->addFlash('success', 'Demande d\'ami envoyée à ' . $targetUser->getUsername() . ' !');
        // Back to the page of the request (profile, friend suggestions)
        return $this->redirect(SafeReferer::urlOr($request, $this->generateUrl('app_profil_show', ['username' => $username])));
    }

    // Accept a friend request
    #[Route('/accept/{id}', name: 'accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(
        int $id,
        Request $request,
        FriendshipRepository $friendshipRepository,
        EntityManagerInterface $em,
        NotificationService $notifications,
        NotificationRepository $notificationRepository,
    ): Response {
        $friendship = $this->findPendingReceivedRequest($id, $request, $friendshipRepository);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $friendship->setStatus('accepted');
        $em->flush();
        $notificationRepository->markReadByGroupKey($currentUser, self::friendRequestKey($friendship->getRequester()));
        $notifications->notify(
            $friendship->getRequester(),
            Notification::TYPE_FRIEND_ACCEPTED,
            $currentUser,
            [],
            $this->generateUrl('app_profil_show', ['username' => $currentUser->getUsername()]),
        );
        $this->addFlash('success', 'Tu es maintenant ami avec ' . $friendship->getRequester()->getUsername() . ' !');
        return $this->redirectToRoute('app_friendship_list');
    }

    // Refuse a friend request
    #[Route('/refuse/{id}', name: 'refuse', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refuse(
        int $id,
        Request $request,
        FriendshipRepository $friendshipRepository,
        NotificationRepository $notificationRepository,
        MemberBlocker $memberBlocker,
    ): Response {
        $friendship = $this->findPendingReceivedRequest($id, $request, $friendshipRepository);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        // Refusing a request blocks the requester (listed in the "blocked" tab, where the block can be lifted)
        $requester = $friendship->getRequester();
        $memberBlocker->block($currentUser, $requester);
        $notificationRepository->markReadByGroupKey($currentUser, self::friendRequestKey($requester));
        $this->addFlash('info', 'Utilisateur bloqué.');
        return $this->redirectToRoute('app_friendship_list');
    }

    // Block a member (from the profile page)
    #[Route('/block/{username}', name: 'block', methods: ['POST'])]
    public function block(string $username, Request $request, UserRepository $userRepository, MemberBlocker $memberBlocker): Response
    {
        $targetUser = $this->findActionTarget($username, $request, $userRepository);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        if ($targetUser !== $currentUser) {
            $memberBlocker->block($currentUser, $targetUser);
            $this->addFlash('success', $targetUser->getUsername() . ' est bloqué : tu ne peux plus échanger de messages ni de demandes d\'ami.');
        }
        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Unblock a member: only the member who blocked can lift the block
    #[Route('/unblock/{username}', name: 'unblock', methods: ['POST'])]
    public function unblock(string $username, Request $request, UserRepository $userRepository, MemberBlocker $memberBlocker): Response
    {
        $targetUser = $this->findActionTarget($username, $request, $userRepository);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        if ($memberBlocker->hasBlocked($currentUser, $targetUser)) {
            $memberBlocker->unblock($currentUser, $targetUser);
            $this->addFlash('success', $targetUser->getUsername() . ' a été débloqué.');
        }
        return $this->redirect($request->request->get('_redirect') === 'list'
            ? $this->generateUrl('app_friendship_list')
            : $this->generateUrl('app_profil_show', ['username' => $username]));
    }

    // Remove a friend, or withdraw a pending request
    #[Route('/remove/{username}', name: 'remove', methods: ['POST'])]
    public function remove(
        string $username,
        Request $request,
        UserRepository $userRepository,
        FriendshipRepository $friendshipRepository,
        EntityManagerInterface $em,
    ): Response {
        $targetUser = $this->findActionTarget($username, $request, $userRepository);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        // A pending request can only be withdrawn by its requester (the receiver refuses it instead)
        $friendship = $friendshipRepository->findExisting($currentUser, $targetUser);
        $canRemove = $friendship && (
            $friendship->getStatus() === 'accepted'
            || ($friendship->getStatus() === 'pending' && $friendship->getRequester() === $currentUser)
        );
        if ($canRemove) {
            $em->remove($friendship);
            $em->flush();
            $this->addFlash('success', $targetUser->getUsername() . ' a été retiré de tes amis.');
        }
        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Friend list, received and sent requests, blocked members
    #[Route('/list', name: 'list')]
    public function list(FriendshipRepository $friendshipRepository, NotificationRepository $notificationRepository, UserBlockRepository $blockRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        // Received requests and acceptances are shown on this page: their notifications are read
        $notificationRepository->markReadByTypes($currentUser, [
            Notification::TYPE_FRIEND_REQUEST,
            Notification::TYPE_FRIEND_ACCEPTED,
        ]);

        $friends = $friendshipRepository->findAcceptedFriends($currentUser);
        $pendingReceived = $friendshipRepository->findPendingReceived($currentUser);
        $pendingSent = $friendshipRepository->findPendingSent($currentUser);
        $blocked = $blockRepository->findBlockedBy($currentUser);

        return $this->render('friendship/list.html.twig', [
            'friends' => $friends,
            'pendingReceived' => $pendingReceived,
            'pendingSent' => $pendingSent,
            'blocked' => $blocked,
            'suggestions' => $friendshipRepository->findSuggestions($currentUser, self::SUGGESTIONS_LIMIT),
        ]);
    }

    /** Target member of a friendship or block action, after the CSRF check (never the « Membre supprimé » account). */
    private function findActionTarget(string $username, Request $request, UserRepository $userRepository): User
    {
        $this->denyUnlessCsrfValid($request);
        $targetUser = $userRepository->findOneBy(['username' => $username]);
        if (!$targetUser || $targetUser->isDeletedMemberAccount()) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }
        return $targetUser;
    }

    /** Pending friend request received by the current member, after the CSRF check. */
    private function findPendingReceivedRequest(int $id, Request $request, FriendshipRepository $friendshipRepository): Friendship
    {
        $this->denyUnlessCsrfValid($request);
        $friendship = $friendshipRepository->find($id);
        if (!$friendship || $friendship->getReceiver() !== $this->getUser() || $friendship->getStatus() !== 'pending') {
            throw $this->createAccessDeniedException();
        }
        return $friendship;
    }

    private function denyUnlessCsrfValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid('friendship', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /** Aggregation key of a friend request (a single notification per requester). */
    private static function friendRequestKey(User $requester): string
    {
        return 'friend_request:' . $requester->getId();
    }
}
