<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Filesystem;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\PrivateCacheStorage;
use MagicSunday\Renamer\Model\Execution\ExecutionGroup;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function base64_encode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Streams private, append-only mutation evidence during the application's lock
 * scope. Intents precede media writes, receipts follow successful writes, and a
 * missing receipt never reverses an already completed operation in the caller's
 * counters. Journal failure prevents subsequent mutations. Paths are encoded
 * losslessly because filesystem names need not contain valid UTF-8.
 *
 * This is diagnostic recovery evidence, not a transaction or automatic rollback.
 * An interrupted intent is uncertain and must be reconciled with fresh disk
 * state. Direct internal callers remain inactive unless they explicitly begin a
 * scope; the normal application always begins one before command analysis.
 */
final class MutationJournal
{
    /**
     * Current private journal pathname, or null outside a mutating CLI scope.
     */
    private ?string $pathname = null;

    /**
     * First persistence failure; retained so the application cannot return success.
     */
    private ?string $failure = null;

    /**
     * Monotonic operation IDs associate intent and outcome without retained history.
     */
    private int $nextOperation = 0;

    /**
     * @param Filesystem          $filesystem     Creates a private unique journal inode
     * @param PrivateCacheStorage $storage        Enforces owner, single-link and append-only leaf checks
     * @param string|null         $stateDirectory Host state base shared with the mutation lock; null selects the runtime default
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly PrivateCacheStorage $storage,
        private readonly ?string $stateDirectory,
    ) {
    }

    /**
     * Creates a separate journal for this run before any analysis or mutation.
     * Failed initialization throws so no media write can start without evidence.
     *
     * @param string $command Resolved writing command name
     *
     * @return void
     */
    public function begin(string $command): void
    {
        $this->failure       = null;
        $this->nextOperation = 0;
        $directory           = $this->storage->directory(FileHelper::resolveCacheDirectory($this->stateDirectory));
        $this->pathname      = $this->filesystem->tempnam($directory, 'mutation-', '.jsonl');
        $this->append(['event' => 'run_started', 'schema' => 1, 'command_b64' => base64_encode($command)]);
    }

    /**
     * Performs one media action only after persisting its intent. Action errors
     * remain their original exceptions; a failed receipt is retained as a journal
     * error instead of throwing past a successful physical mutation.
     *
     * @param string          $operation Operation kind: rename, quarantine, delete or write_date
     * @param string          $source    Exact source path before mutation
     * @param string|null     $target    Actual selected target, or null for in-place writes/deletion
     * @param Closure(): void $action    Physical media mutation, invoked once after a successful intent
     *
     * @return void
     */
    public function perform(string $operation, string $source, ?string $target, Closure $action): void
    {
        if ($this->pathname === null) {
            $action();

            return;
        }

        $id = ++$this->nextOperation;
        $this->append([
            'event'      => 'intent',
            'id'         => $id,
            'operation'  => $operation,
            'source_b64' => base64_encode($source),
            'target_b64' => $target === null ? null : base64_encode($target),
        ]);

        try {
            $action();
        } catch (Throwable $exception) {
            $this->tryAppend(['event' => 'failed', 'id' => $id, 'detail_b64' => base64_encode($exception->getMessage())]);

            throw $exception;
        }

        $this->tryAppend(['event' => 'completed', 'id' => $id]);
    }

    /**
     * Streams the planned Live Photo components before the first move so an
     * abrupt exit identifies not-yet-attempted peers, including paired still
     * variants. Unrelated duplicates remain outside the component records.
     *
     * @param ExecutionGroup $group Live Photo group about to execute
     *
     * @return void
     */
    public function planLivePhoto(ExecutionGroup $group): void
    {
        if ($this->pathname === null) {
            return;
        }

        foreach ($group->getLivePhotoComponents() as $item) {
            $this->append([
                'event'              => 'pair_member',
                'group_b64'          => base64_encode($group->groupKey),
                'source_b64'         => base64_encode($item->sourcePath),
                'planned_target_b64' => base64_encode($item->targetPath),
                'is_noop'            => $item->isNoOp ? 1 : 0,
            ]);
        }
    }

    /**
     * Records the observed pair outcome without hiding already completed moves
     * if this acknowledgement fails. A missing record remains uncertain.
     *
     * @param string $groupKey Capture key matching the component-plan records
     * @param string $status   Observed outcome: complete, partial or unprocessed
     *
     * @return void
     */
    public function livePhotoResult(string $groupKey, string $status): void
    {
        $this->tryAppend(['event' => 'pair_result', 'group_b64' => base64_encode($groupKey), 'status' => $status]);
    }

    /**
     * Finishes a healthy run and releases the scope. A missing final record is
     * explicitly unsuccessful; journals are never removed during cleanup.
     *
     * @param int $exitCode Command status, including failures before physical execution
     *
     * @return bool Whether every journal record, including the final status, was persisted
     */
    public function finish(int $exitCode): bool
    {
        if (($this->pathname !== null) && ($this->failure === null)) {
            $this->tryAppend(['event' => 'run_finished', 'exit_code' => $exitCode]);
        }

        $this->pathname = null;

        return $this->failure === null;
    }

    /**
     * Returns the active scope's first persistence error so callers stop writes
     * without subtracting successes. Finished scopes cannot poison a later
     * dry-run or read-only invocation using the same application instance.
     *
     * @return string|null First journal failure, or null while persistence is healthy
     */
    public function failure(): ?string
    {
        return $this->pathname === null ? null : $this->failure;
    }

    /**
     * Returns the private journal location for operator diagnostics and recovery.
     *
     * @return string|null Current run's journal, or null outside the active scope
     */
    public function pathname(): ?string
    {
        return $this->pathname;
    }

    /**
     * Persists a receipt without throwing past a completed physical mutation.
     * The first failure remains authoritative and blocks later intents.
     *
     * @param array<string, int|string|null> $record Scalar event fields, with lossless encoded paths/details
     *
     * @return void
     */
    private function tryAppend(array $record): void
    {
        try {
            $this->append($record);
        } catch (RuntimeException) {
            // append() preserves the first reason; callers inspect failure().
        }
    }

    /**
     * Serializes and appends one complete JSONL record with a UTC timestamp.
     * Failed/partial writes leave an uncertain tail rather than recreating or
     * rewriting history. No whole-batch operation history is kept in memory.
     *
     * @param array<string, int|string|null> $record Scalar event fields
     *
     * @return void
     */
    private function append(array $record): void
    {
        if ($this->pathname === null) {
            return;
        }

        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        $record['time'] = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);

        try {
            $this->storage->append($this->pathname, json_encode($record, JSON_THROW_ON_ERROR) . "\n");
        } catch (RuntimeException|JsonException $exception) {
            $this->failure = 'Mutation journal failed; further media writes withheld: ' . $exception->getMessage();

            throw new RuntimeException($this->failure, $exception->getCode(), $exception);
        }
    }
}
