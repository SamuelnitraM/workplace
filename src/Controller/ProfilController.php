<?php

namespace App\Controller;

use App\Security\ThrottledAction;
use App\Security\SubmissionThrottle;
use App\Account\AccountDeleter;
use App\Form\ChangePasswordFormType;
use App\Form\UserProfileFormType;
use App\Entity\ArmyList;
use App\Entity\Friendship;
use App\Entity\GalleryPhoto;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\ArmyListRepository;
use App\Repository\GalleryAlbumRepository;
use App\Repository\GalleryPhotoRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\PostRepository;
use App\Repository\UserRepository;
use App\Security\Voter\GalleryPhotoVoter;
use App\Security\Voter\GroupVoter;
use App\Security\Voter\MemberContentVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\GamificationService;
use App\Profile\ProfileImage;
use App\Service\ProfileImageUploader;
use App\Service\GalleryPhotoUploader;
use App\Service\GalleryAlbumManager;
use App\Service\MemberBlocker;
use App\Gamification\BadgeRarity;
use App\Gamification\ExperienceHistory;

#[Route('/profil', name: 'app_profil_')]
class ProfilController extends AbstractController
{
    public const DELETE_ACCOUNT_CSRF_ID = 'delete_account';
    /** Entries of the « recent activity » block of a profile. */
    private const ACTIVITY_LIMIT = 10;

    // Public profile, open to everyone
    #[Route('/{username}', name: 'show')]
    public function show(
        string $username,
        ArmyListRepository $armyListRepository,
        UserRepository $userRepository,
        FriendshipRepository $friendshipRepository,
        GroupRepository $groupRepository,
        GalleryPhotoRepository $galleryPhotoRepository,
        GamificationService $gamification,
        BadgeRarity $badgeRarity,
        ExperienceHistory $experienceHistory,
        MemberBlocker $memberBlocker,
        GalleryAlbumRepository $galleryAlbumRepository,
        PostRepository $postRepository,
        GroupMemberRepository $groupMemberRepository,
    ): Response {
        $user = $userRepository->findOneBy(['username' => $username]);
        if (!$user || $user->isDeletedMemberAccount()) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }
        // A banned member only keeps the photo, the username and the « Banni » mark (App\Moderation\BannedMembers)
        if (!$this->isGranted(MemberContentVoter::VIEW, $user)) {
            return $this->render('profil/banned.html.twig', ['user' => $user]);
        }
        $viewer = $this->getUser();
        $viewer = $viewer instanceof User ? $viewer : null;
        $isOwner = $viewer === $user;
        $galleryPhotos = $isOwner ? $galleryPhotoRepository->findByOwner($user) : $galleryPhotoRepository->findVisibleByOwner($user);
        $publicArmyLists = $armyListRepository->findBy(['owner' => $user, 'isPublic' => true], ['createdAt' => 'DESC']);
        $friends = $friendshipRepository->findAcceptedFriends($user);
        $activities = $user->isShowActivity()
            ? $this->recentActivities($user, $publicArmyLists, $galleryPhotos, $friends, $postRepository, $groupMemberRepository)
            : [];
        $friendship = null;
        $blockedByMe = false;
        $blockedMe = false;
        $myGroups = [];
        if ($viewer !== null && !$isOwner) {
            $friendship = $friendshipRepository->findExisting($viewer, $user);
            $blockedByMe = $memberBlocker->hasBlocked($viewer, $user);
            $blockedMe = $memberBlocker->hasBlocked($user, $viewer);
            // Groups where the viewer may invite (GroupVoter::INVITE) and that the displayed member has not joined yet
            $memberGroupIds = array_map(static fn (Group $group): ?int => $group->getId(), $groupRepository->findGroupsByMember($user));
            $myGroups = array_filter(
                $groupRepository->findGroupsByMember($viewer),
                fn (Group $group): bool => !in_array($group->getId(), $memberGroupIds, true) && $this->isGranted(GroupVoter::INVITE, $group),
            );
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
            // Detailed progress (counters, visited sections) reserved to the profile owner
            'profileBadges' => $gamification->getProfileBadges($user, $isOwner),
            'badgeRarity' => $badgeRarity->all(),
            // Login streak and XP history: private (owner only)
            'streakStatus' => $isOwner ? $gamification->getStreakStatus($user) : null,
            'xpHistory' => $isOwner ? $experienceHistory->page($user, 1, ExperienceHistory::PREVIEW_SIZE) : null,
            'dailyLoginXp' => GamificationService::DAILY_LOGIN_XP,
            'streakBonusXp' => GamificationService::STREAK_BONUS_XP,
            'streakBonusEvery' => GamificationService::STREAK_BONUS_EVERY,
            'streakMaxMissedDays' => GamificationService::STREAK_MAX_MISSED_DAYS,
        ]);
    }

    /**
     * Latest public activities of a member (forum posts, public army lists, visible photos, friendships, public groups joined),
     * most recent first. Posts and group memberships are read with their thread / group in one query each.
     *
     * @param ArmyList[] $publicArmyLists
     * @param GalleryPhoto[] $galleryPhotos
     * @param Friendship[] $friends
     * @return list<array<string, mixed>>
     */
    private function recentActivities(User $user, array $publicArmyLists, array $galleryPhotos, array $friends, PostRepository $postRepository, GroupMemberRepository $groupMemberRepository): array
    {
        $activities = [];
        $posts = $postRepository->createQueryBuilder('p')
            ->addSelect('t')
            ->innerJoin('p.thread', 't')
            ->where('p.author = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(self::ACTIVITY_LIMIT)
            ->getQuery()
            ->getResult();
        foreach ($posts as $post) {
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
        $publicMemberships = $groupMemberRepository->createQueryBuilder('m')
            ->addSelect('g')
            ->innerJoin('m.usergroup', 'g')
            ->where('m.user = :user')
            ->andWhere('g.isPublic = true')
            ->setParameter('user', $user)
            ->orderBy('m.joinedAt', 'DESC')
            ->setMaxResults(self::ACTIVITY_LIMIT)
            ->getQuery()
            ->getResult();
        foreach ($publicMemberships as $membership) {
            $activities[] = ['date' => $membership->getJoinedAt(), 'label' => 'a rejoint le groupe', 'subject' => $membership->getUsergroup()->getName(), 'url' => 'app_group_show', 'parameters' => ['slug' => $membership->getUsergroup()->getSlug()]];
        }
        usort($activities, static fn (array $left, array $right) => $right['date'] <=> $left['date']);
        $activities = array_slice($activities, 0, self::ACTIVITY_LIMIT);
        foreach ($activities as &$activity) {
            $seconds = max(0, time() - $activity['date']->getTimestamp());
            $activity['time'] = $seconds < 3600 ? 'il y a ' . max(1, intdiv($seconds, 60)) . ' min' : ($seconds < 86400 ? 'il y a ' . intdiv($seconds, 3600) . 'h' : 'il y a ' . intdiv($seconds, 86400) . 'j');
        }
        unset($activity);
        return $activities;
    }

    /** Friends of a member, public like the profile; unavailable when either member blocked the other, or for a banned member. */
    #[Route('/{username}/amis', name: 'friends', methods: ['GET'])]
    public function friends(string $username, UserRepository $userRepository, FriendshipRepository $friendshipRepository, MemberBlocker $memberBlocker): Response
    {
        $user = $userRepository->findOneBy(['username' => $username]) ?? throw $this->createNotFoundException('Utilisateur introuvable');
        /** @var \App\Entity\User|null $viewer */
        $viewer = $this->getUser();
        if ($viewer !== null && $viewer->getId() === $user->getId()) {
            return $this->redirectToRoute('app_friendship_list');
        }
        if (($viewer !== null && $memberBlocker->isBlockedEitherWay($viewer, $user)) || !$this->isGranted(MemberContentVoter::VIEW, $user)) {
            throw $this->createNotFoundException('Liste d\'amis indisponible');
        }
        $viewerFriendIds = $viewer !== null
            ? array_map(static fn ($friend) => $friend->getId(), $friendshipRepository->findFriendsOf($viewer))
            : [];

        return $this->render('profil/friends.html.twig', [
            'user' => $user,
            'friends' => $friendshipRepository->findFriendsOf($user),
            'viewerFriendIds' => $viewerFriendIds,
        ]);
    }

    #[Route('/{username}/gallery/upload', name: 'gallery_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadGalleryPhoto(string $username, Request $request, UserRepository $userRepository, EntityManagerInterface $em, GalleryPhotoUploader $galleryUploader, SubmissionThrottle $throttle): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $user = $userRepository->findOneBy(['username' => $username]);
        if (!$user) throw $this->createNotFoundException('Utilisateur introuvable');
        if ($user !== $currentUser || !$this->isCsrfTokenValid('gallery_upload', $request->request->get('_token'))) throw $this->createAccessDeniedException();

        if (!$throttle->tryConsumeForUser(ThrottledAction::PhotoUpload, $user)) {
            $this->addFlash('error', ThrottledAction::PhotoUpload->refusalMessage());
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
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

        // Photos of the member only, loaded in one query
        $photoIds = array_map('intval', $request->request->all('photo_ids'));
        foreach ($photoIds !== [] ? $galleryPhotoRepository->findBy(['id' => $photoIds, 'owner' => $user]) : [] as $photo) {
            if ($this->isGranted(GalleryPhotoVoter::DELETE, $photo)) {
                $galleryUploader->delete($photo);
            }
        }
        $em->flush();

        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    #[Route('/{username}/gallery/{id}/visibility', name: 'gallery_visibility', requirements: ['id' => '\d+'], methods: ['POST'])]
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
        $wantsJson = $request->getPreferredFormat() === 'json';
        if ($photo->isHiddenByModeration()) {
            $message = 'Cette photo a été masquée par la modération : elle ne peut pas être affichée à nouveau.';
            if ($wantsJson) {
                return $this->json(['error' => $message], Response::HTTP_CONFLICT);
            }
            $this->addFlash('error', $message);
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
        $photo->setIsVisible(!$photo->isVisible());
        $em->flush();
        // Asynchronous toggle: the refreshed tile replaces the current one without reloading the page
        if ($wantsJson) {
            return $this->json([
                'visible' => $photo->isVisible(),
                'tile' => $this->renderView('gallery/_photo_tile.html.twig', [
                    'photo' => $photo,
                    'user' => $user,
                    'isOwner' => true,
                    'stats' => $galleryPhotoRepository->getStatsForPhotos([$photo])[$photo->getId()],
                ]),
            ]);
        }

        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Modifier son propre profil — connecté uniquement
    #[Route('/settings/edit', name: 'edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, EntityManagerInterface $em, ProfileImageUploader $profileImageUploader): Response
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
            // Profile photo and banner: removal requested, then optional new file cropped to the chosen frame (WebP, EXIF removed)
            $previousFilenames = [];
            foreach ([ProfileImage::Avatar, ProfileImage::Cover] as $kind) {
                $previousFilenames[$kind->value] = $kind->filenameOf($user);
                if ($request->request->get('delete_' . $kind->value) === '1') {
                    $kind->assignTo($user, null);
                }
                /** @var UploadedFile|null $imageFile */
                $imageFile = $form->get($kind->value . 'File')->getData();
                $cropFrame = $request->request->get($kind->cropFieldName());
                if ($imageFile instanceof UploadedFile && ($error = $profileImageUploader->upload($user, $kind, $imageFile, is_string($cropFrame) ? $cropFrame : null))) {
                    $this->addFlash('error', $error);
                }
            }

            $em->flush();

            // Suppression des anciens fichiers remplacés ou retirés
            foreach ([ProfileImage::Avatar, ProfileImage::Cover] as $kind) {
                $profileImageUploader->deleteReplaced($user, $kind, $previousFilenames[$kind->value]);
            }

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
                $this->addFlash('error', 'Ton mot de passe actuel est incorrect.');
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

    /**
     * Deletion of the account by its member, confirmed by the password (App\Account\AccountDeleter).
     * Administrators keep their account: another administrator removes it from the back office.
     */
    #[Route('/settings/delete', name: 'delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Request $request, UserPasswordHasherInterface $passwordHasher, AccountDeleter $accountDeleter, Security $security): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $settingsUrl = $this->generateUrl('app_profil_edit') . '#suppression';
        if (!$this->isCsrfTokenValid(self::DELETE_ACCOUNT_CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        if ($this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('error', 'Un compte administrateur ne se supprime pas depuis les paramètres : retire d\'abord ton rôle depuis l\'administration.');
            return $this->redirect($settingsUrl);
        }
        if ($request->request->get('confirm') !== '1' || !$passwordHasher->isPasswordValid($user, $request->request->getString('password'))) {
            $this->addFlash('error', 'Mot de passe incorrect ou confirmation manquante : ton compte n\'a pas été supprimé.');
            return $this->redirect($settingsUrl);
        }
        $accountDeleter->delete($user, 'Tu as demandé la suppression de ton compte depuis tes paramètres.');
        $security->logout(false);
        $this->addFlash('success', 'Ton compte est supprimé. Merci d\'avoir fait partie de la communauté.');
        return $this->redirectToRoute('app_home');
    }
}
