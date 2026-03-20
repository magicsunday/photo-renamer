<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer;

use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

use function trim;

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
    private static string $logo = ' .____                         .__                                .___               ____.
 |   _|   _____ _____     ____ |__| ____   ________ __  ____    __| _/____  ___.__. |_   |
 |  |    /     \\\\__  \   / ___\|  |/ ___\ /  ___/  |  \/    \  / __ |\__  \\\<   |  |   |  |
 |  |   |  Y Y  \/ __ \_/ /_/  >  \  \___ \___ \|  |  /   |  \/ /_/ | / __ \\\\___  |   |  |
 |  |_  |__|_|  (____  /\___  /|__|\___  >____  >____/|___|  /\____ |(____  / ____|  _|  |
 |____|       \/     \//_____/         \/     \/           \/      \/     \/\/      |____|

';

    /**
     * Constructor.
     *
     * @param iterable<Command> $commands
     */
    public function __construct(iterable $commands)
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
        return self::$logo . parent::getHelp();
    }
}
