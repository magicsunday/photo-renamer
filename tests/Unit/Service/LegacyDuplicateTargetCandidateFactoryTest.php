<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Service\LegacyDuplicateTargetCandidateFactory;
use MagicSunday\Renamer\Service\LegacyTargetPathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies duplicate candidate path creation for the legacy rename pipeline.
 *
 * The factory must encode the requested `-duplicate-NNN` suffix, preserve the
 * target extension, and keep the target in the source file's relative
 * directory beneath the configured legacy source root.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(LegacyDuplicateTargetCandidateFactory::class)]
final class LegacyDuplicateTargetCandidateFactoryTest extends TestCase
{
    /**
     * Verifies that a duplicate candidate preserves the source-relative
     * directory and target extension while formatting the duplicate counter as
     * a zero-padded `-duplicate-NNN` suffix.
     */
    #[Test]
    public function createBuildsDuplicateCandidateInLegacyTargetDirectory(): void
    {
        $factory    = new LegacyDuplicateTargetCandidateFactory(new LegacyTargetPathResolver());
        $sourceRoot = DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'Fotos';
        $source     = new SplFileInfo(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2019'
            . DIRECTORY_SEPARATOR . 'Zoo'
            . DIRECTORY_SEPARATOR . 'IMG_0001.jpg',
        );
        $target = new SplFileInfo(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2019'
            . DIRECTORY_SEPARATOR . 'Zoo'
            . DIRECTORY_SEPARATOR . '2019-09-28_16-57-59.jpg',
        );

        $candidate = $factory->create(
            $sourceRoot,
            $source,
            $target,
            '2019-09-28_16-57-59',
            7,
        );

        self::assertSame(
            $sourceRoot
            . DIRECTORY_SEPARATOR . '2019'
            . DIRECTORY_SEPARATOR . 'Zoo'
            . DIRECTORY_SEPARATOR . '2019-09-28_16-57-59'
            . Constants::DUPLICATE_IDENTIFIER
            . '007.jpg',
            $candidate->getPathname(),
        );
    }
}
