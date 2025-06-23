<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\FilenameProcessor;

use MagicSunday\Renamer\Exception\TargetFilenameException;
use Override;
use SplFileInfo;

/**
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class PatternFilenameProcessor extends DefaultFilenameProcessor
{
    /**
     * @var string
     */
    private readonly string $pattern;

    /**
     * @var string
     */
    private readonly string $replacement;

    /**
     * Constructor.
     *
     * @param string $pattern
     * @param string $replacement
     */
    public function __construct(string $pattern, string $replacement)
    {
        $this->pattern     = $pattern;
        $this->replacement = $replacement;
    }

    /**
     * @param SplFileInfo $splFileInfo
     *
     * @return string
     *
     * @throws TargetFilenameException
     */
    #[Override]
    public function __invoke(SplFileInfo $splFileInfo): string
    {
        $targetFilename = parent::__invoke($splFileInfo);

        // Perform the regular expression replacement
        $targetFilename = preg_replace(
            $this->pattern,
            $this->replacement,
            $targetFilename
        );

        if ($targetFilename === null) {
            throw new TargetFilenameException(preg_last_error_msg());
        }

        return $targetFilename;
    }
}
