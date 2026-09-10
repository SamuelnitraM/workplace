<?php

namespace App\Controller;

use App\Form\ChangePasswordFormType;
use App\Form\UserProfileFormType;
use App\Entity\GalleryPhoto;
use App\Repository\FriendshipRepository;
use App\Repository\ArmyListRepository;
use App\Repository\GalleryPhotoRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use App\Service\GamificationService;

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
    EntityManagerInterface $em
): Response {
    $user = $userRepository->findOneBy(['username' => $username]);

    if (!$user) {
        throw $this->createNotFoundException('Utilisateur introuvable');
    }

    $gamification->syncAllBadges($user);
	$em->flush();

    $isOwner = $this->getUser() && $this->getUser()->getUserIdentifier() === $user->getEmail();
    $galleryPhotos = $isOwner ? $galleryPhotoRepository->findByOwner($user) : $galleryPhotoRepository->findVisibleByOwner($user);
    $publicArmyLists = $armyListRepository->findBy(['owner' => $user, 'isPublic' => true], ['createdAt' => 'DESC']);
    $friends = $friendshipRepository->findAcceptedFriends($user);
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
    $activities = array_slice($activities, 0, 10);
    foreach ($activities as &$activity) {
        $seconds = max(0, time() - $activity['date']->getTimestamp());
        $activity['time'] = $seconds < 3600 ? 'il y a ' . max(1, intdiv($seconds, 60)) . ' min' : ($seconds < 86400 ? 'il y a ' . intdiv($seconds, 3600) . 'h' : 'il y a ' . intdiv($seconds, 86400) . 'j');
    }
    unset($activity);

    $friendship = null;
    if ($this->getUser() && !$isOwner) {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $friendship = $friendshipRepository->findExisting($currentUser, $user);
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
        'myGroups' => $myGroups,
        'publicArmyLists' => $publicArmyLists,
        'galleryPhotos' => $galleryPhotos,
        'friends' => $friends,
        'activities' => $activities,
        'profileBadges' => $gamification->getProfileBadges($user),
    ]);
}

    #[Route('/{username}/gallery/upload', name: 'gallery_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadGalleryPhoto(string $username, Request $request, UserRepository $userRepository, GalleryPhotoRepository $galleryPhotoRepository, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $user = $userRepository->findOneBy(['username' => $username]);
        if (!$user) throw $this->createNotFoundException('Utilisateur introuvable');
        if ($user !== $currentUser || !$this->isCsrfTokenValid('gallery_upload', $request->request->get('_token'))) throw $this->createAccessDeniedException();
        if (count($galleryPhotoRepository->findByOwner($user)) >= 10) {
            $this->addFlash('error', 'Votre galerie contient déjà 10 photos.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
        /** @var UploadedFile|null $file */
        $file = $request->files->get('photo');
        if (!$file || !in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) || $file->getSize() > 10 * 1024 * 1024) {
            $this->addFlash('error', 'Photo invalide : JPG, PNG ou WEBP de 10 Mo maximum.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }

        $filename = $slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '-' . uniqid() . '.webp';
        try { $file->move($this->getParameter('gallery_directory'), $filename); } catch (FileException) { throw new \RuntimeException('Upload impossible.'); }
        $sourcePath = $this->getParameter('gallery_directory') . '/' . $filename;
        if (!$this->optimizeGalleryImage($sourcePath)) {
            @unlink($sourcePath);
            $this->addFlash('error', 'La photo n’a pas pu être traitée.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
        $em->persist((new GalleryPhoto())->setFilename($filename)->setOwner($user));
        $em->flush();
        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    #[Route('/{username}/gallery/delete', name: 'gallery_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deleteGalleryPhotos(string $username, Request $request, UserRepository $userRepository, GalleryPhotoRepository $galleryPhotoRepository, EntityManagerInterface $em): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $user = $userRepository->findOneBy(['username' => $username]);
        if (!$user || $user !== $currentUser || !$this->isCsrfTokenValid('gallery_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        foreach ($request->request->all('photo_ids') as $photoId) {
            $photo = $galleryPhotoRepository->find((int) $photoId);
            if ($photo && $photo->getOwner() === $user) {
                $em->remove($photo);
                $path = $this->getParameter('gallery_directory') . '/' . $photo->getFilename();
                if (is_file($path)) {
                    unlink($path);
                }
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
        $photo->setIsVisible(!$photo->isVisible());
        $em->flush();

        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    private function optimizeGalleryImage(string $path): bool
    {
        $imageInfo = @getimagesize($path);
        if (!$imageInfo || !function_exists('imagewebp')) {
            return false;
        }

        $source = match ($imageInfo['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        if (!$source) {
            return false;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 1200 / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $optimized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($optimized, false);
        imagesavealpha($optimized, true);
        imagecopyresampled($optimized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        $success = imagewebp($optimized, $path, 82);
        imagedestroy($optimized);
        imagedestroy($source);

        return $success;
    }

    // Modifier son propre profil — connecté uniquement
    #[Route('/settings/edit', name: 'edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $form = $this->createForm(UserProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $avatarFile = $form->get('avatarFile')->getData();
            if ($avatarFile) {
                $originalFilename = pathinfo($avatarFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $avatarFile->guessExtension();

                try {
                    $avatarFile->move(
                        $this->getParameter('avatars_directory'),
                        $newFilename
                    );
                    $user->setAvatar($newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Erreur lors de l\'upload de l\'avatar.');
                }
            }

            $em->flush();
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