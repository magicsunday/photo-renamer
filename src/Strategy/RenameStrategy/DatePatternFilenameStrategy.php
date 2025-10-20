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
use MagicSunday\Renamer\Model\Pattern\PatternMatchSet;
use MagicSunday\Renamer\Service\SafeRegex;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\RegexMatchCollection;
use Override;
use SplFileInfo;

use function count;
use function strlen;
use function str_replace;

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

    private readonly PatternMatchSet $patternMatches;

    /**
     * Constructor.
     *
     * @param string          $pattern
     * @param string          $replacement
     * @param PatternMatchSet $patternMatches
     */
    public function __construct(
        string $pattern,
        string $replacement,
        PatternMatchSet $patternMatches,
        private readonly SafeRegex $regex,
    ) {
        $this->pattern        = $pattern;
        $this->replacement    = $replacement;
        $this->patternMatches = $patternMatches;
    }

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

            $dateFormatCharacters = $this->patternMatches->placeholders();
            $targetFilenamePattern = $this->replacement;

            if ($replacementMatches->hasGroup(0) && $replacementMatches->hasGroup(1)) {
                $targetFilenamePattern = str_replace(
                    $replacementMatches->group(0)?->values() ?? [],
                    $replacementMatches->group(1)?->values() ?? [],
                    $this->replacement,
                );
            }

            $suffixIndex = $filePartMatches->count() > 0 ? $filePartMatches->count() - 1 : 0;

            return $this->regex->replaceCallback(
                $this->pattern,
                static function (array $matches) use ($dateFormatCharacters, $targetFilenamePattern, $suffixIndex): string {
                    $dateParts = [];

                    foreach ($dateFormatCharacters as $key => $dateFormatCharacter) {
                        if ($dateFormatCharacter === 'y') {
                            $dateFormatCharacter = 'Y';
                        }

                        if ($dateFormatCharacter === 'Y' && strlen($matches[$key + 1]) === 2) {
                            $fourDigitYearDate = DateTime::createFromFormat('y', $matches[$key + 1]);

                            if ($fourDigitYearDate !== false) {
                                $matches[$key + 1] = $fourDigitYearDate->format('Y');
                            }
                        }

                        $dateParts[$dateFormatCharacter] = (int) $matches[$key + 1];
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
            throw new TargetFilenameException(
                'Date pattern error: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
