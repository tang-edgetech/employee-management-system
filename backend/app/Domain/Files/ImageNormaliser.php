<?php

namespace App\Domain\Files;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use InvalidArgumentException;

/**
 * Re-encodes uploaded images to WebP. Decoding and re-encoding drops EXIF
 * (location, camera) and anything hidden after the image data.
 *  - photo: 240×240, centre-cropped (brief §4; the mockup crops to a square)
 *  - logo: longest side at most 512 px, aspect ratio kept
 */
final class ImageNormaliser
{
    public function normalise(string $path, string $kind): string
    {
        $image = ImageManager::usingDriver(GdDriver::class)->decodePath($path)->orient();

        $image = match ($kind) {
            'photo' => $image->cover(240, 240),
            'logo' => $image->scaleDown(512, 512),
            default => throw new InvalidArgumentException("Unknown image normalisation [{$kind}]; see meridian.files.purposes."),
        };

        return (string) $image->encode(new WebpEncoder(quality: 85));
    }
}
