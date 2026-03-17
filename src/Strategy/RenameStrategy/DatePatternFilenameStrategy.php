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
use MagicSunday\Renamer\Exception\RegexExecutionException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Regex\RegexMatchCollection;
use MagicSunday\Renamer\Regex\SafeRegex;
use MagicSunday\Renamer\Strategy\RenameStrategy\DatePattern\PatternMatchSet;
use Override;
use SplFileInfo;

use function str_replace;
use function strlen;

/**
 * Extracts date components from the existing filename via a regex pattern, then
 * reconstructs a DateTime and formats it using the replacement template. Supports
 * both 2-digit and 4-digit year tokens and preserves any trailing suffix that
 * follows the date portion in the original filename.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class DatePatternFilenameStrategy extends InheritFilenameStrategy
{
    /**
     * @param string          $pattern        PCRE regex to extract date components from the filename
     * @param string          $replacement    PHP date() format template with {placeholder} tokens
     * @param PatternMatchSet $patternMatches Set of placeholder-to-date-format-character mappings
     * @param SafeRegex       $regex          Safe wrapper around preg_* functions with error handling
     */
    public function __construct(private readonly string $pattern, private readonly string $replacement, private readonly PatternMatchSet $patternMatches, private readonly SafeRegex $regex)
    {
    }

    /**
     * Parses date components from the inherited filename, reconstructs a DateTime,
     * and formats it using the replacement template. Preserves any trailing suffix
     * (e.g. sequence numbers) from the original filename.
     *
     * @param SplFileInfo $splFileInfo Source file to derive the target name from
     *
     * @return string Reformatted filename based on the extracted date
     *
     * @throws TargetFilenameException When the regex pattern is invalid or does not match
     */
    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): string
    {
        $targetFilename = parent::generateFilename($splFileInfo);

        try {
            $filePartMatches = RegexMatchCollection::fromMatch(
                $this->regex->match(
                    $this->pattern,
                    $targetFilename,
                    'matching date pattern',
                ),
            );

            $replacementMatches = RegexMatchCollection::fromMatchAll(
                $this->regex->matchAll(
                    '/{(\w+)}/',
                    $this->replacement . '$1',
                    'resolving date pattern placeholders',
                ),
            );

            $dateFormatCharacters  = $this->patternMatches->placeholders();
            $targetFilenamePattern = $this->replacement;

            if ($replacementMatches->hasGroup(0) && $replacementMatches->hasGroup(1)) {
                $targetFilenamePattern = str_replace(
                    $replacementMatches->group(0)?->values() ?? [],
                    $replacementMatches->group(1)?->values() ?? [],
                    $this->replacement,
                );
            }

            $suffixIndex = $filePartMatches->count() > 0 ? $filePartMatches->count() - 1 : 0;

            return $this->regex
                ->replaceCallback(
                    $this->pattern,
                    /** @param array<int|string, string> $matches */
                    static function (array $matches) use ($dateFormatCharacters, $targetFilenamePattern, $suffixIndex): string {
                        /** @var array<int|string, string> $matches */
                        $dateParts = [];

                        foreach ($dateFormatCharacters as $key => $dateFormatCharacter) {
                            if ($dateFormatCharacter === 'y') {
                                $dateFormatCharacter = 'Y';
                            }

                            $matchValue = $matches[$key + 1] ?? '';

                            if (($dateFormatCharacter === 'Y') && (strlen($matchValue) === 2)) {
                                $fourDigitYearDate = DateTime::createFromFormat('y', $matchValue);

                                if ($fourDigitYearDate !== false) {
                                    $matchValue        = $fourDigitYearDate->format('Y');
                                    $matches[$key + 1] = $matchValue;
                                }
                            }

                            $dateParts[$dateFormatCharacter] = (int) $matchValue;
                        }

                        $dateTimeCreated = new DateTime();
                        $dateTimeCreated
                            ->setDate($dateParts['Y'] ?? 0, $dateParts['m'] ?? 1, $dateParts['d'] ?? 1)
                            ->setTime($dateParts['H'] ?? 0, $dateParts['i'] ?? 0, $dateParts['s'] ?? 0);

                        $suffix = $matches[$suffixIndex] ?? '';

                        return $dateTimeCreated->format($targetFilenamePattern) . $suffix;
                    },
                    $targetFilename,
                    'executing preg_replace_callback for date pattern',
                );
        } catch (RegexExecutionException $exception) {
            throw new TargetFilenameException('Date pattern error: ' . $exception->getMessage(), $exception->getCode(), previous: $exception);
        }
    }
}
