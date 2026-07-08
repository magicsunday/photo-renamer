<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Dedup;

use MagicSunday\Renamer\Service\Dedup\DedupOriginalMatcher;
use MagicSunday\Renamer\Service\Dedup\OriginalCandidateIndex;
use MagicSunday\Renamer\Service\FormatPriorityResolver;
use MagicSunday\Renamer\Service\MediaCompatibilityPolicy;
use MagicSunday\Renamer\Service\MediaTypeClassifier;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

use function file_put_contents;
use function mkdir;

use const DIRECTORY_SEPARATOR;

/**
 * Tests the filename-based original matching policy used by `rename:dedup`.
 *
 * The matcher must stay conservative enough for destructive cleanup while still
 * recognizing valid originals across still-image format variants that are
 * produced by the main rename pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(DedupOriginalMatcher::class)]
#[UsesClass(OriginalCandidateIndex::class)]
#[UsesClass(MediaTypeClassifier::class)]
#[UsesClass(MediaCompatibilityPolicy::class)]
#[UsesClass(FormatPriorityResolver::class)]
final class DedupOriginalMatcherTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Verifies that exact normalized extension matches are preferred over
     * alternative still-image candidates when both are available.
     */
    #[Test]
    public function matchPrefersExactExtensionCandidate(): void
    {
        $workspace = $this->createTempWorkspace('dedup_matcher_');
        $backupDir = $workspace . DIRECTORY_SEPARATOR . 'backup';
        mkdir($backupDir, 0777, true);

        $duplicatePath = $backupDir . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647-duplicate-001.jpg';
        $jpgPath       = $workspace . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647.jpg';
        $heicPath      = $workspace . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647.heic';

        file_put_contents($duplicatePath, 'duplicate-content');
        file_put_contents($jpgPath, 'jpg-content');
        file_put_contents($heicPath, 'heic-content');

        try {
            $matcher = $this->createMatcher();
            $index   = $matcher->createIndex([
                new SplFileInfo($duplicatePath),
                new SplFileInfo($jpgPath),
                new SplFileInfo($heicPath),
            ]);

            $match = $matcher->match(new SplFileInfo($duplicatePath), $index);

            self::assertInstanceOf(SplFileInfo::class, $match);
            self::assertSame($jpgPath, $match->getPathname());
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that still-image duplicates remain actionable when the original
     * exists only in a different still format such as HEIC.
     */
    #[Test]
    public function matchFindsCrossExtensionStillCandidate(): void
    {
        $workspace = $this->createTempWorkspace('dedup_matcher_');
        $backupDir = $workspace . DIRECTORY_SEPARATOR . 'backup';
        mkdir($backupDir, 0777, true);

        $duplicatePath = $backupDir . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647-duplicate-001.jpg';
        $heicPath      = $workspace . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647.heic';

        file_put_contents($duplicatePath, 'duplicate-content');
        file_put_contents($heicPath, 'heic-content');

        try {
            $matcher = $this->createMatcher();
            $index   = $matcher->createIndex([
                new SplFileInfo($duplicatePath),
                new SplFileInfo($heicPath),
            ]);

            $match = $matcher->match(new SplFileInfo($duplicatePath), $index);

            self::assertInstanceOf(SplFileInfo::class, $match);
            self::assertSame($heicPath, $match->getPathname());
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that multiple still-image candidates without an exact extension
     * match are ranked by configured canonical format priority.
     */
    #[Test]
    public function matchPrefersConfiguredStillFormatPriority(): void
    {
        $workspace = $this->createTempWorkspace('dedup_matcher_');
        $backupDir = $workspace . DIRECTORY_SEPARATOR . 'backup';
        mkdir($backupDir, 0777, true);

        $duplicatePath = $backupDir . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647-duplicate-001.heif';
        $heicPath      = $workspace . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647.heic';
        $jpgPath       = $workspace . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647.jpg';

        file_put_contents($duplicatePath, 'duplicate-content');
        file_put_contents($heicPath, 'heic-content');
        file_put_contents($jpgPath, 'jpg-content');

        try {
            $matcher = $this->createMatcher();
            $index   = $matcher->createIndex([
                new SplFileInfo($duplicatePath),
                new SplFileInfo($heicPath),
                new SplFileInfo($jpgPath),
            ]);

            $match = $matcher->match(new SplFileInfo($duplicatePath), $index);

            self::assertInstanceOf(SplFileInfo::class, $match);
            self::assertSame($heicPath, $match->getPathname());
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that cross-extension video candidates are rejected so a video
     * duplicate cannot be removed merely because a still image shares the basename.
     */
    #[Test]
    public function matchRejectsCrossMediaFamilyCandidates(): void
    {
        $workspace = $this->createTempWorkspace('dedup_matcher_');
        $backupDir = $workspace . DIRECTORY_SEPARATOR . 'backup';
        mkdir($backupDir, 0777, true);

        $duplicatePath = $backupDir . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647-duplicate-001.mov';
        $heicPath      = $workspace . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647.heic';

        file_put_contents($duplicatePath, 'duplicate-content');
        file_put_contents($heicPath, 'heic-content');

        try {
            $matcher = $this->createMatcher();
            $index   = $matcher->createIndex([
                new SplFileInfo($duplicatePath),
                new SplFileInfo($heicPath),
            ]);

            $match = $matcher->match(new SplFileInfo($duplicatePath), $index);

            self::assertNull($match);
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that shallower paths win when multiple compatible candidates are
     * otherwise equivalent.
     */
    #[Test]
    public function matchPrefersShallowerPathOnTie(): void
    {
        $workspace = $this->createTempWorkspace('dedup_matcher_');
        $deepDir   = $workspace . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b';
        $backupDir = $workspace . DIRECTORY_SEPARATOR . 'backup';
        mkdir($deepDir, 0777, true);
        mkdir($backupDir, 0777, true);

        $duplicatePath = $backupDir . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647-duplicate-001.jpg';
        $rootPath      = $workspace . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647.jpg';
        $deepPath      = $deepDir . DIRECTORY_SEPARATOR . '2025-11-15_20-26-50-647.jpg';

        file_put_contents($duplicatePath, 'duplicate-content');
        file_put_contents($rootPath, 'root-content');
        file_put_contents($deepPath, 'deep-content');

        try {
            $matcher = $this->createMatcher();
            $index   = $matcher->createIndex([
                new SplFileInfo($duplicatePath),
                new SplFileInfo($rootPath),
                new SplFileInfo($deepPath),
            ]);

            $match = $matcher->match(new SplFileInfo($duplicatePath), $index);

            self::assertInstanceOf(SplFileInfo::class, $match);
            self::assertSame($rootPath, $match->getPathname());
        } finally {
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that Windows-style path separators participate in depth ranking
     * even when tests run on a Unix host.
     */
    #[Test]
    public function matchPrefersShallowerWindowsPathOnTie(): void
    {
        $duplicatePath = 'C:\\Photos\\backup\\2025-11-15_20-26-50-647-duplicate-001.jpg';
        $rootPath      = 'C:\\Photos\\2025-11-15_20-26-50-647.jpg';
        $deepPath      = 'C:\\Photos\\a\\b\\2025-11-15_20-26-50-647.jpg';

        $matcher = $this->createMatcher();
        $index   = $matcher->createIndex([
            new SplFileInfo($duplicatePath),
            new SplFileInfo($deepPath),
            new SplFileInfo($rootPath),
        ]);

        $match = $matcher->match(new SplFileInfo($duplicatePath), $index);

        self::assertInstanceOf(SplFileInfo::class, $match);
        self::assertSame($rootPath, $match->getPathname());
    }

    /**
     * Creates the matcher under test with the production media classifier.
     *
     * @return DedupOriginalMatcher Fully configured matcher
     */
    private function createMatcher(): DedupOriginalMatcher
    {
        return new DedupOriginalMatcher(
            new MediaCompatibilityPolicy(new MediaTypeClassifier()),
        );
    }
}
