<?php

namespace App\Service;

use App\Image\ImageCrop;

class ImageOptimizerService
{
    /** Nombre maximal de pixels accepté (protection contre les « pixel bombs »). */
    public const MAX_PIXELS = 40_000_000;

    /**
     * Ré-encode une image en WebP (ce qui supprime les métadonnées EXIF) et la redimensionne si nécessaire.
     * The EXIF orientation of photos is applied first, so that the image is stored the way it is displayed;
     * with a crop, only the chosen rectangle is kept (see App\Image\ImageCrop).
     *
     * @param string $path Chemin absolu du fichier image, réécrit sur place.
     * @param int $maxWidth Largeur ou hauteur maximale de l'image.
     * @param int $quality Qualité WebP (0-100).
     * @return bool true en cas de succès, false si l'image est invalide, trop grande ou non traitable.
     */
    public function optimizeToWebp(string $path, int $maxWidth = 1200, int $quality = 82, ?ImageCrop $crop = null): bool
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
        if ($imageInfo['mime'] === 'image/jpeg') {
            $source = $this->applyExifOrientation($source, $path);
        }
        $area = $crop?->areaWithin(imagesx($source), imagesy($source))
            ?? ['x' => 0, 'y' => 0, 'width' => imagesx($source), 'height' => imagesy($source)];
        $scale = min(1, $maxWidth / max($area['width'], $area['height']));
        $targetWidth = max(1, (int) round($area['width'] * $scale));
        $targetHeight = max(1, (int) round($area['height'] * $scale));
        $optimized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($optimized, false);
        imagesavealpha($optimized, true);
        imagecopyresampled($optimized, $source, 0, 0, $area['x'], $area['y'], $targetWidth, $targetHeight, $area['width'], $area['height']);
        $success = imagewebp($optimized, $path, $quality);
        imagedestroy($optimized);
        imagedestroy($source);
        return $success;
    }

    /** Rotates and mirrors a JPEG photo according to its EXIF orientation (phones store portrait photos rotated). */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) ((@exif_read_data($path) ?: [])['Orientation'] ?? 1);
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            6, 7 => 270,
            5, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);
        return $rotated;
    }
}
