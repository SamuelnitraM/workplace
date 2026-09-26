<?php

namespace App\Image;

/**
 * Rectangle kept from an image, with a fixed aspect ratio (width / height).
 *
 * The requested rectangle comes from the crop frame chosen in the browser (natural pixels of the image once
 * rotated by its EXIF orientation). It is clamped to the image and brought back to the aspect ratio around
 * its centre; without a request, the largest centred rectangle of the aspect ratio is kept.
 */
final class ImageCrop
{
    /**
     * @param array{0: int, 1: int, 2: int, 3: int}|null $requested x, y, width, height
     */
    public function __construct(
        public readonly float $aspectRatio,
        public readonly ?array $requested = null,
    ) {
    }

    /** Crop from the "x,y,width,height" value sent by the crop frame (ignored when malformed). */
    public static function fromFrameValue(float $aspectRatio, ?string $frameValue): self
    {
        if ($frameValue === null || !preg_match('/^(\d{1,6}),(\d{1,6}),(\d{1,6}),(\d{1,6})$/D', trim($frameValue), $matches)) {
            return new self($aspectRatio);
        }
        [, $x, $y, $width, $height] = array_map('intval', $matches);
        return new self($aspectRatio, $width > 0 && $height > 0 ? [$x, $y, $width, $height] : null);
    }

    /**
     * Rectangle to keep inside an image of the given size.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function areaWithin(int $imageWidth, int $imageHeight): array
    {
        if ($this->requested === null) {
            return $this->fitAspect($imageWidth / 2, $imageHeight / 2, $imageWidth, $imageHeight, $imageWidth, $imageHeight);
        }
        [$x, $y, $width, $height] = $this->requested;
        $x = min(max(0, $x), $imageWidth - 1);
        $y = min(max(0, $y), $imageHeight - 1);
        $width = min($width, $imageWidth - $x);
        $height = min($height, $imageHeight - $y);
        return $this->fitAspect($x + $width / 2, $y + $height / 2, $width, $height, $imageWidth, $imageHeight);
    }

    /**
     * Largest rectangle of the aspect ratio inside the given box, centred on the given point and kept inside the image.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    private function fitAspect(float $centerX, float $centerY, float $boxWidth, float $boxHeight, int $imageWidth, int $imageHeight): array
    {
        $width = $boxWidth / $boxHeight > $this->aspectRatio ? $boxHeight * $this->aspectRatio : $boxWidth;
        $width = max(1, (int) round(min($width, $imageWidth, $imageHeight * $this->aspectRatio)));
        $height = max(1, (int) round(min($width / $this->aspectRatio, $imageHeight)));
        $x = (int) round(min(max(0, $centerX - $width / 2), $imageWidth - $width));
        $y = (int) round(min(max(0, $centerY - $height / 2), $imageHeight - $height));
        return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }
}
