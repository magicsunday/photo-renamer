<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use MagicSunday\Renamer\Exception\RegexExecutionException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Service\SafeRegex;
use Override;
use SplFileInfo;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class PatternFilenameStrategy extends InheritFilenameStrategy
{
    /**
     * @var string
     */
    private readonly string $pattern;

    /**
     * @var string
     */
    private readonly string $replacement;

    public function __construct(
        string $pattern,
        string $replacement,
        private readonly SafeRegex $regex,
    ) {
        $this->pattern     = $pattern;
        $this->replacement = $replacement;
    }

    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $targetFilename = parent::generateFilename($splFileInfo);

        try {
            return $this->regex
                ->replace($this->pattern, $this->replacement, $targetFilename)
                ->result();
        } catch (RegexExecutionException $exception) {
            throw new TargetFilenameException(
                'Regular expression error: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
