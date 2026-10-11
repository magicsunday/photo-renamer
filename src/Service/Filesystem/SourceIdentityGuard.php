<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use MagicSunday\Renamer\Exception\HashComputationException;
use MagicSunday\Renamer\Model\SourceFileFingerprint;
use MagicSunday\Renamer\Service\SafeHashCalculatorInterface;
use RuntimeException;
use SplFileInfo;

use function clearstatcache;
use function is_file;
use function is_link;
use function realpath;
use function sprintf;
use function stat;

/**
 * Keeps a run-scoped pre-analysis identity for each source in a mutating CLI
 * invocation. Application activates it under the batch lock; verification,
 * dry runs and direct internal service calls do not start a mutation scope.
 * Hashes are fresh, streamed and never retained in the calculator across files.
 */
final class SourceIdentityGuard
{
    /**
     * Sources present before analysis; null marks a source that could not be
     * verified, so a later read cannot bless an unproven analysis state.
     *
     * @var array<string, SourceFileFingerprint|null>
     */
    private array $sources = [];

    /**
     * Whether a mutating application invocation currently owns this scope.
     */
    private bool $active = false;

    /**
     * @param SafeHashCalculatorInterface $hashCalculator Dedicated fresh-hash calculator, separate from classification caches
     * @param FileCollector               $fileCollector  Streams the source tree without retaining another SplFileInfo list
     */
    public function __construct(private readonly SafeHashCalculatorInterface $hashCalculator, private readonly FileCollector $fileCollector)
    {
    }

    /**
     * Captures sources before any command analysis, retaining failed observations
     * as unverified rather than aborting unrelated, readable sources. Traversal
     * failures still abort before command execution and before any media mutation.
     *
     * @param string|null $source Source argument, or null for a command without one
     *
     * @return void
     */
    public function begin(?string $source): void
    {
        $this->sources = [];
        $this->active  = true;

        if ($source === null) {
            return;
        }

        $resolved = realpath($source);

        if ($resolved === false) {
            throw new RuntimeException('Source does not exist; pre-analysis identity cannot be captured.');
        }

        if (is_file($resolved)) {
            $this->observe($resolved);

            return;
        }

        /** @var SplFileInfo $file */
        foreach ($this->fileCollector->createFileIterator($resolved) as $file) {
            if ($file->isFile() || $file->isLink()) {
                $this->observe($file->getPathname());
            }
        }
    }

    /**
     * Rejects a source absent/unreadable at analysis start or different now.
     * Fresh hashing deliberately ignores metadata/classification caches. These
     * checks detect observed changes, not an atomic race against hostile writers.
     *
     * @param string $source Absolute source pathname immediately before mutation
     *
     * @return void
     */
    public function assertUnchanged(string $source): void
    {
        if (!$this->active) {
            return;
        }

        $observed = $this->sources[$source] ?? null;

        if ($observed === null) {
            throw new RuntimeException(sprintf('Source "%s" was not verified before analysis; mutation withheld.', $source));
        }

        try {
            $current = $this->fingerprint($source);
        } catch (RuntimeException $exception) {
            throw new RuntimeException(sprintf('Source "%s" changed or became unreadable after analysis; mutation withheld.', $source), $exception->getCode(), previous: $exception);
        }

        if (!$observed->matches($current)) {
            throw new RuntimeException(sprintf('Source "%s" changed after analysis; mutation withheld.', $source));
        }
    }

    /**
     * Releases the run's retained identities after command cleanup, including
     * failures, and prevents state from leaking into a later application run.
     *
     * @return void
     */
    public function finish(): void
    {
        $this->active  = false;
        $this->sources = [];
        $this->hashCalculator->clearCache();
    }

    /**
     * Stores the initial identity exactly once for the pre-analysis source pass.
     * A failed observation remains null even if the file becomes readable later.
     *
     * @param string $source Absolute pathname found during the initial source pass
     *
     * @return void
     */
    private function observe(string $source): void
    {
        try {
            $this->sources[$source] = $this->fingerprint($source);
        } catch (RuntimeException) {
            $this->sources[$source] = null;
        }
    }

    /**
     * Checks file identity around a fresh streamed SHA-256 read. Symlink sources
     * are not regular-file identities and never authorize media mutation.
     *
     * @param string $source Absolute pathname whose bytes and inode are observed
     *
     * @return SourceFileFingerprint Fresh, stable file identity
     *
     * @throws HashComputationException When source bytes cannot be read
     */
    private function fingerprint(string $source): SourceFileFingerprint
    {
        clearstatcache(true, $source);

        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException('Source is no longer a regular non-symlink file.');
        }

        $before = stat($source);
        $this->hashCalculator->clearCache();

        try {
            $hash = $this->hashCalculator->hashFile(new SplFileInfo($source), 'sha256');
        } finally {
            $this->hashCalculator->clearCache();
        }

        clearstatcache(true, $source);
        $after = stat($source);

        if (($before === false) || ($after === false) || is_link($source)) {
            throw new RuntimeException('Source changed while its identity was being read.');
        }

        $initial = new SourceFileFingerprint($before['dev'], $before['ino'], $before['size'], $before['mtime'], $before['ctime'], $hash);
        $current = new SourceFileFingerprint($after['dev'], $after['ino'], $after['size'], $after['mtime'], $after['ctime'], $hash);

        if (!$initial->matches($current)) {
            throw new RuntimeException('Source changed while its identity was being read.');
        }

        return $current;
    }
}
