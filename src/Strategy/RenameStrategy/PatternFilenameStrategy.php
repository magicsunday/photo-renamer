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
use MagicSunday\Renamer\Regex\SafeRegex;
use Override;
use SplFileInfo;

/**
 * Applies a user-supplied regex search/replace to the inherited filename.
 * Used by the rename:pattern command to perform arbitrary regex-based
 * filename transformations.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
readonly class PatternFilenameStrategy extends InheritFilenameStrategy
{
    /**
     * @param string    $pattern     PCRE regex pattern to match against the filename
     * @param string    $replacement Replacement string (may contain back-references)
     * @param SafeRegex $regex       Safe wrapper around preg_* functions with error handling
     */
    public function __construct(private string $pattern, private string $replacement, private SafeRegex $regex)
    {
    }

    /**
     * Applies the regex replacement to the inherited filename. Throws
     * TargetFilenameException when the regex pattern is invalid.
     *
     * @param SplFileInfo $splFileInfo Source file to derive the target name from
     *
     * @return string Transformed filename after regex replacement
     *
     * @throws TargetFilenameException When the regex execution fails
     */
    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $targetFilename = parent::generateFilename($splFileInfo);

        try {
            return $this->regex
                ->replace($this->pattern, $this->replacement, $targetFilename);
        } catch (RegexExecutionException $exception) {
            throw new TargetFilenameException('Regular expression error: ' . $exception->getMessage(), $exception->getCode(), previous: $exception);
        }
    }
}
