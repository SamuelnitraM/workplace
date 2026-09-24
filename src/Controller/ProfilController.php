<?php

namespace App\Controller;

use App\Form\ChangePasswordFormType;
use App\Form\UserProfileFormType;
use App\Entity\GalleryPhoto;
use App\Repository\FriendshipRepository;
use App\Repository\ArmyListRepository;
use App\Repository\GalleryAlbumRepository;
use App\Repository\GalleryPhotoRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Security\Voter\GalleryPhotoVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\GamificationService;
use App\Service\AvatarUploader;
use App\Service\GalleryPhotoUploader;
use App\Service\GalleryAlbumManager;
use App\Service\MemberBlocker;
use App\Gamification\BadgeRarity;
use App\Gamification\ExperienceHistory;

#[Route('/profil', name: 'app_profil_')]
class ProfilController extends AbstractController
{
    // Profil public — accessible par tous
#[Route('/{username}', name: 'show')]
public function show(
    string $username,
    ArmyListRepository $armyListRepository,
    UserRepository $userRepository,
    FriendshipRepository $friendshipRepository,
    GroupRepository $groupRepository,
    GroupMemberRepository $groupMemberRepository,
    GalleryPhotoRepository $galleryPhotoRepository,
    GamificationService $gamification,
    BadgeRarity $badgeRarity,
    ExperienceHistory $experienceHistory,
    MemberBlocker $memberBlocker,
    GalleryAlbumRepository $galleryAlbumRepository,
): Response {
    $user = $userRepository->findOneBy(['username' => $username]);

    if (!$user) {
        throw $this->createNotFoundException('Utilisateur introuvable');
    }

    $isOwner = $this->getUser() && $this->getUser()->getUserIdentifier() === $user->getEmail();
    $galleryPhotos = $isOwner ? $galleryPhotoRepository->findByOwner($user) : $galleryPhotoRepository->findVisibleByOwner($user);
    $publicArmyLists = $armyListRepository->findBy(['owner' => $user, 'isPublic' => true], ['createdAt' => 'DESC']);
    $friends = $friendshipRepository->findAcceptedFriends($user);
    $activities = [];
    if ($user->isShowActivity()) {
        foreach ($user->getPosts() as $post) {
            $activities[] = ['date' => $post->getCreatedAt(), 'label' => 'a écrit dans le sujet', 'subject' => $post->getThread()->getTitle(), 'url' => 'app_thread_show', 'parameters' => ['slug' => $post->getThread()->getSlug()]];
        }
        foreach ($publicArmyLists as $list) {
            $activities[] = ['date' => $list->getCreatedAt(), 'label' => 'a créé la liste d\'armée', 'subject' => $list->getName(), 'url' => 'app_army_show', 'parameters' => ['id' => $list->getId()]];
        }
        foreach ($galleryPhotos as $photo) {
            if ($photo->isVisible()) {
                $activities[] = ['date' => $photo->getCreatedAt(), 'label' => 'a ajouté une photo', 'subject' => null, 'url' => null, 'parameters' => []];
            }
        }
        foreach ($friends as $friendship) {
            $friend = $friendship->getRequester() === $user ? $friendship->getReceiver() : $friendship->getRequester();
            $activities[] = ['date' => $friendship->getCreatedAt(), 'label' => 'est devenu ami avec', 'subject' => $friend->getUsername(), 'url' => 'app_profil_show', 'parameters' => ['username' => $friend->getUsername()]];
        }
        foreach ($user->getGroupMembers() as $membership) {
            if ($membership->getUsergroup()->isPublic()) {
                $activities[] = ['date' => $membership->getJoinedAt(), 'label' => 'a rejoint le groupe', 'subject' => $membership->getUsergroup()->getName(), 'url' => 'app_group_show', 'parameters' => ['slug' => $membership->getUsergroup()->getSlug()]];
            }
        }
        usort($activities, static fn (array $left, array $right) => $right['date'] <=> $left['date']);
        $activities = array_slice($activities, 0, 10);
        foreach ($activities as &$activity) {
            $seconds = max(0, time() - $activity['date']->getTimestamp());
            $activity['time'] = $seconds < 3600 ? 'il y a ' . max(1, intdiv($seconds, 60)) . ' min' : ($seconds < 86400 ? 'il y a ' . intdiv($seconds, 3600) . 'h' : 'il y a ' . intdiv($seconds, 86400) . 'j');
        }
        unset($activity);
    }

    $friendship = null;
    $blockedByMe = false;
    $blockedMe = false;
    if ($this->getUser() && !$isOwner) {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $friendship = $friendshipRepository->findExisting($currentUser, $user);
        $blockedByMe = $memberBlocker->hasBlocked($currentUser, $user);
        $blockedMe = $memberBlocker->hasBlocked($user, $currentUser);
    }

    $myGroups = [];
    if ($this->getUser() && !$isOwner) {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        // Récupérer tous mes groupes où j'ai le droit d'inviter
        $allMyGroups = $groupRepository->findGroupsByMember($currentUser);

        // Filtrer : garder uniquement les groupes où
        // 1. J'ai le rôle owner ou admin
        // 2. L'utilisateur cible n'est pas déjà membre
        $myGroups = array_filter($allMyGroups, function($group) use ($currentUser, $user, $groupMemberRepository) {
            // Vérifier mon rôle dans ce groupe
            $myMembership = $groupMemberRepository->findOneBy([
                'user' => $currentUser,
                'usergroup' => $group,
            ]);

            if (!$myMembership || !in_array($myMembership->getRole(), ['owner', 'admin', 'member'])) {
                return false;
            }

            // Vérifier que l'utilisateur cible n'est pas déjà membre
            $targetMembership = $groupMemberRepository->findOneBy([
                'user' => $user,
                'usergroup' => $group,
            ]);

            return $targetMembership === null;
        });
    }

    return $this->render('profil/index.html.twig', [
        'user' => $user,
        'isOwner' => $isOwner,
        'friendship' => $friendship,
        'blockedByMe' => $blockedByMe,
        'blockedMe' => $blockedMe,
        'myGroups' => $myGroups,
        'publicArmyLists' => $publicArmyLists,
        'galleryPhotos' => $galleryPhotos,
        'galleryAlbums' => array_values(array_filter(
            $galleryAlbumRepository->findByOwner($user),
            static fn ($album) => $isOwner || GalleryAlbumManager::coverOf($album, false) !== null,
        )),
        'loosePhotos' => array_values(array_filter($galleryPhotos, static fn (GalleryPhoto $photo) => $photo->getAlbum() === null)),
        'galleryStats' => $galleryPhotoRepository->getStatsForPhotos($galleryPhotos),
        'galleryDescriptionMaxLength' => GalleryPhoto::DESCRIPTION_MAX_LENGTH,
        'friends' => $friends,
        'activities' => $activities,
        // Progression détaillée (compteurs, rubriques visitées) réservée au propriétaire du profil
        'profileBadges' => $gamification->getProfileBadges($user, $isOwner),
        'badgeRarity' => $badgeRarity->all(),
        // Série de connexions et historique d'XP : privés (propriétaire uniquement)
        'streakStatus' => $isOwner ? $gamification->getStreakStatus($user) : null,
        'xpHistory' => $isOwner ? $experienceHistory->page($user, 1, ExperienceHistory::PREVIEW_SIZE) : null,
        'dailyLoginXp' => GamificationService::DAILY_LOGIN_XP,
        'streakBonusXp' => GamificationService::STREAK_BONUS_XP,
        'streakBonusEvery' => GamificationService::STREAK_BONUS_EVERY,
        'streakMaxMissedDays' => GamificationService::STREAK_MAX_MISSED_DAYS,
    ]);
}

    #[Route('/{username}/gallery/upload', name: 'gallery_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadGalleryPhoto(string $username, Request $request, UserRepository $userRepository, EntityManagerInterface $em, GalleryPhotoUploader $galleryUploader): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $user = $userRepository->findOneBy(['username' => $username]);
        if (!$user) throw $this->createNotFoundException('Utilisateur introuvable');
        if ($user !== $currentUser || !$this->isCsrfTokenValid('gallery_upload', $request->request->get('_token'))) throw $this->createAccessDeniedException();

        $result = $galleryUploader->upload($user, $request->files->get('photo'), (string) $request->request->get('description', ''));
        if (is_string($result)) {
            $this->addFlash('error', $result);
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
        $em->flush();
        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    #[Route('/{username}/gallery/delete', name: 'gallery_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteGalleryPhotos(string $username, Request $request, UserRepository $userRepository, GalleryPhotoRepository $galleryPhotoRepository, EntityManagerInterface $em, GalleryPhotoUploader $galleryUploader): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $user = $userRepository->findOneBy(['username' => $username]);
        if (!$user || $user !== $currentUser || !$this->isCsrfTokenValid('gallery_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        foreach ($request->request->all('photo_ids') as $photoId) {
            $photo = $galleryPhotoRepository->find((int) $photoId);
            if ($photo && $photo->getOwner() === $user && $this->isGranted(GalleryPhotoVoter::DELETE, $photo)) {
                $galleryUploader->delete($photo);
            }
        }
        $em->flush();

        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    #[Route('/{username}/gallery/{id}/visibility', name: 'gallery_visibility', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggleGalleryPhotoVisibility(string $username, int $id, Request $request, UserRepository $userRepository, GalleryPhotoRepository $galleryPhotoRepository, EntityManagerInterface $em): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $user = $userRepository->findOneBy(['username' => $username]);
        $photo = $galleryPhotoRepository->find($id);
        if (!$user || $user !== $currentUser || !$photo || $photo->getOwner() !== $user || !$this->isCsrfTokenValid('gallery_visibility_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($photo->isHiddenByModeration()) {
            $this->addFlash('error', 'Cette photo a été masquée par la modération : elle ne peut pas être affichée à nouveau.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
        $photo->setIsVisible(!$photo->isVisible());
        $em->flush();

        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Modifier son propre profil — connecté uniquement
    #[Route('/settings/edit', name: 'edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, EntityManagerInterface $em, AvatarUploader $avatarUploader): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $form = $this->createForm(UserProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && !$form->isValid()) {
            // Le formulaire est lié à l'utilisateur connecté : on annule les valeurs refusées sur l'entité
            // (sinon app.user.username, utilisé dans la barre de navigation, contiendrait la valeur invalide).
            // Le formulaire garde les valeurs saisies et ses erreurs pour le ré-affichage.
            $em->refresh($user);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $oldAvatar = $user->getAvatar();
            /** @var UploadedFile|null $avatarFile */
            $avatarFile = $form->get('avatarFile')->getData();

            if ($request->request->get('delete_avatar') === '1') {
                $user->setAvatar(null);
            }

            // L'avatar est ré-encodé en WebP (suppression des métadonnées EXIF)
            if ($avatarFile instanceof UploadedFile && ($error = $avatarUploader->upload($user, $avatarFile))) {
                $this->addFlash('error', $error);
            }

            $em->flush();

            // Suppression de l'ancien fichier s'il a été remplacé ou supprimé
            $avatarUploader->deleteReplaced($oldAvatar, $user);

            $this->addFlash('success', 'Profil mis à jour avec succès !');
            return $this->redirectToRoute('app_profil_show', ['username' => $user->getUsername()]);
        }

        return $this->render('profil/edit.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    // Changer son mot de passe — connecté uniquement
    #[Route('/settings/change-password', name: 'change_password')]
    #[IsGranted('ROLE_USER')]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();
            if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('error', 'Votre mot de passe actuel est incorrect.');
                return $this->redirectToRoute('app_profil_change_password');
            }

            $newPassword = $form->get('newPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));

            $em->flush();
            $this->addFlash('success', 'Mot de passe modifié avec succès !');
            return $this->redirectToRoute('app_profil_show', ['username' => $user->getUsername()]);
        }

        return $this->render('profil/change_password.html.twig', [
            'form' => $form,
        ]);
    }
}