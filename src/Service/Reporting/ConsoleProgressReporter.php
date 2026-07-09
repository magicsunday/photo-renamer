<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Reporting;

use MagicSunday\Renamer\Constants;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

use function max;

/**
 * Console-backed reporter that adapts the narrow reporting boundary to SymfonyStyle.
 *
 * Commands and output/reporting adapters are the only places that should know
 * Symfony Console directly. This implementation preserves the existing CLI
 * behavior of progress bars, headings, and diagnostics while keeping those
 * details out of domain services.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class ConsoleProgressReporter implements ProgressReporterInterface
{
    /**
     * Currently active progress bar, if any.
     */
    private ?ProgressBar $progressBar = null;

    /**
     * @param SymfonyStyle $io Console IO adapter used for headings, diagnostics, and progress bars
     */
    public function __construct(private readonly SymfonyStyle $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function startProgress(int $max): void
    {
        $this->progressBar = $this->io->createProgressBar(max($max, 1));
        $this->progressBar->setFormat(Constants::PROGRESS_BAR_FORMAT);
        $this->progressBar->start();
    }

    /**
     * {@inheritDoc}
     */
    public function advance(int $step = 1): void
    {
        $this->progressBar?->advance($step);
    }

    /**
     * {@inheritDoc}
     */
    public function finish(): void
    {
        if (!$this->progressBar instanceof ProgressBar) {
            return;
        }

        $this->progressBar->finish();
        $this->progressBar = null;

        $this->io->newLine();
    }

    /**
     * {@inheritDoc}
     */
    public function text(string $message): void
    {
        $this->io->text($message);
    }

    /**
     * {@inheritDoc}
     */
    public function error(string $message): void
    {
        $this->io->error($message);
    }

    /**
     * {@inheritDoc}
     */
    public function debug(string $message): void
    {
        if (!$this->io->isDebug()) {
            return;
        }

        $this->io->writeln($message);
    }

    /**
     * {@inheritDoc}
     */
    public function section(string $title): void
    {
        $this->io->newLine();
        $this->io->text($title);
    }
}
