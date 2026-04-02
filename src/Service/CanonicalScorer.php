<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service;

use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Model\AssetGroup;
use MagicSunday\Renamer\Model\AssetItem;
use Override;

use function count;
use function max;
use function rtrim;
use function sprintf;
use function strlen;

use const DIRECTORY_SEPARATOR;

/**
 * Computes a weighted priority score for each AssetItem in an AssetGroup to determine
 * which file becomes the canonical representative. Format priority is the dominant
 * signal — a preferred format (HEIC) always beats a correctly-named lower-priority
 * format (JPG), even if the JPG already has the correct filename.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class CanonicalScorer implements CanonicalScorerInterface
{
    /**
     * Multiplier applied per format priority rank (highest-priority format gets the largest bonus).
     */
    private const int SCORE_FORMAT_FACTOR = 10000;

    /**
     * Bonus for a file whose basename already matches the group key (idempotent rename).
     */
    private const int SCORE_IDEMPOTENCY = 1000;

    /**
     * Bonus for a file located directly in the source root directory.
     */
    private const int SCORE_ROOT_DIRECTORY = 50;

    /**
     * Bonus for a file that carries a Live Photo content identifier.
     */
    private const int SCORE_LIVE_PHOTO_ID = 25;

    /**
     * Maximum tie-break score awarded based on path length (shorter path wins).
     */
    private const int SCORE_TIE_BREAK_MAX = 20;

    /**
     * Mapping of normalized extension to its index in the format priority list.
     *
     * @var array<string, int>
     */
    private array $formatIndex = [];

    /**
     * Number of entries in the format priority list.
     */
    private int $formatCount = 0;

    /**
     * Normalized source directory path (with trailing separator).
     */
    private string $normalizedSourceDir = '';

    /**
     * Sets the ordered format priority list, building an internal index
     * mapping each normalized extension to its rank.
     *
     * @param list<string> $formatPriority Extensions ordered by descending preference
     */
    #[Override]
    public function setFormatPriority(array $formatPriority): void
    {
        $this->formatIndex = [];

        foreach ($formatPriority as $index => $extension) {
            $this->formatIndex[FileHelper::normalizeExtension($extension)] = $index;
        }

        $this->formatCount = count($formatPriority);
    }

    /**
     * Stores and normalizes the source directory path for root-directory bonus scoring.
     *
     * @param string $sourceDirectory Absolute path to the directory being processed
     */
    #[Override]
    public function setSourceDirectory(string $sourceDirectory): void
    {
        $this->normalizedSourceDir = rtrim($sourceDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Computes and assigns a weighted priority score to every item in the group
     * via {@see AssetGroup::replaceItem()}.
     *
     * @param AssetGroup $group Group whose items will be scored
     */
    #[Override]
    public function scoreItems(AssetGroup $group): void
    {
        foreach ($group->getItems() as $item) {
            [$score, $reasoning] = $this->computeScore($item, $group);

            $group->replaceItem($item, $item->withScore($score, $reasoning));
        }
    }

    /**
     * Returns the item with the highest priority score, or null if the group is empty.
     *
     * @param AssetGroup $group Group to select from
     *
     * @return AssetItem|null Highest-scored item
     */
    #[Override]
    public function selectCanonical(AssetGroup $group): ?AssetItem
    {
        $best = null;

        foreach ($group->getItems() as $item) {
            if (($best === null) || ($item->priorityScore > $best->priorityScore)) {
                $best = $item;
            }
        }

        return $best;
    }

    /**
     * Computes the total score and reasoning strings for a single item.
     * Scoring favors preferred formats, already correctly named files,
     * files in the root directory, and Live Photo assets.
     *
     * @param AssetItem  $item  Asset to score
     * @param AssetGroup $group Parent group for context (e.g. groupKey for idempotency)
     *
     * @return array{int, list<string>} Tuple of [totalScore, reasons]
     */
    private function computeScore(AssetItem $item, AssetGroup $group): array
    {
        $score     = 0;
        $reasoning = [];

        // Format priority
        $extension   = FileHelper::normalizeExtension($item->file->getExtension());
        $formatScore = $this->computeFormatScore($extension);

        if ($formatScore > 0) {
            $score += $formatScore;
            $reasoning[] = sprintf('format:%s=%d', $extension, $formatScore);
        }

        // Idempotency
        if (FileHelper::basenameWithoutExtension($item->file) === $group->groupKey) {
            $score += self::SCORE_IDEMPOTENCY;
            $reasoning[] = sprintf('idempotent=%d', self::SCORE_IDEMPOTENCY);
        }

        // Root directory
        if ($item->file->getPath() . DIRECTORY_SEPARATOR === $this->normalizedSourceDir) {
            $score += self::SCORE_ROOT_DIRECTORY;
            $reasoning[] = sprintf('root=%d', self::SCORE_ROOT_DIRECTORY);
        }

        // Live Photo ID
        if ($item->contentIdentifier !== null) {
            $score += self::SCORE_LIVE_PHOTO_ID;
            $reasoning[] = sprintf('livePhotoId=%d', self::SCORE_LIVE_PHOTO_ID);
        }

        // Tie-break: shorter path wins
        $pathLen  = strlen($item->file->getPathname());
        $tieBreak = max(0, self::SCORE_TIE_BREAK_MAX - (int) ($pathLen / 10));

        if ($tieBreak > 0) {
            $score += $tieBreak;
            $reasoning[] = sprintf('tieBreak=%d', $tieBreak);
        }

        return [$score, $reasoning];
    }

    /**
     * Returns the format priority score for a given normalized extension.
     * Higher preference formats (e.g. HEIC over JPG) receive higher scores.
     * Unknown formats return 0.
     *
     * @param string $extension Normalized file extension without leading dot
     *
     * @return int Priority score (0 if unknown)
     */
    private function computeFormatScore(string $extension): int
    {
        if (!isset($this->formatIndex[$extension])) {
            return 0;
        }

        return ($this->formatCount - $this->formatIndex[$extension]) * self::SCORE_FORMAT_FACTOR;
    }
}
