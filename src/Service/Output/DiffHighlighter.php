<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

use function count;
use function max;
use function mb_str_split;
use function mb_stripos;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function strrpos;
use function substr;

/**
 * Highlights visual differences between source and target pathnames.
 *
 * The output renderer and the legacy execution path both need consistent diff
 * highlighting. Extracting this logic into a dedicated collaborator removes the
 * path-diff algorithm from the renderer without changing its public surface.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class DiffHighlighter
{
    /**
     * Highlights differences between source and target paths using color-coded output.
     *
     * @param string $source    The original path
     * @param string $target    The new path to highlight
     * @param string $baseColor The base color for unchanged parts
     *
     * @return string ANSI-highlighted target path
     */
    public function highlightDiff(string $source, string $target, string $baseColor): string
    {
        if ($source === $target) {
            return sprintf('<fg=%s>%s</>', $baseColor, $target);
        }

        $sourceSplit = $this->splitPathPrefix($source);
        $targetSplit = $this->splitPathPrefix($target);

        if ($sourceSplit->directoryPrefix !== $targetSplit->directoryPrefix) {
            return $this->highlightSequentialTokenDiff($source, $target, $baseColor);
        }

        return sprintf('<fg=%s>%s</>', $baseColor, $targetSplit->directoryPrefix)
            . $this->highlightSequentialTokenDiff($sourceSplit->filename, $targetSplit->filename, $baseColor);
    }

    /**
     * Splits a path into directory prefix and filename.
     *
     * @param string $path Pathname to split
     *
     * @return PathPrefixSplit Directory prefix and filename wrapped in an explicit value object
     */
    private function splitPathPrefix(string $path): PathPrefixSplit
    {
        $slashPos     = strrpos($path, '/');
        $backslashPos = strrpos($path, '\\');

        $lastSlashPos = max(
            $slashPos === false ? -1 : $slashPos,
            $backslashPos === false ? -1 : $backslashPos,
        );

        if ($lastSlashPos < 0) {
            return new PathPrefixSplit('', $path);
        }

        return new PathPrefixSplit(
            substr($path, 0, $lastSlashPos + 1),
            substr($path, $lastSlashPos + 1),
        );
    }

    /**
     * Highlights a target string by sequentially matching its tokens against the source.
     *
     * @param string $source    The original filename or path segment
     * @param string $target    The target filename or path segment
     * @param string $baseColor ANSI color for unchanged segments
     *
     * @return string ANSI-highlighted target segment
     */
    private function highlightSequentialTokenDiff(string $source, string $target, string $baseColor): string
    {
        $tokens = $this->tokenizeForSequentialDiff($target);
        $flags  = $this->matchTargetTokensSequentially($source, $tokens);

        return $this->renderHighlightedTokens($tokens, $flags, $baseColor);
    }

    /**
     * Tokenizes a string into alphanumeric runs and separator runs.
     *
     * @param string $value Value to tokenize
     *
     * @return list<string> Sequential tokens
     */
    private function tokenizeForSequentialDiff(string $value): array
    {
        preg_match_all('/[[:alnum:]]+|[^[:alnum:]]/u', $value, $matches);

        /** @var list<string> $tokens */
        $tokens = $matches[0];

        return $tokens;
    }

    /**
     * Matches target tokens against the source string and determines their states.
     *
     * @param string       $source The original string to match against
     * @param list<string> $tokens Tokenized target string
     *
     * @return list<DiffTokenState> States for each token
     */
    private function matchTargetTokensSequentially(string $source, array $tokens): array
    {
        $states      = [];
        $sourceChars = mb_str_split($source);
        $sourceLen   = count($sourceChars);
        $offset      = 0;

        foreach ($tokens as $token) {
            if ($this->isSeparatorToken($token)) {
                $matched = $this->matchSeparatorNearOffset($sourceChars, $sourceLen, $token, $offset);

                $states[] = $matched ? DiffTokenState::Same : DiffTokenState::Changed;

                if ($matched) {
                    $offset += mb_strlen($token);
                }

                continue;
            }

            $position = $this->findTokenPosition($source, $token, $offset);

            if ($position === null) {
                $states[] = DiffTokenState::Changed;

                continue;
            }

            $sourceToken = mb_substr($source, $position, mb_strlen($token));

            if ($sourceToken === $token) {
                $states[] = DiffTokenState::Same;
            } elseif (mb_strtolower($sourceToken) === mb_strtolower($token)) {
                $states[] = DiffTokenState::CaseChanged;
            } else {
                $states[] = DiffTokenState::Changed;
            }

            $offset = $position + mb_strlen($token);
        }

        return $states;
    }

    /**
     * Finds an alphanumeric token in the source string starting at the given offset.
     *
     * @param string $source Original source string
     * @param string $token  Token to search
     * @param int    $offset Start offset for the search
     *
     * @return int|null Character offset or null when not found
     */
    private function findTokenPosition(string $source, string $token, int $offset): ?int
    {
        $position = mb_stripos($source, $token, $offset);

        if ($position === false) {
            return null;
        }

        return $position;
    }

    /**
     * Attempts to match a separator token near the current source offset.
     *
     * @param list<string> $sourceChars Multibyte character array of the source string
     * @param int          $sourceLen   Total number of characters in the source
     * @param string       $token       Separator token to match
     * @param int          $offset      Current character offset
     *
     * @return bool True if a match was found within the lookahead window
     */
    private function matchSeparatorNearOffset(array $sourceChars, int $sourceLen, string $token, int $offset): bool
    {
        $tokenChars = mb_str_split($token);
        $tokenLen   = count($tokenChars);

        for ($lookahead = 0; $lookahead <= 1; ++$lookahead) {
            $matched = true;

            for ($i = 0; $i < $tokenLen; ++$i) {
                $sourceIndex = $offset + $lookahead + $i;

                if (($sourceIndex >= $sourceLen) || ($sourceChars[$sourceIndex] !== $tokenChars[$i])) {
                    $matched = false;

                    break;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the token contains only non-alphanumeric characters.
     *
     * @param string $token Token to inspect
     *
     * @return bool True for separator-only tokens
     */
    private function isSeparatorToken(string $token): bool
    {
        return preg_match('/^[^[:alnum:]]+$/u', $token) === 1;
    }

    /**
     * Renders the tokenized target string with ANSI color codes based on match states.
     *
     * @param list<string>         $tokens    Original tokens
     * @param list<DiffTokenState> $states    Match states per token
     * @param string               $baseColor Color to use for unchanged segments
     *
     * @return string ANSI-formatted diff output
     */
    private function renderHighlightedTokens(array $tokens, array $states, string $baseColor): string
    {
        $result       = '';
        $buffer       = '';
        $currentState = null;

        foreach ($tokens as $index => $token) {
            $state = $states[$index];

            if (($currentState !== null) && ($state !== $currentState) && ($buffer !== '')) {
                $result .= $this->formatDiffSegment($buffer, $currentState, $baseColor);
                $buffer = '';
            }

            $buffer .= $token;
            $currentState = $state;
        }

        if (($buffer !== '') && ($currentState instanceof DiffTokenState)) {
            $result .= $this->formatDiffSegment($buffer, $currentState, $baseColor);
        }

        return $result;
    }

    /**
     * Formats a single diff segment with ANSI colors.
     *
     * @param string         $value     Text segment to format
     * @param DiffTokenState $state     Match state for the segment
     * @param string         $baseColor Base color for unchanged segments
     *
     * @return string ANSI-formatted segment
     */
    private function formatDiffSegment(string $value, DiffTokenState $state, string $baseColor): string
    {
        return match ($state) {
            DiffTokenState::Same        => sprintf('<fg=%s>%s</>', $baseColor, $value),
            DiffTokenState::CaseChanged => sprintf('<fg=bright-%s;options=bold>%s</>', $baseColor, $value),
            default                     => sprintf('<fg=bright-%s;options=bold>%s</>', $baseColor, $value),
        };
    }
}
