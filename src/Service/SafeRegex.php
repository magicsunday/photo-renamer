<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Exception\RegexExecutionException;
use MagicSunday\Renamer\Service\Dto\RegexExecutionOutcome;
use MagicSunday\Renamer\Service\Dto\RegexMatchAllResult;
use MagicSunday\Renamer\Service\Dto\RegexMatchResult;
use MagicSunday\Renamer\Service\Dto\RegexReplaceResult;
use MagicSunday\Renamer\Service\Dto\RegexResultInterface;

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
     * @param callable():RegexExecutionOutcome $operation Callback that performs the actual regular expression work.
     * @param string                            $pattern   Regular expression pattern applied by the callback.
     * @param string                            $context   Short description inserted into error messages.
     *
     * @return RegexResultInterface Result of the executed operation.
     */
    private function execute(callable $operation, string $pattern, string $context): RegexResultInterface
    {
        set_error_handler(
            static function (int $severity, string $message) use ($pattern, $context): never {
                throw new RegexExecutionException(
                    sprintf('Regex failure while %s with pattern "%s": %s', $context, $pattern, $message),
                );
            },
        );

        try {
            $outcome = $operation();
        } finally {
            restore_error_handler();
        }

        if (!$outcome->isSuccessful()) {
            throw new RegexExecutionException(
                sprintf(
                    'Regex failure while %s with pattern "%s": %s',
                    $context,
                    $pattern,
                    preg_last_error_msg(),
                ),
            );
        }

        return $outcome->result();
    }

    /**
     * Wrapper for {@see preg_replace()} that converts warnings into exceptions.
     *
     * @param string $pattern     Regular expression pattern to search for.
     * @param string $replacement Replacement string used when the pattern matches.
     * @param string $subject     Input string being modified.
     *
     * @return RegexReplaceResult Resulting string after replacements.
     */
    public function replace(string $pattern, string $replacement, string $subject): RegexReplaceResult
    {
        /** @var RegexReplaceResult $result */
        $result = $this->execute(
            static function () use ($pattern, $replacement, $subject): RegexExecutionOutcome {
                $replaceResult = preg_replace($pattern, $replacement, $subject);

                if (!is_string($replaceResult)) {
                    return RegexExecutionOutcome::failure();
                }

                return RegexExecutionOutcome::success(new RegexReplaceResult($replaceResult));
            },
            $pattern,
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
     * @return RegexReplaceResult Resulting string after replacements.
     */
    public function replaceCallback(string $pattern, callable $callback, string $subject, string $contextDescription): RegexReplaceResult
    {
        /** @var RegexReplaceResult $result */
        $result = $this->execute(
            static function () use ($pattern, $callback, $subject): RegexExecutionOutcome {
                $replaceResult = preg_replace_callback($pattern, $callback, $subject);

                if (!is_string($replaceResult)) {
                    return RegexExecutionOutcome::failure();
                }

                return RegexExecutionOutcome::success(new RegexReplaceResult($replaceResult));
            },
            $pattern,
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
     * @return RegexMatchResult Captured matches indexed by offsets.
     */
    public function match(string $pattern, string $subject, string $contextDescription): RegexMatchResult
    {
        /** @var RegexMatchResult $result */
        $result = $this->execute(
            static function () use ($pattern, $subject): RegexExecutionOutcome {
                $matches = [];
                $matchResult = preg_match($pattern, $subject, $matches);

                if ($matchResult === false) {
                    return RegexExecutionOutcome::failure();
                }

                return RegexExecutionOutcome::success(new RegexMatchResult($matches));
            },
            $pattern,
            $contextDescription,
        );

        return $result;
    }

    /**
     * Wrapper for {@see preg_match_all()} that converts warnings into exceptions.
     *
     * @param string $pattern            Regular expression pattern to search for.
     * @param string $subject            Input string being matched.
     * @param string $contextDescription Description inserted into error messages on failure.
     *
     * @return RegexMatchAllResult Captured matches grouped by pattern index.
     */
    public function matchAll(string $pattern, string $subject, string $contextDescription): RegexMatchAllResult
    {
        /** @var RegexMatchAllResult $result */
        $result = $this->execute(
            static function () use ($pattern, $subject): RegexExecutionOutcome {
                $matches = [];
                $matchResult = preg_match_all($pattern, $subject, $matches);

                if ($matchResult === false) {
                    return RegexExecutionOutcome::failure();
                }

                /** @var array<int, array<int|string, string>> $matches */
                return RegexExecutionOutcome::success(new RegexMatchAllResult($matches));
            },
            $pattern,
            $contextDescription,
        );

        return $result;
    }
}
