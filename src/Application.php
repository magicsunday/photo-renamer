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

/**
 * Class Application.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class Application extends \Symfony\Component\Console\Application
{
    private const string NAME = 'renamer';

    private static string $logo = ' .____                         .__                                .___               ____.
 |   _|   _____ _____     ____ |__| ____   ________ __  ____    __| _/____  ___.__. |_   |
 |  |    /     \\\\__  \   / ___\|  |/ ___\ /  ___/  |  \/    \  / __ |\__  \\\<   |  |   |  |
 |  |   |  Y Y  \/ __ \_/ /_/  >  \  \___ \___ \|  |  /   |  \/ /_/ | / __ \\\\___  |   |  |
 |  |_  |__|_|  (____  /\___  /|__|\___  >____  >____/|___|  /\____ |(____  / ____|  _|  |
 |____|       \/     \//_____/         \/     \/           \/      \/     \/\/      |____|

';

    /**
     * @param iterable<Command> $commands
     */
    public function __construct(iterable $commands)
    {
        $version = trim(file_get_contents(__DIR__ . '/../version'), PHP_EOL);

        parent::__construct(self::NAME, $version);

        foreach ($commands as $command) {
            $this->add($command);
        }
    }

    #[Override]
    public function getHelp(): string
    {
        return self::$logo . parent::getHelp();
    }
}
