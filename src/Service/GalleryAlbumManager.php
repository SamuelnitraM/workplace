<?php

namespace App\Service;

use App\Entity\GalleryAlbum;
use App\Entity\GalleryPhoto;
use App\Entity\User;
use App\Repository\GalleryAlbumRepository;
use App\Repository\GalleryPhotoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Albums of a member's gallery. Photos are always resolved among the member's own photos: ids of other
 * members' photos are ignored.
 */
class GalleryAlbumManager
{
    public function __construct(
        private readonly GalleryAlbumRepository $albumRepository,
        private readonly GalleryPhotoRepository $photoRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<int|string> $photoIds
     * @return GalleryAlbum|string the album, or an error message
     */
    public function create(User $owner, string $name, array $photoIds): GalleryAlbum|string
    {
        $name = trim($name);
        $error = $this->validateName($name);
        if ($error !== null) {
            return $error;
        }
        if ($this->albumRepository->count(['owner' => $owner]) >= GalleryAlbum::MAX_ALBUMS_PER_MEMBER) {
            return sprintf('Tu as déjà %d albums : supprimes-en un pour en créer un nouveau.', GalleryAlbum::MAX_ALBUMS_PER_MEMBER);
        }
        $album = new GalleryAlbum($owner, $name);
        $this->em->persist($album);
        $this->movePhotos($owner, $photoIds, $album);
        return $album;
    }

    public function rename(GalleryAlbum $album, string $name): ?string
    {
        $name = trim($name);
        $error = $this->validateName($name);
        if ($error !== null) {
            return $error;
        }
        $album->setName($name);
        $this->em->flush();
        return null;
    }

    /**
     * Moves the member's selected photos into an album, or back to the gallery root when $album is null.
     *
     * @param list<int|string> $photoIds
     * @return int number of photos moved
     */
    public function movePhotos(User $owner, array $photoIds, ?GalleryAlbum $album): int
    {
        $photos = $this->ownedPhotos($owner, $photoIds);
        foreach ($photos as $photo) {
            $photo->setAlbum($album);
        }
        $this->em->flush();
        return count($photos);
    }

    /** The photos go back to the gallery root (ON DELETE SET NULL). */
    public function delete(GalleryAlbum $album): void
    {
        foreach ($album->getPhotos() as $photo) {
            $photo->setAlbum(null);
        }
        $this->em->remove($album);
        $this->em->flush();
    }

    /** Cover of an album: its most recent photo the viewer may see. */
    public static function coverOf(GalleryAlbum $album, bool $includeHidden): ?GalleryPhoto
    {
        foreach ($album->getPhotos() as $photo) {
            if ($includeHidden || $photo->isVisible()) {
                return $photo;
            }
        }
        return null;
    }

    private function validateName(string $name): ?string
    {
        $length = mb_strlen($name);
        return $length === 0 || $length > GalleryAlbum::NAME_MAX_LENGTH
            ? sprintf('Le nom de l\'album doit contenir entre 1 et %d caractères.', GalleryAlbum::NAME_MAX_LENGTH)
            : null;
    }

    /**
     * @param list<int|string> $photoIds
     * @return GalleryPhoto[]
     */
    private function ownedPhotos(User $owner, array $photoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $photoIds))));
        return $ids === [] ? [] : $this->photoRepository->findBy(['id' => $ids, 'owner' => $owner]);
    }
}
