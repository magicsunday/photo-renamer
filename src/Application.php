<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer;

use MagicSunday\Renamer\Service\Filesystem\BatchRunLock;
use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function str_starts_with;
use function trim;

use const PHP_EOL;

/**
 * Symfony Console application entry point. Registers all rename commands
 * injected via the DI container, reads the application version from the
 * version file and displays the ASCII art logo in help output.
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
     * @param iterable<Command> $commands     The list of commands to register
     * @param BatchRunLock      $batchRunLock Serializes mutating commands from before analysis until cleanup
     */
    public function __construct(iterable $commands, private readonly BatchRunLock $batchRunLock)
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

        $lock = $this->batchRunLock->acquire();

        try {
            return parent::doRunCommand($command, $input, $output);
        } finally {
            $lock->release();
        }
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
