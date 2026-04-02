<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * Verifies the shared still/video compatibility rules used by multiple commands
 * and pipeline services.
 *
 * The policy must stay intentionally small: it only answers repeated media-family
 * questions and must not absorb naming, duplicate ranking, or metadata logic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(MediaCompatibilityPolicy::class)]
#[UsesClass(MediaTypeClassifier::class)]
final class MediaCompatibilityPolicyTest extends TestCase
{
    /**
     * Verifies that still-image files are recognized as such through the policy.
     */
    #[Test]
    public function isStillImageReturnsTrueForStillFormats(): void
    {
        $policy = $this->createPolicy();

        self::assertTrue($policy->isStillImage(new SplFileInfo('/photos/IMG_0001.heic')));
        self::assertTrue($policy->isStillImage(new SplFileInfo('/photos/IMG_0001.jpg')));
    }

    /**
     * Verifies that video files are recognized as such through the policy.
     */
    #[Test]
    public function isVideoReturnsTrueForVideoFormats(): void
    {
        $policy = $this->createPolicy();

        self::assertTrue($policy->isVideo(new SplFileInfo('/photos/IMG_0001.mov')));
        self::assertTrue($policy->isVideo(new SplFileInfo('/photos/IMG_0001.mp4')));
    }

    /**
     * Verifies that two still-image files are treated as belonging to the same
     * still family even when they use different still extensions.
     */
    #[Test]
    public function areBothStillImagesRecognizesCrossExtensionStillPairs(): void
    {
        $policy = $this->createPolicy();

        self::assertTrue($policy->areBothStillImages(
            new SplFileInfo('/photos/IMG_0001.heic'),
            new SplFileInfo('/photos/IMG_0001.jpg'),
        ));
    }

    /**
     * Verifies that only explicit still/video pairings are treated as different
     * media families by the policy.
     */
    #[Test]
    public function areDifferentMediaFamiliesRecognizesStillVideoPairs(): void
    {
        $policy = $this->createPolicy();

        self::assertTrue($policy->areDifferentMediaFamilies(
            new SplFileInfo('/photos/IMG_0001.heic'),
            new SplFileInfo('/photos/IMG_0001.mov'),
        ));

        self::assertFalse($policy->areDifferentMediaFamilies(
            new SplFileInfo('/photos/IMG_0001.heic'),
            new SplFileInfo('/photos/IMG_0001.jpg'),
        ));
    }

    /**
     * Creates the production policy using the production media classifier.
     *
     * @return MediaCompatibilityPolicy Fully configured compatibility policy
     */
    private function createPolicy(): MediaCompatibilityPolicy
    {
        return new MediaCompatibilityPolicy(new MediaTypeClassifier());
    }
}
