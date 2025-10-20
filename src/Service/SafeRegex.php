<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\RegexExecutionException;

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
     * @param callable $operation          Callback that performs the actual regular expression work.
     * @param string   $pattern            Regular expression pattern applied by the callback.
     * @param bool     $nullIndicatesError Whether a null result should be treated as an error.
     * @param string   $context            Short description inserted into error messages.
     *
     * @return mixed Result of the executed operation.
     */
    private function execute(callable $operation, string $pattern, bool $nullIndicatesError, string $context): mixed
    {
        set_error_handler(
            static function (int $severity, string $message) use ($pattern, $context): never {
                throw new RegexExecutionException(
                    sprintf('Regex failure while %s with pattern "%s": %s', $context, $pattern, $message),
                );
            },
        );

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        if ($result === false || ($nullIndicatesError && $result === null)) {
            throw new RegexExecutionException(
                sprintf(
                    'Regex failure while %s with pattern "%s": %s',
                    $context,
                    $pattern,
                    preg_last_error_msg(),
                ),
            );
        }

        return $result;
    }

    /**
     * Wrapper for {@see preg_replace()} that converts warnings into exceptions.
     *
     * @param string $pattern     Regular expression pattern to search for.
     * @param string $replacement Replacement string used when the pattern matches.
     * @param string $subject     Input string being modified.
     *
     * @return string Resulting string after replacements.
     */
    public function replace(string $pattern, string $replacement, string $subject): string
    {
        /** @var string $result */
        $result = $this->execute(
            static function () use ($pattern, $replacement, $subject) {
                return preg_replace($pattern, $replacement, $subject);
            },
            $pattern,
            true,
            'executing preg_replace',
        );

        return $result;
    }

    /**
     * Wrapper for {@see preg_replace_callback()} that converts warnings into exceptions.
     *
     * @param string   $pattern            Regular expression pattern to search for.
     * @param callable $callback           Callback invoked for each match.
     * @param string   $subject            Input string being modified.
     * @param string   $contextDescription Description inserted into error messages on failure.
     *
     * @return string Resulting string after replacements.
     */
    public function replaceCallback(string $pattern, callable $callback, string $subject, string $contextDescription): string
    {
        /** @var string $result */
        $result = $this->execute(
            static function () use ($pattern, $callback, $subject) {
                return preg_replace_callback($pattern, $callback, $subject);
            },
            $pattern,
            true,
            $contextDescription,
        );

        return $result;
    }

    /**
     * Wrapper for {@see preg_match()} that converts warnings into exceptions.
     *
     * @param string $pattern            Regular expression pattern to search for.
     * @param string $subject            Input string being matched.
     * @param string $contextDescription Description inserted into error messages on failure.
     *
     * @return array<int|string, string> Captured matches indexed by offsets.
     */
    public function match(string $pattern, string $subject, string $contextDescription): array
    {
        $matches = [];

        $this->execute(
            static function () use ($pattern, $subject, &$matches) {
                return preg_match($pattern, $subject, $matches);
            },
            $pattern,
            false,
            $contextDescription,
        );

        return $matches;
    }

    /**
     * Wrapper for {@see preg_match_all()} that converts warnings into exceptions.
     *
     * @param string $pattern            Regular expression pattern to search for.
     * @param string $subject            Input string being matched.
     * @param string $contextDescription Description inserted into error messages on failure.
     *
     * @return array<int, array<int, string>> Captured matches grouped by pattern index.
     */
    public function matchAll(string $pattern, string $subject, string $contextDescription): array
    {
        $matches = [];

        $this->execute(
            static function () use ($pattern, $subject, &$matches) {
                return preg_match_all($pattern, $subject, $matches);
            },
            $pattern,
            false,
            $contextDescription,
        );

        return $matches;
    }
}
