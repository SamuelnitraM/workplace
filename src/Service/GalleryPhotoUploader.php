<?php

namespace App\Service;

use App\Entity\GalleryPhoto;
use App\Entity\User;
use App\Repository\GalleryPhotoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Ajout d'une photo à la galerie d'un membre (profil, présentation guidée) : limites communes,
 * ré-encodage WebP 1200 px (suppression des métadonnées EXIF), création du GalleryPhoto.
 */
final class GalleryPhotoUploader
{
    public const MAX_PHOTOS = 10;
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        #[Autowire('%gallery_directory%')] private string $galleryDirectory,
        private GalleryPhotoRepository $galleryPhotoRepository,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
        private ImageOptimizerService $imageOptimizer,
    ) {}

    public function isFull(User $owner): bool
    {
        return $this->galleryPhotoRepository->count(['owner' => $owner]) >= self::MAX_PHOTOS;
    }

    /**
     * Valide, enregistre et persiste la photo (sans flush).
     * Retourne la photo créée, ou un message d'erreur en français.
     */
    public function upload(User $owner, ?UploadedFile $file, string $description): GalleryPhoto|string
    {
        if ($this->isFull($owner)) {
            return sprintf('Votre galerie contient déjà %d photos.', self::MAX_PHOTOS);
        }
        $description = trim($description);
        if (mb_strlen($description) > GalleryPhoto::DESCRIPTION_MAX_LENGTH) {
            return sprintf('La description ne doit pas dépasser %d caractères.', GalleryPhoto::DESCRIPTION_MAX_LENGTH);
        }
        if (!$file instanceof UploadedFile || !$file->isValid() || !in_array($file->getMimeType(), self::MIME_TYPES, true) || $file->getSize() > self::MAX_BYTES) {
            return 'Photo invalide : JPG, PNG ou WEBP de 10 Mo maximum.';
        }

        $filename = $this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->lower()->slice(0, 80) . '-' . uniqid() . '.webp';
        try {
            $file->move($this->galleryDirectory, $filename);
        } catch (FileException) {
            return 'L’envoi de la photo a échoué, veuillez réessayer.';
        }
        $sourcePath = $this->galleryDirectory . '/' . $filename;
        if (!$this->imageOptimizer->optimizeToWebp($sourcePath, 1200, 82)) {
            @unlink($sourcePath);

            return 'La photo n’a pas pu être traitée (image invalide ou dimensions trop grandes).';
        }

        $photo = (new GalleryPhoto())->setFilename($filename)->setOwner($owner)->setDescription($description);
        $this->em->persist($photo);

        return $photo;
    }

    /**
     * Removes the photo (without flush) and its file. Likes and comments are deleted by the database (ON DELETE CASCADE).
     */
    public function delete(GalleryPhoto $photo): void
    {
        $this->em->remove($photo);
        $path = $this->galleryDirectory . '/' . basename((string) $photo->getFilename());
        if (is_file($path)) {
            unlink($path);
        }
    }
}
