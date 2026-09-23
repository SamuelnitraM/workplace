<?php

namespace App\Service;

class ImageOptimizerService
{
    /** Nombre maximal de pixels accepté (protection contre les « pixel bombs »). */
    public const MAX_PIXELS = 40_000_000;

    /**
     * Ré-encode une image en WebP (ce qui supprime les métadonnées EXIF) et la redimensionne si nécessaire.
     *
     * @param string $path Chemin absolu du fichier image, réécrit sur place.
     * @param int $maxWidth Largeur ou hauteur maximale de l'image.
     * @param int $quality Qualité WebP (0-100).
     * @return bool true en cas de succès, false si l'image est invalide, trop grande ou non traitable.
     */
    public function optimizeToWebp(string $path, int $maxWidth = 1200, int $quality = 82): bool
    {
        $imageInfo = @getimagesize($path);
        if (!$imageInfo || !function_exists('imagewebp')) {
            return false;
        }

        [$declaredWidth, $declaredHeight] = $imageInfo;
        if ($declaredWidth < 1 || $declaredHeight < 1 || $declaredWidth * $declaredHeight > self::MAX_PIXELS) {
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

        // imagewebp() refuse les images en palette (PNG 8 bits)
        if (!imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxWidth / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $optimized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($optimized, false);
        imagesavealpha($optimized, true);
        imagecopyresampled($optimized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $success = imagewebp($optimized, $path, $quality);

        imagedestroy($optimized);
        imagedestroy($source);

        return $success;
    }
}
