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

final class SafeRegex
{
    /**
     * Executes a regular expression operation while converting PHP warnings into exceptions.
     *
     * @template T
     *
     * @param callable(): T $operation callback that performs the actual regular expression work
     * @param string        $pattern   regular expression pattern applied by the callback
     * @param string        $context   short description inserted into error messages
     *
     * @return T result of the executed operation
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
     *
     * @param string $pattern     regular expression pattern to search for
     * @param string $replacement replacement string used when the pattern matches
     * @param string $subject     input string being modified
     *
     * @return string resulting string after replacements
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
     *
     * @param string   $pattern            regular expression pattern to search for
     * @param callable $callback           callback invoked for each match
     * @param string   $subject            input string being modified
     * @param string   $contextDescription description inserted into error messages on failure
     *
     * @return string resulting string after replacements
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
     *
     * @param string $pattern            regular expression pattern to search for
     * @param string $subject            input string being matched
     * @param string $contextDescription description inserted into error messages on failure
     *
     * @return RegexMatchResult captured matches indexed by offsets
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
     *
     * @param string $pattern            regular expression pattern to search for
     * @param string $subject            input string being matched
     * @param string $contextDescription description inserted into error messages on failure
     *
     * @return RegexMatchAllResult captured matches grouped by pattern index
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
