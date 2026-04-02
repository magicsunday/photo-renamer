<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Dedup;

use MagicSunday\Renamer\Constants;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Service\FormatPriorityResolver;
use MagicSunday\Renamer\Service\MediaTypeClassifierInterface;
use SplFileInfo;

use function str_contains;
use function strcmp;
use function strlen;
use function strtolower;
use function substr_count;

use const DIRECTORY_SEPARATOR;

/**
 * Matches duplicate-suffixed files to actionable originals for `rename:dedup`.
 *
 * The matcher intentionally stays cheaper and simpler than the EXIF pipeline:
 * it relies on filename structure, extension normalization, media-family
 * compatibility, and configured format priority. This keeps the cleanup command
 * deterministic while avoiding a second full classification pipeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final readonly class DedupOriginalMatcher
{
    /**
     * Normalized extension rank map derived from canonical format priority.
     *
     * Lower numbers are preferred.
     *
     * @var array<string, int>
     */
    private array $formatPriorityRanks;

    /**
     * @param MediaTypeClassifierInterface $mediaTypeClassifier Classifies still and video asset families
     */
    public function __construct(
        private MediaTypeClassifierInterface $mediaTypeClassifier,
    ) {
        $this->formatPriorityRanks = $this->buildFormatPriorityRanks();
    }

    /**
     * Builds an index of non-duplicate candidate originals from the scanned files.
     *
     * Only files without the duplicate marker are indexed. Matching and ranking
     * decisions are deferred to {@see match()}.
     *
     * @param list<SplFileInfo> $files Files collected from the dedup source tree
     *
     * @return OriginalCandidateIndex Lookup structure keyed by clean basename
     */
    public function createIndex(array $files): OriginalCandidateIndex
    {
        /** @var array<string, list<SplFileInfo>> $candidatesByBasename */
        $candidatesByBasename = [];

        foreach ($files as $file) {
            $basename = FileHelper::basenameWithoutExtension($file);

            if (str_contains($basename, Constants::DUPLICATE_IDENTIFIER)) {
                continue;
            }

            $candidatesByBasename[$basename] ??= [];
            $candidatesByBasename[$basename][] = $file;
        }

        return new OriginalCandidateIndex($candidatesByBasename);
    }

    /**
     * Resolves the best original candidate for a duplicate-suffixed file.
     *
     * The matcher first looks up candidates by clean basename. It then applies a
     * conservative compatibility policy:
     * - exact normalized extension always matches
     * - still images may cross-match between still formats (`jpg`, `heic`, `heif`)
     * - videos require the same normalized extension
     *
     * Among multiple compatible still candidates, the configured canonical format
     * priority wins, then shallower paths, then shorter pathnames.
     *
     * @param SplFileInfo            $duplicateFile Duplicate-suffixed file to resolve
     * @param OriginalCandidateIndex $index         Indexed original candidates
     *
     * @return SplFileInfo|null The best matching original, or null when none exists
     */
    public function match(SplFileInfo $duplicateFile, OriginalCandidateIndex $index): ?SplFileInfo
    {
        $duplicateBasename = FileHelper::basenameWithoutExtension($duplicateFile);
        $originalBasename  = FileHelper::stripDuplicateSuffix($duplicateBasename);
        $candidates        = $index->getCandidatesForBasename($originalBasename);
        $bestCandidate     = null;

        foreach ($candidates as $candidate) {
            if (!$this->isCompatibleCandidate($duplicateFile, $candidate)) {
                continue;
            }

            if (($bestCandidate === null) || ($this->compareCandidates($duplicateFile, $candidate, $bestCandidate) < 0)) {
                $bestCandidate = $candidate;
            }
        }

        return $bestCandidate;
    }

    /**
     * Compares two compatible candidates for the same duplicate file.
     *
     * Lower comparison values indicate that `$candidateA` is preferred.
     *
     * @param SplFileInfo $duplicateFile Duplicate file being resolved
     * @param SplFileInfo $candidateA    First compatible original candidate
     * @param SplFileInfo $candidateB    Second compatible original candidate
     *
     * @return int Negative when A is preferred, positive when B is preferred, 0 when equal
     */
    private function compareCandidates(SplFileInfo $duplicateFile, SplFileInfo $candidateA, SplFileInfo $candidateB): int
    {
        $duplicateExtension = $this->normalizeExtension($duplicateFile);
        $aExactMatch        = $this->normalizeExtension($candidateA) === $duplicateExtension;
        $bExactMatch        = $this->normalizeExtension($candidateB) === $duplicateExtension;

        if ($aExactMatch !== $bExactMatch) {
            return $aExactMatch ? -1 : 1;
        }

        $rankComparison = $this->compareFormatPriority($candidateA, $candidateB);

        if ($rankComparison !== 0) {
            return $rankComparison;
        }

        $depthComparison = substr_count($candidateA->getPathname(), DIRECTORY_SEPARATOR)
            <=> substr_count($candidateB->getPathname(), DIRECTORY_SEPARATOR);

        if ($depthComparison !== 0) {
            return $depthComparison;
        }

        $lengthComparison = strlen($candidateA->getPathname()) <=> strlen($candidateB->getPathname());

        if ($lengthComparison !== 0) {
            return $lengthComparison;
        }

        return strcmp($candidateA->getPathname(), $candidateB->getPathname());
    }

    /**
     * Returns whether the given candidate may serve as an original for the duplicate.
     *
     * @param SplFileInfo $duplicateFile Duplicate-suffixed file under evaluation
     * @param SplFileInfo $candidate     Indexed non-duplicate file with the same clean basename
     *
     * @return bool True when the candidate is compatible with the duplicate
     */
    private function isCompatibleCandidate(SplFileInfo $duplicateFile, SplFileInfo $candidate): bool
    {
        $duplicateExtension = $this->normalizeExtension($duplicateFile);
        $candidateExtension = $this->normalizeExtension($candidate);

        if ($candidateExtension === $duplicateExtension) {
            return true;
        }

        return $this->mediaTypeClassifier->isLivePhotoStill($duplicateFile)
            && $this->mediaTypeClassifier->isLivePhotoStill($candidate);
    }

    /**
     * Compares candidates by configured format priority.
     *
     * Unknown extensions sort after known ones while still remaining stable and deterministic.
     *
     * @param SplFileInfo $candidateA First candidate
     * @param SplFileInfo $candidateB Second candidate
     *
     * @return int Negative when A has higher priority, positive when B has higher priority, 0 when equal
     */
    private function compareFormatPriority(SplFileInfo $candidateA, SplFileInfo $candidateB): int
    {
        $rankA = $this->formatPriorityRanks[$this->normalizeExtension($candidateA)] ?? PHP_INT_MAX;
        $rankB = $this->formatPriorityRanks[$this->normalizeExtension($candidateB)] ?? PHP_INT_MAX;

        return $rankA <=> $rankB;
    }

    /**
     * Builds the normalized extension rank map from configured format priority.
     *
     * Duplicate normalized extensions collapse to the earliest configured rank so
     * aliases like `jpeg` and `jpg` remain equivalent for matching purposes.
     *
     * @return array<string, int> Normalized extension to rank map
     */
    private function buildFormatPriorityRanks(): array
    {
        /** @var array<string, int> $ranks */
        $ranks = [];

        foreach (FormatPriorityResolver::resolve() as $index => $extension) {
            $normalizedExtension = FileHelper::normalizeExtension(strtolower($extension));

            if (!isset($ranks[$normalizedExtension])) {
                $ranks[$normalizedExtension] = $index;
            }
        }

        return $ranks;
    }

    /**
     * Returns the normalized lowercase extension for a file.
     *
     * @param SplFileInfo $file File whose extension should be normalized
     *
     * @return string Normalized extension without leading dot
     */
    private function normalizeExtension(SplFileInfo $file): string
    {
        return FileHelper::normalizeExtension($file->getExtension());
    }
}
