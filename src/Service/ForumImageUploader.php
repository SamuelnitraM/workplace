<?php

namespace App\Service;

use App\Entity\ForumImage;
use App\Entity\User;
use App\Forum\ForumMarkdown;
use App\Repository\ForumImageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Images insérées dans les messages du forum : ré-encodage WebP 1600 px (métadonnées EXIF supprimées),
 * nom de fichier aléatoire, limite d'envois par membre et par jour (anti-abus).
 */
final class ForumImageUploader
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const MAX_PER_DAY = 30;

    public function __construct(
        #[Autowire('%forum_images_directory%')] private string $directory,
        private ForumImageRepository $repository,
        private EntityManagerInterface $em,
        private ImageOptimizerService $imageOptimizer,
    ) {}

    /** Enregistre l'image et retourne son URL publique (/uploads/forum/…), ou un message d'erreur en français. */
    public function upload(User $uploader, ?UploadedFile $file): array
    {
        if ($this->repository->countSince($uploader, new \DateTimeImmutable('-1 day')) >= self::MAX_PER_DAY) {
            return ['error' => sprintf('Limite atteinte : %d images par jour au maximum.', self::MAX_PER_DAY)];
        }
        if (!$file instanceof UploadedFile || !$file->isValid() || !in_array($file->getMimeType(), self::MIME_TYPES, true) || $file->getSize() > self::MAX_BYTES) {
            return ['error' => 'Image invalide : JPG, PNG ou WEBP de 8 Mo maximum.'];
        }

        (new Filesystem())->mkdir($this->directory);
        $filename = bin2hex(random_bytes(12)) . '-' . dechex(time()) . '.webp';
        try {
            $file->move($this->directory, $filename);
        } catch (FileException) {
            return ['error' => 'L’envoi de l’image a échoué, veuillez réessayer.'];
        }
        $path = $this->directory . '/' . $filename;
        if (!$this->imageOptimizer->optimizeToWebp($path, 1600, 82)) {
            @unlink($path);

            return ['error' => 'L’image n’a pas pu être traitée (fichier invalide ou dimensions trop grandes).'];
        }

        $this->em->persist(new ForumImage($filename, $uploader));
        $this->em->flush();

        return ['url' => ForumMarkdown::IMAGE_PATH_PREFIX . $filename];
    }
}
