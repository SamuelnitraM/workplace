<?php

namespace App\Controller;

use App\Entity\Friendship;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/friendship', name: 'app_friendship_')]
class FriendshipController extends AbstractController
{
    // Envoyer une demande d'ami
    #[Route('/request/{username}', name: 'request', methods: ['POST'])]
    public function request(
        string $username,
        UserRepository $userRepository,
        Request $request,
        FriendshipRepository $friendshipRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfTokenManager,
        NotificationService $notifications,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('friendship', $request->request->get('_token')))) {
        throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (!$targetUser) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        // Vérifier qu'on ne s'ajoute pas soi-même
        if ($targetUser === $currentUser) {
            $this->addFlash('error', 'Vous ne pouvez pas vous ajouter vous-même.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }

        // Vérifier qu'une demande n'existe pas déjà
        $existing = $friendshipRepository->findExisting($currentUser, $targetUser);
        if ($existing) {
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
        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Accepter une demande d'ami
    #[Route('/accept/{id}', name: 'accept', methods: ['POST'])]
    public function accept(
        int $id,
        Request $request,
        FriendshipRepository $friendshipRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfTokenManager,
        NotificationService $notifications,
        NotificationRepository $notificationRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $friendship = $friendshipRepository->find($id);

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('friendship', $request->request->get('_token')))) {
        throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (!$friendship || $friendship->getReceiver() !== $currentUser || $friendship->getStatus() !== 'pending') {
            throw $this->createAccessDeniedException();
        }

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

        $this->addFlash('success', 'Vous êtes maintenant ami avec ' . $friendship->getRequester()->getUsername() . ' !');
        return $this->redirectToRoute('app_friendship_list');
    }

    // Refuser une demande d'ami
#[Route('/refuse/{id}', name: 'refuse', methods: ['POST'])]
public function refuse(
    int $id,
    Request $request,
    FriendshipRepository $friendshipRepository,
    EntityManagerInterface $em,
    CsrfTokenManagerInterface $csrfTokenManager,
    NotificationRepository $notificationRepository,
): Response {
    if (!$csrfTokenManager->isTokenValid(new CsrfToken('friendship', $request->request->get('_token')))) {
        throw $this->createAccessDeniedException('Jeton CSRF invalide.');
    }

    /** @var \App\Entity\User $currentUser */
    $currentUser = $this->getUser();
    $friendship = $friendshipRepository->find($id);

    if (!$friendship || $friendship->getReceiver() !== $currentUser || $friendship->getStatus() !== 'pending') {
        throw $this->createAccessDeniedException();
    }

    // Refuser une demande bloque le demandeur (il apparaît dans l'onglet « bloqués » et peut être débloqué)
    $friendship->setStatus('blocked');
    $em->flush();
    $notificationRepository->markReadByGroupKey($currentUser, self::friendRequestKey($friendship->getRequester()));

    $this->addFlash('info', 'Utilisateur bloqué.');
    return $this->redirectToRoute('app_friendship_list');
}

        //débloquer un utilisateur refusé
    #[Route('/unblock/{username}', name: 'unblock', methods: ['POST'])]
    public function unblock(
        string $username,
        Request $request,
        UserRepository $userRepository,
        FriendshipRepository $friendshipRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfTokenManager
    ): Response {
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('friendship', $request->request->get('_token')))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$targetUser) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        // Seul l'utilisateur qui a bloqué (destinataire de la demande refusée) peut débloquer
        $friendship = $friendshipRepository->findExisting($currentUser, $targetUser);
        if ($friendship && $friendship->getStatus() === 'blocked' && $friendship->getReceiver() === $currentUser) {
            $em->remove($friendship);
            $em->flush();
            $this->addFlash('success', $targetUser->getUsername() . ' a été débloqué.');
        }

        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Supprimer un ami
    #[Route('/remove/{username}', name: 'remove', methods: ['POST'])]
    public function remove(
        string $username,
        Request $request,
        UserRepository $userRepository,
        FriendshipRepository $friendshipRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfTokenManager
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('friendship', $request->request->get('_token')))) {
        throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (!$targetUser) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        // Un blocage ne peut pas être levé par ici (sinon l'utilisateur bloqué pourrait l'effacer)
        $friendship = $friendshipRepository->findExisting($currentUser, $targetUser);
        $canRemove = $friendship && (
            $friendship->getStatus() === 'accepted'
            || ($friendship->getStatus() === 'pending' && $friendship->getRequester() === $currentUser)
        );
        if ($canRemove) {
            $em->remove($friendship);
            $em->flush();
            $this->addFlash('success', $targetUser->getUsername() . ' a été retiré de vos amis.');
        }

        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Liste des amis et demandes reçues
    #[Route('/list', name: 'list')]
    public function list(FriendshipRepository $friendshipRepository, NotificationRepository $notificationRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        // Les demandes reçues et acceptations sont affichées sur cette page : leurs notifications sont lues
        $notificationRepository->markReadByTypes($currentUser, [
            Notification::TYPE_FRIEND_REQUEST,
            Notification::TYPE_FRIEND_ACCEPTED,
        ]);

        $friends = $friendshipRepository->findAcceptedFriends($currentUser);
        $pendingReceived = $friendshipRepository->findPendingReceived($currentUser);
        $pendingSent = $friendshipRepository->findPendingSent($currentUser);
        $blocked = $friendshipRepository->findBlocked($currentUser);

        return $this->render('friendship/list.html.twig', [
            'friends' => $friends,
            'pendingReceived' => $pendingReceived,
            'pendingSent' => $pendingSent,
            'blocked' => $blocked,
        ]);
    }

    /** Clé d'agrégation d'une demande d'ami (une seule notification par demandeur). */
    private static function friendRequestKey(User $requester): string
    {
        return 'friend_request:' . $requester->getId();
    }
}
