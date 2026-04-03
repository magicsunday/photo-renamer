<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Output;

use MagicSunday\Renamer\Helper\PathHelper;
use MagicSunday\Renamer\Model\LinkConfig;
use MagicSunday\Renamer\Model\OutputEntry;
use MagicSunday\Renamer\Model\OutputEntryTag;
use Symfony\Component\Console\Style\SymfonyStyle;

use function in_array;
use function max;
use function mb_strlen;
use function sprintf;
use function str_repeat;

/**
 * Presents semantic output entries as concrete operator-facing CLI lines.
 *
 * The output module already projects semantic `OutputEntry` objects. This
 * presenter owns the remaining console-facing layout decisions such as filter
 * visibility, padding alignment, two-line reason blocks, and render counters so
 * the top-level renderer no longer has to carry that detailed line-rendering
 * logic itself.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class OutputEntryPresenter
{
    /**
     * @param OutputSkipReasonDecider $skipReasonDecider   Semantic skip-reason decider used for skipped rename entries
     * @param SkipReasonFormatter     $skipReasonFormatter Operator-facing skip-reason formatter
     * @param DiffHighlighter         $diffHighlighter     Diff highlighter used for one-line rename output
     */
    public function __construct(
        private OutputSkipReasonDecider $skipReasonDecider,
        private SkipReasonFormatter $skipReasonFormatter,
        private DiffHighlighter $diffHighlighter,
    ) {
    }

    /**
     * Renders the given entries and returns the resulting execution counters.
     *
     * @param list<OutputEntry> $outputEntries       Sorted output entries to render
     * @param SymfonyStyle      $io                  Console IO used for output
     * @param string|null       $sourceBaseDirectory Base directory used for relative links
     * @param list<string>|null $showFilter          Visible tag letters or null for all tags
     *
     * @return OutputCounters Immutable counters describing the rendered execution set
     */
    public function render(
        array $outputEntries,
        SymfonyStyle $io,
        ?string $sourceBaseDirectory = null,
        ?array $showFilter = null,
    ): OutputCounters {
        $maxFilenameLength = $this->resolveMaxFilenameLength($outputEntries, $showFilter);
        $linkConfig        = LinkConfig::fromEnv();

        $fileCount           = 0;
        $duplicateCount      = 0;
        $plannedMoves        = 0;
        $plannedSkips        = 0;
        $lastRenderedSortKey = null;

        foreach ($outputEntries as $entry) {
            $padding    = str_repeat(' ', max(0, $maxFilenameLength - mb_strlen($entry->sourcePath)));
            $linkedPath = PathHelper::linkifyPath($entry->sourcePath, $entry->sourcePath, $sourceBaseDirectory, $linkConfig, 'yellow');

            if ($entry->isInfo()) {
                if (!$this->isTagVisible($entry->tag, $showFilter)) {
                    continue;
                }

                if ($lastRenderedSortKey === $entry->sortKey) {
                    $io->text(sprintf(
                        '     <fg=cyan>→</> <fg=%s>%s</>',
                        $entry->tag->color(),
                        $entry->reason ?? '',
                    ));
                } else {
                    $this->renderTwoLineReasonBlock($entry->tag, $linkedPath, $entry->reason ?? '', $io);
                }

                $lastRenderedSortKey = $entry->sortKey;

                continue;
            }

            if ($entry->isSkip()) {
                if ($this->isTagVisible($entry->tag, $showFilter)) {
                    $this->renderTwoLineReasonBlock($entry->tag, $linkedPath, $entry->reason ?? '', $io);
                    $lastRenderedSortKey = $entry->sortKey;
                }

                continue;
            }

            if ($this->isTagVisible($entry->tag, $showFilter)) {
                if ($entry->shouldSkip) {
                    $skipReason = $this->skipReasonFormatter->format(
                        $this->skipReasonDecider->decide($entry),
                    );

                    $this->renderTwoLineReasonBlock($entry->tag, $linkedPath, $skipReason, $io);
                } else {
                    $io->text(sprintf(
                        ' %s %s' . $padding . ' <fg=cyan>→</> %s',
                        $entry->tag->formattedTag(),
                        $linkedPath,
                        $this->diffHighlighter->highlightDiff($entry->sourcePath, $entry->targetPath ?? '', 'green'),
                    ));
                }

                $lastRenderedSortKey = $entry->sortKey;
            }

            if ($entry->isDuplicateTarget) {
                ++$duplicateCount;
            }

            if ($entry->shouldSkip) {
                ++$plannedSkips;
            } elseif ($entry->shouldPerformOperation) {
                ++$plannedMoves;
                ++$fileCount;
            }
        }

        return new OutputCounters(
            fileCount: $fileCount,
            duplicateCount: $duplicateCount,
            plannedMoves: $plannedMoves,
            plannedSkips: $plannedSkips,
        );
    }

    /**
     * Computes the maximum visible source-path length for padding alignment.
     *
     * Hidden entries do not influence alignment so filtered output stays tight
     * instead of reserving width for lines the user chose not to display.
     *
     * @param list<OutputEntry> $outputEntries Entries that may be rendered
     * @param list<string>|null $showFilter    Visible tag letters or null for all tags
     *
     * @return int Maximum visible source-path length
     */
    private function resolveMaxFilenameLength(array $outputEntries, ?array $showFilter): int
    {
        $maxFilenameLength = 0;

        foreach ($outputEntries as $entry) {
            if (!$this->isTagVisible($entry->tag, $showFilter)) {
                continue;
            }

            $maxFilenameLength = max($maxFilenameLength, mb_strlen($entry->sourcePath));
        }

        return $maxFilenameLength;
    }

    /**
     * Checks whether the given tag should be visible for the current filter.
     *
     * @param OutputEntryTag    $tag        Entry tag being evaluated
     * @param list<string>|null $showFilter Visible tag letters or null for all tags
     *
     * @return bool True when the entry should be rendered
     */
    private function isTagVisible(OutputEntryTag $tag, ?array $showFilter): bool
    {
        return ($showFilter === null) || in_array($tag->letter(), $showFilter, true);
    }

    /**
     * Renders a two-line block with the tagged source path and colored reason.
     *
     * Long reasons intentionally move to a second line so the source path stays
     * easy to scan in dense rename output.
     *
     * @param OutputEntryTag $tag        Visual tag of the rendered entry
     * @param string         $linkedPath Source path already prepared for output
     * @param string         $reason     Human-readable explanation shown on the second line
     * @param SymfonyStyle   $io         Console IO used for output
     */
    private function renderTwoLineReasonBlock(OutputEntryTag $tag, string $linkedPath, string $reason, SymfonyStyle $io): void
    {
        $io->text(sprintf(
            ' %s %s',
            $tag->formattedTag(),
            $linkedPath,
        ));
        $io->text(sprintf(
            '     <fg=cyan>→</> <fg=%s>%s</>',
            $tag->color(),
            $reason,
        ));
    }
}
