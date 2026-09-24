<?php

namespace App\Controller;

use App\Entity\GalleryAlbum;
use App\Entity\User;
use App\Http\SafeReferer;
use App\Repository\GalleryAlbumRepository;
use App\Repository\GalleryPhotoRepository;
use App\Service\GalleryAlbumManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Albums of a member's gallery: album page, creation from a selection, moving photos, renaming, deletion. */
#[Route('/profil/{username}/album', name: 'app_gallery_album_')]
class GalleryAlbumController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'gallery_album';

    public function __construct(
        private readonly GalleryAlbumManager $albumManager,
        private readonly GalleryAlbumRepository $albumRepository,
    ) {
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $username, int $id, GalleryPhotoRepository $photoRepository): Response
    {
        $album = $this->findAlbum($username, $id);
        $isOwner = $this->getUser() === $album->getOwner();
        $photos = array_values(array_filter($album->getPhotos()->toArray(), static fn ($photo) => $isOwner || $photo->isVisible()));
        return $this->render('gallery/album.html.twig', [
            'album' => $album,
            'user' => $album->getOwner(),
            'isOwner' => $isOwner,
            'photos' => $photos,
            'galleryStats' => $photoRepository->getStatsForPhotos($photos),
            'otherAlbums' => $isOwner ? array_filter($this->albumRepository->findByOwner($album->getOwner()), static fn (GalleryAlbum $other) => $other !== $album) : [],
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(string $username, Request $request): Response
    {
        $owner = $this->currentOwner($username, $request);
        $result = $this->albumManager->create($owner, (string) $request->request->get('name'), $request->request->all('photo_ids'));
        if (is_string($result)) {
            $this->addFlash('error', $result);
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }
        $this->addFlash('success', sprintf('Album « %s » créé.', $result->getName()));
        return $this->redirectToRoute('app_gallery_album_show', ['username' => $username, 'id' => $result->getId()]);
    }

    #[Route('/{id}/ajouter', name: 'add', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function add(string $username, int $id, Request $request): Response
    {
        $owner = $this->currentOwner($username, $request);
        $album = $this->ownedAlbum($owner, $id);
        $moved = $this->albumManager->movePhotos($owner, $request->request->all('photo_ids'), $album);
        $this->addFlash('success', sprintf('%d photo%s ajoutée%s à « %s ».', $moved, $moved > 1 ? 's' : '', $moved > 1 ? 's' : '', $album->getName()));
        return $this->redirect(SafeReferer::urlOr($request, $this->generateUrl('app_profil_show', ['username' => $username])));
    }

    #[Route('/{id}/retirer', name: 'remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function remove(string $username, int $id, Request $request): Response
    {
        $owner = $this->currentOwner($username, $request);
        $album = $this->ownedAlbum($owner, $id);
        $moved = $this->albumManager->movePhotos($owner, $request->request->all('photo_ids'), null);
        $this->addFlash('success', sprintf('%d photo%s retirée%s de l\'album.', $moved, $moved > 1 ? 's' : '', $moved > 1 ? 's' : ''));
        return $this->redirectToRoute('app_gallery_album_show', ['username' => $username, 'id' => $album->getId()]);
    }

    #[Route('/{id}/renommer', name: 'rename', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function rename(string $username, int $id, Request $request): Response
    {
        $album = $this->ownedAlbum($this->currentOwner($username, $request), $id);
        $error = $this->albumManager->rename($album, (string) $request->request->get('name'));
        $this->addFlash($error === null ? 'success' : 'error', $error ?? 'Album renommé.');
        return $this->redirectToRoute('app_gallery_album_show', ['username' => $username, 'id' => $album->getId()]);
    }

    #[Route('/{id}/supprimer', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(string $username, int $id, Request $request): Response
    {
        $album = $this->ownedAlbum($this->currentOwner($username, $request), $id);
        $this->albumManager->delete($album);
        $this->addFlash('success', 'Album supprimé : ses photos sont de retour dans la galerie.');
        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    private function findAlbum(string $username, int $id): GalleryAlbum
    {
        $album = $this->albumRepository->find($id);
        if ($album === null || $album->getOwner()->getUsername() !== $username) {
            throw $this->createNotFoundException('Album introuvable');
        }
        return $album;
    }

    /** The current member, owner of the gallery of the URL, after the CSRF check. */
    private function currentOwner(string $username, Request $request): User
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->getUsername() !== $username || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

    private function ownedAlbum(User $owner, int $id): GalleryAlbum
    {
        $album = $this->albumRepository->find($id);
        if ($album === null || $album->getOwner() !== $owner) {
            throw $this->createNotFoundException('Album introuvable');
        }
        return $album;
    }
}
