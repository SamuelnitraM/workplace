<?php

namespace App\Service;

class ImageOptimizerService
{
    /**
     * Optimizes an image by converting it to WebP and resizing it if necessary.
     *
     * @param string $path Absolute path to the image file.
     * @param int $maxWidth Maximum width or height for the image.
     * @param int $quality WebP quality (0-100).
     * @return bool True on success, false on failure.
     */
    public function optimizeToWebp(string $path, int $maxWidth = 1200, int $quality = 82): bool
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
        $scale = min(1, $maxWidth / max($width, $height));
        
        if ($scale < 1) {
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $optimized = imagecreatetruecolor($targetWidth, $targetHeight);
            
            imagealphablending($optimized, false);
            imagesavealpha($optimized, true);
            imagecopyresampled($optimized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        } else {
            $optimized = $source;
        }

        $success = imagewebp($optimized, $path, $quality);

        if ($optimized !== $source) {
            imagedestroy($optimized);
        }
        imagedestroy($source);

        return $success;
    }
}
