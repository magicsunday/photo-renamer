<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Reporting;

/**
 * Silent reporter for tests and non-interactive flows.
 *
 * Virtual-flow harnesses and unit tests should be able to exercise the full
 * semantics of the pipeline without constructing console IO or asserting on
 * terminal output. This reporter intentionally discards all progress and text.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class NullProgressReporter implements ProgressReporterInterface
{
    /**
     * {@inheritDoc}
     */
    public function startProgress(int $max): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function advance(int $step = 1): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function finish(): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function text(string $message): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function error(string $message): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function debug(string $message): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function section(string $title): void
    {
    }
}
