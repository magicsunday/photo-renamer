<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Regex;

use MagicSunday\Renamer\Exception\RegexExecutionException;

use function is_string;
use function preg_last_error_msg;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

/**
 * Safe wrapper around PHP's preg_* functions that installs a temporary error
 * handler to convert PCRE warnings into typed RegexExecutionException instances.
 * Provides match, matchAll, replace and replaceCallback operations with
 * consistent error handling and descriptive exception messages.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class SafeRegex
{
    /**
     * Executes a regular expression operation while converting PHP warnings into exceptions.
     * This ensures that any logic error in the regex (like invalid syntax) is caught
     * as a typed exception rather than a silent warning.
     *
     * @template T
     *
     * @param callable(): T $operation Callback that performs the actual regular expression work.
     * @param string        $pattern   The regular expression pattern applied by the callback.
     * @param string        $context   Short description inserted into error messages to identify
     *                                 where the failure occurred (e.g., "executing preg_match").
     *
     * @return T The result of the executed operation.
     *
     * @throws RegexExecutionException If the regex operation fails or triggers a warning.
     */
    private function execute(callable $operation, string $pattern, string $context): mixed
    {
        set_error_handler(
            static function (int $severity, string $message) use ($pattern, $context): never {
                throw new RegexExecutionException(
                    sprintf('Regex failure while %s with pattern "%s": %s', $context, $pattern, $message),
                );
            },
        );

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Wrapper for {@see preg_replace()} that converts warnings into exceptions.
     * Replaces occurrences of the pattern in the subject with the replacement string.
     *
     * @param string $pattern     The regular expression pattern to search for.
     * @param string $replacement The replacement string used when the pattern matches.
     * @param string $subject     The input string being modified.
     *
     * @return string The resulting string after all replacements have been applied.
     *
     * @throws RegexExecutionException If the replacement fails (e.g., due to backtracking limits).
     */
    public function replace(string $pattern, string $replacement, string $subject): string
    {
        return $this->execute(
            static function () use ($pattern, $replacement, $subject): string {
                $result = preg_replace($pattern, $replacement, $subject);

                if (!is_string($result)) {
                    throw new RegexExecutionException(
                        sprintf(
                            'Regex failure while executing preg_replace with pattern "%s": %s',
                            $pattern,
                            preg_last_error_msg(),
                        ),
                    );
                }

                return $result;
            },
            $pattern,
            'executing preg_replace',
        );
    }

    /**
     * Wrapper for {@see preg_replace_callback()} that converts warnings into exceptions.
     * Invokes the given callback for each match to determine the replacement string.
     *
     * @param string                                      $pattern            The regular expression pattern to search for.
     * @param callable(array<int|string, string>): string $callback           The callback invoked for each match.
     * @param string                                      $subject            The input string being modified.
     * @param string                                      $contextDescription Description inserted into error messages on failure.
     *
     * @return string The resulting string after replacements.
     *
     * @throws RegexExecutionException If the operation fails.
     */
    public function replaceCallback(string $pattern, callable $callback, string $subject, string $contextDescription): string
    {
        return $this->execute(
            static function () use ($pattern, $callback, $subject, $contextDescription): string {
                $result = preg_replace_callback($pattern, $callback, $subject);

                if (!is_string($result)) {
                    throw new RegexExecutionException(
                        sprintf(
                            'Regex failure while %s with pattern "%s": %s',
                            $contextDescription,
                            $pattern,
                            preg_last_error_msg(),
                        ),
                    );
                }

                return $result;
            },
            $pattern,
            $contextDescription,
        );
    }

    /**
     * Wrapper for {@see preg_match()} that converts warnings into exceptions.
     * Performs a single match and returns a result object containing the captured data.
     *
     * @param string $pattern            The regular expression pattern to search for.
     * @param string $subject            The input string to search within.
     * @param string $contextDescription Description inserted into error messages on failure.
     *
     * @return RegexMatchResult Captured matches indexed by offsets.
     *
     * @throws RegexExecutionException If the matching operation fails.
     */
    public function match(string $pattern, string $subject, string $contextDescription): RegexMatchResult
    {
        return $this->execute(
            static function () use ($pattern, $subject, $contextDescription): RegexMatchResult {
                $matches     = [];
                $matchResult = preg_match($pattern, $subject, $matches);

                if ($matchResult === false) {
                    throw new RegexExecutionException(
                        sprintf(
                            'Regex failure while %s with pattern "%s": %s',
                            $contextDescription,
                            $pattern,
                            preg_last_error_msg(),
                        ),
                    );
                }

                return new RegexMatchResult($matches);
            },
            $pattern,
            $contextDescription,
        );
    }

    /**
     * Wrapper for {@see preg_match_all()} that converts warnings into exceptions.
     * Performs a global search and returns all matches in a nested structure.
     *
     * @param string $pattern            The regular expression pattern to search for.
     * @param string $subject            The input string to search within.
     * @param string $contextDescription Description inserted into error messages on failure.
     *
     * @return RegexMatchAllResult Captured matches grouped by pattern index.
     *
     * @throws RegexExecutionException If the matching operation fails.
     */
    public function matchAll(string $pattern, string $subject, string $contextDescription): RegexMatchAllResult
    {
        return $this->execute(
            static function () use ($pattern, $subject, $contextDescription): RegexMatchAllResult {
                $matches     = [];
                $matchResult = preg_match_all($pattern, $subject, $matches);

                if ($matchResult === false) {
                    throw new RegexExecutionException(
                        sprintf(
                            'Regex failure while %s with pattern "%s": %s',
                            $contextDescription,
                            $pattern,
                            preg_last_error_msg(),
                        ),
                    );
                }

                /** @var array<int, array<int|string, string>> $matches */
                return new RegexMatchAllResult($matches);
            },
            $pattern,
            $contextDescription,
        );
    }
}
