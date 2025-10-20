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
     * @param callable       $operation       Operation to execute.
     * @param string         $pattern         Pattern used in the operation.
     * @param bool           $nullIndicatesError Whether a null result indicates an error condition.
     * @param string         $context         Additional context used in error messages.
     *
     * @return mixed
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
     * Wrapper for preg_replace.
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
     * Wrapper for preg_replace_callback.
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
     * Wrapper for preg_match.
     *
     * @return array<int|string, string>
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
     * Wrapper for preg_match_all.
     *
     * @return array<int, array<int, string>>
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
