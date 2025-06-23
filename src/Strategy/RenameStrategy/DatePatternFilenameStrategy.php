<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use DateTime;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use Override;
use SplFileInfo;

use function count;
use function strlen;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class DatePatternFilenameStrategy extends InheritFilenameStrategy
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
     * @var string[][]
     */
    private array $patternMatches;

    /**
     * Constructor.
     *
     * @param string     $pattern
     * @param string     $replacement
     * @param string[][] $patternMatches
     */
    public function __construct(string $pattern, string $replacement, array $patternMatches = [])
    {
        $this->pattern        = $pattern;
        $this->replacement    = $replacement;
        $this->patternMatches = $patternMatches;
    }

    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $filePartMatches    = [];
        $replacementMatches = [];

        $targetFilename = parent::generateFilename($splFileInfo);

        // Perform the regular expression replacement
        preg_match(
            $this->pattern,
            $targetFilename,
            $filePartMatches
        );

        preg_match_all(
            '/{(\w+)}/',
            $this->replacement . '$1',
            $replacementMatches
        );

        $dateFormatCharacters  = $this->patternMatches[1];
        $targetFilenamePattern = str_replace($replacementMatches[0], $replacementMatches[1], $this->replacement);

        // Create a new filename
        $targetFilename = preg_replace_callback(
            $this->pattern,
            static function (array $replacementMatches) use ($dateFormatCharacters, $targetFilenamePattern, $filePartMatches): string {
                $dateParts = [];

                foreach ($dateFormatCharacters as $key => $dateFormatCharacter) {
                    if ($dateFormatCharacter === 'y') {
                        $dateFormatCharacter = 'Y';
                    }

                    // Convert 2-digit year to 4-digit
                    if (($dateFormatCharacter === 'Y')
                        && (strlen($replacementMatches[$key + 1]) === 2)
                    ) {
                        $fourDigitYearDate = DateTime::createFromFormat('y', $replacementMatches[$key + 1]);

                        if ($fourDigitYearDate !== false) {
                            $replacementMatches[$key + 1] = $fourDigitYearDate->format('Y');
                        }
                    }

                    $dateParts[$dateFormatCharacter] = (int) $replacementMatches[$key + 1];
                }

                $dateTimeCreated = new DateTime();
                $dateTimeCreated
                    ->setDate($dateParts['Y'] ?? 0, $dateParts['m'] ?? 1, $dateParts['d'] ?? 1)
                    ->setTime($dateParts['H'] ?? 0, $dateParts['i'] ?? 0, $dateParts['s'] ?? 0);

                return $dateTimeCreated->format($targetFilenamePattern) . $replacementMatches[count($filePartMatches) - 1];
            },
            $targetFilename
        );

        if ($targetFilename === null) {
            throw new TargetFilenameException(
                'Date pattern error: ' . preg_last_error_msg() . '. ' .
                'Check your pattern syntax "' . $this->pattern . '". ' .
                'Make sure all date placeholders ({Y}, {m}, {d}, etc.) are valid and properly formatted. ' .
                'Try using the --dry-run option to test your pattern before applying changes.'
            );
        }

        return $targetFilename;
    }
}
