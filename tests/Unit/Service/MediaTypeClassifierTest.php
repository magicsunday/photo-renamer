<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\MediaTypeClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies extension classification and shared media-family pair rules.
 *
 * The classifier distinguishes still image extensions (HEIC, HEIF, JPG, JPEG) from
 * video extensions (AVI, MOV, MP4, M4V) and other file types.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(MediaTypeClassifier::class)]
final class MediaTypeClassifierTest extends TestCase
{
    /**
     * Provides file extensions that should be classified as Live Photo stills.
     *
     * @return array<string, array{string}>
     */
    public static function stillExtensionProvider(): array
    {
        return [
            'heic'           => ['photo.heic'],
            'heif'           => ['photo.heif'],
            'jpg'            => ['photo.jpg'],
            'jpeg'           => ['photo.jpeg'],
            'HEIC uppercase' => ['photo.HEIC'],
            'JPG uppercase'  => ['photo.JPG'],
        ];
    }

    /**
     * Provides file extensions that should NOT be classified as Live Photo stills.
     *
     * @return array<string, array{string}>
     */
    public static function nonStillExtensionProvider(): array
    {
        return [
            'mov'          => ['video.mov'],
            'mp4'          => ['video.mp4'],
            'png'          => ['image.png'],
            'gif'          => ['image.gif'],
            'tiff'         => ['image.tiff'],
            'no extension' => ['noext'],
        ];
    }

    /**
     * Verifies that known still image extensions are correctly identified.
     */
    #[Test]
    #[DataProvider('stillExtensionProvider')]
    public function isLivePhotoStillReturnsTrueForStillExtensions(string $filename): void
    {
        $classifier = $this->createClassifier();

        self::assertTrue($classifier->isLivePhotoStill(new SplFileInfo($filename)));
    }

    /**
     * Verifies that video companions and other file types are not classified as stills.
     */
    #[Test]
    #[DataProvider('nonStillExtensionProvider')]
    public function isLivePhotoStillReturnsFalseForNonStillExtensions(string $filename): void
    {
        $classifier = $this->createClassifier();

        self::assertFalse($classifier->isLivePhotoStill(new SplFileInfo($filename)));
    }

    /**
     * Verifies that two still-image files are treated as belonging to the same
     * still family even when they use different still extensions.
     */
    #[Test]
    public function areBothStillImagesRecognizesCrossExtensionStillPairs(): void
    {
        $classifier = $this->createClassifier();

        self::assertTrue($classifier->areBothStillImages(
            new SplFileInfo('/photos/IMG_0001.heic'),
            new SplFileInfo('/photos/IMG_0001.jpg'),
        ));
    }

    /**
     * Verifies that only explicit still/video pairings are treated as different
     * media families by extension classification.
     */
    #[Test]
    public function areDifferentMediaFamiliesRecognizesStillVideoPairs(): void
    {
        $classifier = $this->createClassifier();

        self::assertTrue($classifier->areDifferentMediaFamilies(
            new SplFileInfo('/photos/IMG_0001.heic'),
            new SplFileInfo('/photos/IMG_0001.mov'),
        ));

        self::assertFalse($classifier->areDifferentMediaFamilies(
            new SplFileInfo('/photos/IMG_0001.heic'),
            new SplFileInfo('/photos/IMG_0001.jpg'),
        ));
    }

    /**
     * Creates the production classifier used by these extension and pair checks.
     *
     * @return MediaTypeClassifier Stateless media-family classifier
     */
    private function createClassifier(): MediaTypeClassifier
    {
        return new MediaTypeClassifier();
    }
}
