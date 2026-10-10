<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Filesystem;

use MagicSunday\Renamer\Helper\FilterIterator\RecursiveRegexFileFilterIterator;
use MagicSunday\Renamer\Model\SourceFileFingerprint;
use MagicSunday\Renamer\Regex\RegexMatchResult;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Service\Filesystem\FileCollector;
use MagicSunday\Renamer\Service\Filesystem\SourceIdentityGuard;
use MagicSunday\Renamer\Service\SafeHashCalculator;
use MagicSunday\Renamer\Test\Fixtures\FileSystemServiceFactory;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Verifies that mutation-source observations cannot be refreshed after failed
 * analysis, and that a run's state cannot leak into a later invocation.
 */
#[UsesClass(FileCollector::class)]
#[UsesClass(RecursiveRegexFileFilterIterator::class)]
#[UsesClass(SourceFileFingerprint::class)]
#[UsesClass(RegexMatchResult::class)]
#[UsesClass(SafeRegex::class)]
#[UsesClass(SafeHashCalculator::class)]
#[CoversClass(SourceIdentityGuard::class)]
final class SourceIdentityGuardTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * A source unreadable before analysis stays unverified even if it becomes
     * readable before execution. Another independently verified source remains
     * usable, preventing a read failure from authorizing stale content or from
     * unnecessarily blocking every other source in the collection.
     *
     * @return void
     */
    #[Test]
    public function unreadableInitialSourceCannotBeBlessedByLaterRead(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $blocked    = $workspace . '/blocked.jpg';
        $verified   = $workspace . '/verified.jpg';
        $filesystem->dumpFile($blocked, 'unverified bytes');
        $filesystem->dumpFile($verified, 'verified bytes');
        $filesystem->chmod($blocked, 0o000);

        $guard = FileSystemServiceFactory::createSourceIdentityGuard();

        try {
            $guard->begin($workspace);
            $filesystem->chmod($blocked, 0o600);
            $guard->assertUnchanged($verified);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/was not verified before analysis/');
            $guard->assertUnchanged($blocked);
        } finally {
            $guard->finish();
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A file introduced after the initial scan cannot use a fresh fingerprint as
     * evidence for an earlier plan; only sources observed before analysis qualify.
     *
     * @return void
     */
    #[Test]
    public function sourceCreatedAfterInitialPassRemainsUnverified(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $guard      = FileSystemServiceFactory::createSourceIdentityGuard();

        try {
            $guard->begin($workspace);
            $source = $workspace . '/late.jpg';
            $filesystem->dumpFile($source, 'late bytes');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/was not verified before analysis/');
            $guard->assertUnchanged($source);
        } finally {
            $guard->finish();
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * A new run observing only a different single file must not authorize sources
     * retained from an earlier collection. begin resets observations independently
     * of whether the preceding invocation reached its normal cleanup path.
     *
     * @return void
     */
    #[Test]
    public function subsequentRunDoesNotReuseEarlierSourceObservations(): void
    {
        $workspace  = $this->createTempWorkspace();
        $filesystem = new Filesystem();
        $first      = $workspace . '/first.jpg';
        $second     = $workspace . '/second.jpg';
        $filesystem->dumpFile($first, 'first bytes');
        $filesystem->dumpFile($second, 'second bytes');

        $guard = FileSystemServiceFactory::createSourceIdentityGuard();

        try {
            $guard->begin($first);
            $guard->assertUnchanged($first);
            $guard->begin($second);
            $guard->assertUnchanged($second);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/was not verified before analysis/');
            $guard->assertUnchanged($first);
        } finally {
            $guard->finish();
            $this->removeWorkspace($workspace);
        }
    }
}
