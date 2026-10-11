<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer;

use MagicSunday\Renamer\Exception\AnalysisMemoryBudgetExceededException;
use MagicSunday\Renamer\Helper\ConsoleText;
use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use MagicSunday\Renamer\Service\Filesystem\MutationJournal;
use MagicSunday\Renamer\Service\Filesystem\SourceIdentityGuard;
use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function is_string;
use function sprintf;
use function str_starts_with;
use function trim;

use const PHP_EOL;

/**
 * Symfony Console application entry point. Registers all rename commands
 * injected via the DI container, reads the application version from the
 * version file and displays the ASCII art logo in help output.
 * Mutation coordination lives at this boundary so every writing command,
 * including dedup and write-date, is locked before its own analysis starts.
 * Source identity is captured within that lock and released after cleanup;
 * read-only commands and help remain available without a mutation scope.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class Application extends \Symfony\Component\Console\Application
{
    /**
     * The name of the application.
     */
    private const string NAME = 'renamer';

    /**
     * The path to the version file.
     */
    private const string VERSION_FILE = __DIR__ . '/../version';

    /**
     * The default version if the version file is not available.
     */
    private const string DEFAULT_VERSION = '0.0.0';

    /**
     * The logo.
     */
    private const string LOGO = ' .____                         .__                                .___               ____.
 |   _|   _____ _____     ____ |__| ____   ________ __  ____    __| _/____  ___.__. |_   |
 |  |    /     \\\\__  \   / ___\|  |/ ___\ /  ___/  |  \/    \  / __ |\__  \\\<   |  |   |  |
 |  |   |  Y Y  \/ __ \_/ /_/  >  \  \___ \___ \|  |  /   |  \/ /_/ | / __ \\\\___  |   |  |
 |  |_  |__|_|  (____  /\___  /|__|\___  >____  >____/|___|  /\____ |(____  / ____|  _|  |
 |____|       \/     \//_____/         \/     \/           \/      \/     \/\/      |____|

';

    /**
     * Constructor.
     *
     * Registers all commands injected via the DI container. The commands
     * are expected to be provided as an iterable, typically from a
     * service tag in the container configuration.
     *
     * @param iterable<Command>   $commands            The list of commands to register
     * @param BatchRunLock        $batchRunLock        Serializes mutating commands from before analysis until cleanup
     * @param SourceIdentityGuard $sourceIdentityGuard Captures source state before analysis and releases it after execution
     * @param MutationJournal     $mutationJournal     Persists mutation evidence throughout the protected CLI scope
     */
    public function __construct(iterable $commands, private readonly BatchRunLock $batchRunLock, private readonly SourceIdentityGuard $sourceIdentityGuard, private readonly MutationJournal $mutationJournal)
    {
        parent::__construct(
            self::NAME,
            $this->loadVersion()
        );

        foreach ($commands as $command) {
            $this->addCommand($command);
        }
    }

    /**
     * Holds the shared mutation lock throughout command analysis, confirmation,
     * execution and cache cleanup. New rename commands are protected by default;
     * verification and explicitly parsed dry runs do not mutate media.
     *
     * @param Command         $command Resolved command, including aliases
     * @param InputInterface  $input   Raw command arguments and options
     * @param OutputInterface $output  Console output used by Symfony's error handling
     *
     * @return int Exit status returned by the protected command
     *
     * @throws AnalysisMemoryBudgetExceededException When retained analysis reaches its pre-mutation budget
     */
    #[Override]
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        $name = $command->getName() ?? '';

        if (!str_starts_with($name, 'rename:') || ($name === 'rename:verify')) {
            return parent::doRunCommand($command, $input, $output);
        }

        $command->mergeApplicationDefinition();
        $input->bind($command->getDefinition());

        if ($input->hasOption('dry-run') && ($input->getOption('dry-run') === true)) {
            return parent::doRunCommand($command, $input, $output);
        }

        $lock   = $this->batchRunLock->acquire();
        $status = Command::FAILURE;

        try {
            $this->mutationJournal->begin($name);
            $source = $input->hasArgument('source') ? $input->getArgument('source') : null;
            $this->sourceIdentityGuard->begin(is_string($source) ? $source : null);
            $status = parent::doRunCommand($command, $input, $output);
        } finally {
            try {
                $this->sourceIdentityGuard->finish();

                if (!$this->mutationJournal->finish($status)) {
                    $output->writeln('<error>Mutation journal incomplete; inspect persisted intents and actual files before retrying.</error>');
                    $status = Command::FAILURE;
                }
            } finally {
                $lock->release();
            }
        }

        return $status;
    }

    /**
     * Renders uncaught failures without active filename/diagnostic controls.
     * Symfony's default exception renderer escapes tags but retains controls;
     * sanitize values before adding its existing error/comment styles instead
     * of mutating Throwable objects or duplicating box-width calculations.
     * Verbose output preserves original locations and frame identities, while
     * exception codes and application exit handling stay with Symfony.
     *
     * @param Throwable       $exception Original failure, including its previous chain
     * @param OutputInterface $output    Console error output with requested verbosity
     *
     * @return void
     */
    #[Override]
    protected function doRenderThrowable(Throwable $exception, OutputInterface $output): void
    {
        do {
            $output->writeln('<error>' . ConsoleText::escape($exception::class . ': ' . $exception->getMessage()) . '</error>', OutputInterface::VERBOSITY_QUIET);

            if ($output->isVerbose()) {
                $output->writeln('<comment>' . ConsoleText::escape(sprintf('In %s line %d:', $exception->getFile(), $exception->getLine())) . '</comment>');

                foreach ($exception->getTrace() as $index => $frame) {
                    $output->writeln(ConsoleText::escape(sprintf(
                        '#%d %s:%d %s%s%s()',
                        $index,
                        $frame['file'] ?? '[internal]',
                        $frame['line'] ?? 0,
                        $frame['class'] ?? '',
                        $frame['type'] ?? '',
                        $frame['function'] ?? '',
                    )));
                }
            }

            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);
    }

    /**
     * Loads the version from the specified version file if it exists.
     * If the file does not exist or cannot be read, returns the default version.
     *
     * @return string the loaded version or the default version if loading fails
     */
    private function loadVersion(): string
    {
        $filesystem = new Filesystem();

        try {
            $content = $filesystem->readFile(self::VERSION_FILE);
        } catch (IOException) {
            return self::DEFAULT_VERSION;
        }

        $version = trim($content, PHP_EOL);

        return $version !== '' ? $version : self::DEFAULT_VERSION;
    }

    /**
     * Prepends the ASCII art logo to the default Symfony Console help text.
     */
    #[Override]
    public function getHelp(): string
    {
        return self::LOGO . parent::getHelp();
    }
}
