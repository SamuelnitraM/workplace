<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints\File;

/**
 * Photo de profil : validation commune (formulaire de profil, présentation guidée), ré-encodage WebP 512 px
 * (suppression des métadonnées EXIF) et suppression de l'ancien fichier.
 *
 * Usage : $old = $user->getAvatar(); $error = $uploader->upload($user, $file); $em->flush(); $uploader->deleteReplaced($old, $user);
 */
final class AvatarUploader
{
    public const MAX_SIZE = '2M';
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_DIMENSION = 512;
    private const QUALITY = 85;

    public function __construct(
        #[Autowire('%avatars_directory%')] private string $avatarsDirectory,
        private SluggerInterface $slugger,
        private ImageOptimizerService $imageOptimizer,
    ) {}

    /** Contrainte de validation du champ fichier (taille, formats). */
    public static function fileConstraint(): File
    {
        return new File(
            maxSize: self::MAX_SIZE,
            mimeTypes: self::MIME_TYPES,
            mimeTypesMessage: 'Formats acceptés : JPG, PNG, WEBP',
        );
    }

    /**
     * Enregistre le fichier (déjà validé par fileConstraint()) et l'affecte à l'utilisateur (sans flush).
     * Retourne null en cas de succès, sinon un message d'erreur en français (l'avatar actuel est conservé).
     */
    public function upload(User $user, UploadedFile $file): ?string
    {
        if (!$file->isValid()) {
            return 'Erreur lors de l\'envoi de la photo de profil.';
        }

        $safeFilename = $this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->lower()->slice(0, 80);
        $newFilename = $safeFilename . '-' . uniqid() . '.webp';

        try {
            $file->move($this->avatarsDirectory, $newFilename);
        } catch (FileException) {
            return 'Erreur lors de l\'envoi de la photo de profil.';
        }

        if (!$this->imageOptimizer->optimizeToWebp($this->avatarsDirectory . '/' . $newFilename, self::MAX_DIMENSION, self::QUALITY)) {
            @unlink($this->avatarsDirectory . '/' . $newFilename);

            return 'La photo de profil n’a pas pu être traitée (image invalide ou dimensions trop grandes).';
        }

        $user->setAvatar($newFilename);

        return null;
    }

    /** À appeler APRÈS le flush : supprime l'ancien fichier s'il a été remplacé ou retiré. */
    public function deleteReplaced(?string $oldAvatar, User $user): void
    {
        if ($oldAvatar && $oldAvatar !== $user->getAvatar()) {
            $oldPath = $this->avatarsDirectory . '/' . basename($oldAvatar);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }
    }
}
