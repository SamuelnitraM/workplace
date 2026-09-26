<?php

namespace App\Service;

use App\Entity\User;
use App\Profile\ProfileImage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints\File;

/**
 * Profile images (profile photo, banner): shared validation (profile form, guided presentation), WebP re-encoding
 * (EXIF metadata removed) and removal of the replaced file.
 *
 * Usage: $old = ProfileImage::Cover->filenameOf($user); $error = $uploader->upload($user, ProfileImage::Cover, $file);
 *        $em->flush(); $uploader->deleteReplaced($user, ProfileImage::Cover, $old);
 */
final class ProfileImageUploader
{
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const QUALITY = 85;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads')] private string $uploadsDirectory,
        private SluggerInterface $slugger,
        private ImageOptimizerService $imageOptimizer,
    ) {}

    /** Validation constraint of the file field (size, formats). */
    public static function fileConstraint(ProfileImage $kind): File
    {
        return new File(
            maxSize: $kind->maxFileSize(),
            mimeTypes: self::MIME_TYPES,
            mimeTypesMessage: 'Formats acceptés : JPG, PNG, WEBP',
        );
    }

    /**
     * Stores the file (already validated by fileConstraint()) and assigns it to the member (no flush).
     * Returns null on success, otherwise an error message in French (the current image is kept).
     */
    public function upload(User $user, ProfileImage $kind, UploadedFile $file): ?string
    {
        $failure = sprintf('Erreur lors de l\'envoi de %s.', $kind->label());
        if (!$file->isValid()) {
            return $failure;
        }
        $directory = $this->directoryOf($kind);
        $safeFilename = $this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->lower()->slice(0, 80);
        $newFilename = $safeFilename . '-' . uniqid() . '.webp';
        try {
            $file->move($directory, $newFilename);
        } catch (FileException) {
            return $failure;
        }
        if (!$this->imageOptimizer->optimizeToWebp($directory . '/' . $newFilename, $kind->maxDimension(), self::QUALITY)) {
            @unlink($directory . '/' . $newFilename);
            return sprintf('%s n’a pas pu être traitée (image invalide ou dimensions trop grandes).', ucfirst($kind->label()));
        }
        $kind->assignTo($user, $newFilename);
        return null;
    }

    /** To be called AFTER the flush: removes the previous file when it was replaced or removed. */
    public function deleteReplaced(User $user, ProfileImage $kind, ?string $previousFilename): void
    {
        if ($previousFilename && $previousFilename !== $kind->filenameOf($user)) {
            $previousPath = $this->directoryOf($kind) . '/' . basename($previousFilename);
            if (is_file($previousPath)) {
                @unlink($previousPath);
            }
        }
    }

    private function directoryOf(ProfileImage $kind): string
    {
        return $this->uploadsDirectory . '/' . $kind->folder();
    }
}
