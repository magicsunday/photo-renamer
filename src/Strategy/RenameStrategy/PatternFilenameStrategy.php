<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use MagicSunday\Renamer\Exception\TargetFilenameException;
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

    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $targetFilename = parent::generateFilename($splFileInfo);

        // Perform the regular expression replacement
        $targetFilename = preg_replace(
            $this->pattern,
            $this->replacement,
            $targetFilename
        );

        if ($targetFilename === null) {
            throw new TargetFilenameException(
                'Regular expression error: ' . preg_last_error_msg() . '. ' .
                'Check your pattern syntax "' . $this->pattern . '". ' .
                'Make sure it is a valid PCRE pattern enclosed in delimiters (e.g., /pattern/).'
            );
        }

        return $targetFilename;
    }
}
