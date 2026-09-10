<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ArmyListRepository;
use App\Repository\FriendshipRepository;
use App\Repository\GalleryPhotoRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Dto\UserProfileUpdateDto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ProfilService
{
    public function __construct(
        private UserRepository $userRepository,
        private FriendshipRepository $friendshipRepository,
        private ArmyListRepository $armyListRepository,
        private GalleryPhotoRepository $galleryPhotoRepository,
        private GroupRepository $groupRepository,
        private GroupMemberRepository $groupMemberRepository,
        private GamificationService $gamification,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
        private ImageOptimizerService $imageOptimizer,
        private CacheInterface $cache
    ) {}

    public function getUserProfileData(string $username, ?User $currentUser, int $page = 1): array
    {
        $currentUserIdentifier = $currentUser ? $currentUser->getUserIdentifier() : 'guest';
        $cacheKey = sprintf('user_profile_%s_%s_p%d', $username, md5($currentUserIdentifier), $page);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($username, $currentUser, $page) {
            $item->expiresAfter(3600); // Cache for 1 hour

            $user = $this->userRepository->findOneBy(['username' => $username]);

            if (!$user) {
                return [];
            }

            $this->gamification->syncAllBadges($user);
            $this->em->flush();

            $isOwner = $currentUser && $currentUser->getUserIdentifier() === $user->getEmail();
            
            $galleryPhotos = $isOwner 
                ? $this->galleryPhotoRepository->findByOwner($user) 
                : $this->galleryPhotoRepository->findVisibleByOwner($user);

            $publicArmyLists = $this->armyListRepository->findBy(['owner' => $user, 'isPublic' => true], ['createdAt' => 'DESC']);
            $friends = $this->friendshipRepository->findAcceptedFriends($user);

            $activities = $this->buildActivityFeed($user, $publicArmyLists, $galleryPhotos, $friends, $page);

            $friendship = null;
            if ($currentUser && !$isOwner) {
                $friendship = $this->friendshipRepository->findExisting($currentUser, $user);
            }

            $myGroups = [];
            if ($currentUser && !$isOwner) {
                $myGroups = $this->getInviteableGroups($currentUser, $user);
            }

            return [
                'user' => $user,
                'isOwner' => $isOwner,
                'friendship' => $friendship,
                'myGroups' => $myGroups,
                'publicArmyLists' => $publicArmyLists,
                'galleryPhotos' => $galleryPhotos,
                'friends' => $friends,
                'activities' => $activities,
                'profileBadges' => $this->gamification->getProfileBadges($user),
            ];
        });
    }

    private function buildActivityFeed(User $user, array $publicArmyLists, array $galleryPhotos, array $friends, int $page): array
    {
        $activities = [];
        foreach ($user->getPosts() as $post) {
            $activities[] = ['date' => $post->getCreatedAt(), 'label' => 'a posté dans', 'subject' => $post->getThread()->getTitle(), 'url' => null];
        }
        foreach ($publicArmyLists as $list) {
            $activities[] = ['date' => $list->getCreatedAt(), 'label' => 'a créé la liste', 'subject' => $list->getName(), 'url' => 'app_army_show', 'parameters' => ['id' => $list->getId()]];
        }
        foreach ($galleryPhotos as $photo) {
            $activities[] = ['date' => $photo->getCreatedAt(), 'label' => 'a ajouté une photo', 'subject' => null, 'url' => null];
        }
        foreach ($friends as $friendship) {
            $friend = $friendship->getRequester() === $user ? $friendship->getReceiver() : $friendship->getRequester();
            $activities[] = ['date' => $friendship->getCreatedAt(), 'label' => 'est devenu ami avec', 'subject' => $friend->getUsername(), 'url' => 'app_profil_show', 'parameters' => ['username' => $friend->getUsername()]];
        }
        foreach ($user->getGroupMembers() as $membership) {
            if ($membership->getUsergroup()->isPublic()) {
                $activities[] = ['date' => $membership->getJoinedAt(), 'label' => 'a rejoint le groupe', 'subject' => $membership->getUsergroup()->getName(), 'url' => null];
            }
        }

        usort($activities, static fn (array $left, array $right) => $right['date'] <=> $left['date']);
        
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $activities = array_slice($activities, $offset, $limit);

        foreach ($activities as &$activity) {
            $seconds = max(0, time() - $activity['date']->getTimestamp());
            $activity['time'] = $seconds < 3600 ? 'il y a ' . max(1, intdiv($seconds, 60)) . ' min' : ($seconds < 86400 ? 'il y a ' . intdiv($seconds, 3600) . 'h' : 'il y a ' . intdiv($seconds, 86400) . 'j');
        }
        unset($activity);

        return $activities;
    }

    private function getInviteableGroups(User $currentUser, User $user): array
    {
        $allMyGroups = $this->groupRepository->findGroupsByMember($currentUser);

        return array_filter($allMyGroups, function($group) use ($currentUser, $user) {
            $myMembership = $this->groupMemberRepository->findOneBy([
                'user' => $currentUser,
                'usergroup' => $group,
            ]);

            if (!$myMembership || !in_array($myMembership->getRole(), ['owner', 'admin', 'member'])) {
                return false;
            }

            $targetMembership = $this->groupMemberRepository->findOneBy([
                'user' => $user,
                'usergroup' => $group,
            ]);

            return $targetMembership === null;
        });
    }

    public function uploadGalleryPhoto(User $user, UploadedFile $file, string $galleryDirectory): string
    {
        $filename = $this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '-' . uniqid() . '.webp';
        $file->move($galleryDirectory, $filename);
        
        $sourcePath = $galleryDirectory . '/' . $filename;
        if (!$this->imageOptimizer->optimizeToWebp($sourcePath)) {
            @unlink($sourcePath);
            throw new \RuntimeException('La photo n’a pas pu être traitée.');
        }

        $photo = new \App\Entity\GalleryPhoto();
        $photo->setFilename($filename);
        $photo->setOwner($user);
        
        $this->em->persist($photo);
        $this->em->flush();

        return $filename;
    }

    public function getGalleryPhotosForUser(User $user): array
    {
        return $this->galleryPhotoRepository->findByOwner($user);
    }

    public function deleteGalleryPhotos(array $photoIds, User $user, string $galleryDirectory): void
    {
        foreach ($photoIds as $photoId) {
            $photo = $this->galleryPhotoRepository->find((int) $photoId);
            if ($photo && $photo->getOwner() === $user) {
                $this->em->remove($photo);
                $path = $galleryDirectory . '/' . $photo->getFilename();
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        $this->em->flush();
    }

    public function togglePhotoVisibility(int $id, User $user): void
    {
        $photo = $this->galleryPhotoRepository->find($id);
        if (!$photo || $photo->getOwner() !== $user) {
            throw new \RuntimeException('Photo introuvable ou accès refusé.');
        }
        $photo->setIsVisible(!$photo->isVisible());
        $this->em->flush();
    }

    public function updateProfile(User $user, UserProfileUpdateDto $dto): void
    {
        if ($dto->username !== null) {
            $user->setUsername($dto->username);
        }
        if ($dto->bio !== null) {
            $user->setBio($dto->bio);
        }
        if ($dto->favoriteFaction !== null) {
            $user->setFavoriteFaction($dto->favoriteFaction);
        }
        if ($dto->showActivity !== null) {
            $user->setShowActivity($dto->showActivity);
        }

        $this->em->flush();
        
        // Invalidate cache for this user
        // We can't easily clear all pages, but we can at least clear the most common one
        // or implement a more robust cache tagging system later.
        // For now, we just let it expire or we could iterate common pages.
    }
}
