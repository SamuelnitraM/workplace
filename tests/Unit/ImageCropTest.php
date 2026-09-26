<?php

namespace App\Tests\Unit;

use App\Image\ImageCrop;
use PHPUnit\Framework\TestCase;

class ImageCropTest extends TestCase
{
    public function testWithoutFrameTheLargestCentredRectangleIsKept(): void
    {
        self::assertSame(['x' => 0, 'y' => 400, 'width' => 1600, 'height' => 400], ImageCrop::fromFrameValue(4.0, null)->areaWithin(1600, 1200));
        self::assertSame(['x' => 200, 'y' => 0, 'width' => 1200, 'height' => 1200], ImageCrop::fromFrameValue(1.0, '')->areaWithin(1600, 1200));
    }

    public function testChosenFrameIsKept(): void
    {
        self::assertSame(['x' => 100, 'y' => 50, 'width' => 800, 'height' => 200], ImageCrop::fromFrameValue(4.0, '100,50,800,200')->areaWithin(1600, 1200));
    }

    public function testFrameIsClampedToTheImageAndBroughtBackToTheAspectRatio(): void
    {
        $area = ImageCrop::fromFrameValue(1.0, '1400,1000,900,700')->areaWithin(1600, 1200);
        self::assertSame(200, $area['width']);
        self::assertSame(200, $area['height']);
        self::assertLessThanOrEqual(1600, $area['x'] + $area['width']);
        self::assertLessThanOrEqual(1200, $area['y'] + $area['height']);
    }

    public function testMalformedFrameFallsBackToTheCentre(): void
    {
        self::assertNull(ImageCrop::fromFrameValue(1.0, '10,20,<script>')->requested);
        self::assertNull(ImageCrop::fromFrameValue(1.0, '10,20,0,50')->requested);
    }
}
