<?php

namespace App\Controller;

use App\Entity\Friendship;
use App\Repository\FriendshipRepository;
use App\Repository\UserRepository;
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
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('friendship', $request->request->get('_token')))) {
        throw $this->createAccessDeniedException('Token CSRF invalide.');
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
        CsrfTokenManagerInterface $csrfTokenManager
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $friendship = $friendshipRepository->find($id);

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('friendship', $request->request->get('_token')))) {
        throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        if (!$friendship || $friendship->getReceiver() !== $currentUser) {
            throw $this->createAccessDeniedException();
        }

        $friendship->setStatus('accepted');
        $em->flush();

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
    CsrfTokenManagerInterface $csrfTokenManager
): Response {
    if (!$csrfTokenManager->isTokenValid(new CsrfToken('friendship', $request->request->get('_token')))) {
        throw $this->createAccessDeniedException('Token CSRF invalide.');
    }

    /** @var \App\Entity\User $currentUser */
    $currentUser = $this->getUser();
    $friendship = $friendshipRepository->find($id);

    if (!$friendship || $friendship->getReceiver() !== $currentUser) {
        throw $this->createAccessDeniedException();
    }

    $friendship->setStatus('blocked');
    $em->flush();

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
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        $friendship = $friendshipRepository->findExisting($currentUser, $targetUser);
        if ($friendship && $friendship->getStatus() === 'blocked') {
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
        throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        if (!$targetUser) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        $friendship = $friendshipRepository->findExisting($currentUser, $targetUser);
        if ($friendship) {
            $em->remove($friendship);
            $em->flush();
            $this->addFlash('success', $targetUser->getUsername() . ' a été retiré de vos amis.');
        }

        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Liste des amis et demandes reçues
    #[Route('/list', name: 'list')]
    public function list(FriendshipRepository $friendshipRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

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
}