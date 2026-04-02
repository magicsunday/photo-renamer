<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Service\Verify;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MagicSunday\Renamer\Helper\FileHelper;

use function escapeshellarg;
use function filesize;
use function implode;
use function sprintf;

/**
 * Formats detail-mode verify entries with problem explanation and fix guidance.
 *
 * Verify's detail mode is presentation logic, not scan logic. This formatter
 * keeps the user-facing wording, recovery hints, and suggested write-date
 * commands in one place so the command and scanner can stay focused on control
 * flow and classification.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
final class VerifyDetailEntryFormatter
{
    /**
     * Formats a detail entry for one verify finding.
     *
     * The formatter explains the detected problem, shows the currently available
     * metadata, tries to derive a recovery timestamp from the filename, and
     * suggests the corresponding `rename:write-date` invocation when possible.
     *
     * @param string                 $relativePath       Path shown to the user
     * @param string                 $absolutePath       Absolute file path used for size and filename-date lookup
     * @param string                 $category           Verify category key describing the problem
     * @param DateTimeInterface|null $captureDateTime    Effective capture date currently stored in metadata
     * @param DateTimeZone|null      $configuredTimezone Configured timezone used in fix suggestions for QuickTime files
     *
     * @return string Multi-line detail entry ready for console output
     */
    public function format(
        string $relativePath,
        string $absolutePath,
        string $category,
        ?DateTimeInterface $captureDateTime,
        ?DateTimeZone $configuredTimezone = null,
    ): string {
        $fileSize    = filesize($absolutePath);
        $sizeLabel   = ($fileSize !== false) ? FileHelper::formatSize($fileSize) : '?';
        $lines       = [sprintf('%s <fg=gray>(%s)</>', $relativePath, $sizeLabel)];
        $escapedPath = escapeshellarg($absolutePath);

        $tzFlag = ($configuredTimezone instanceof DateTimeZone)
            ? '--timezone=' . $configuredTimezone->getName()
            : '--timezone=<TZ>';

        // Problem description per category
        $problem = match ($category) {
            VerifyCategoryCatalog::TIMEZONE => '     <fg=yellow>Problem:</>    Ambiguous timezone — QuickTime UTC without offset',
            VerifyCategoryCatalog::FALLBACK => '     <fg=yellow>Problem:</>    Only ModifyDate (0x0132) — no DateTimeOriginal or CreateDate',
            VerifyCategoryCatalog::NODATA   => '     <fg=yellow>Problem:</>    No capture date found (no DateTimeOriginal, CreateDate, or ModifyDate)',
            default                         => null,
        };

        if ($problem !== null) {
            $lines[] = $problem;
        }

        // Show what metadata IS present
        if ($captureDateTime instanceof DateTimeInterface) {
            $label   = ($category === VerifyCategoryCatalog::TIMEZONE) ? 'CreateDate (UTC)' : 'ModifyDate';
            $lines[] = sprintf('     <fg=gray>Metadata:</>   %s = %s', $label, $captureDateTime->format('Y:m:d H:i:s'));
        } else {
            $lines[] = '     <fg=gray>Metadata:</>   (none)';
        }

        // Check if filename contains a date that write-date could use
        $filenameDateTime = FileHelper::extractDateTimeFromPath($absolutePath);

        if ($filenameDateTime instanceof DateTimeImmutable) {
            $lines[] = sprintf('     <fg=gray>Recovery:</>   date from filename: %s', $filenameDateTime->format('Y-m-d H:i:s'));
        } elseif ($category === VerifyCategoryCatalog::NODATA) {
            $lines[] = '     <fg=gray>Recovery:</>   no date in filename — rename file first';
        }

        $suggestion = match ($category) {
            VerifyCategoryCatalog::TIMEZONE => sprintf(
                '     <fg=green>Fix:</>        rename:write-date --reason=timezone %s %s',
                $tzFlag,
                $escapedPath,
            ),
            VerifyCategoryCatalog::FALLBACK => sprintf(
                '     <fg=green>Fix:</>        rename:write-date --reason=fallback %s',
                $escapedPath,
            ),
            VerifyCategoryCatalog::NODATA => ($filenameDateTime instanceof DateTimeImmutable)
                ? sprintf('     <fg=green>Fix:</>        rename:write-date --reason=nodata %s', $escapedPath)
                : sprintf('     <fg=green>Fix:</>        Rename to date-based name, then: rename:write-date --reason=nodata %s', $escapedPath),
            default => null,
        };

        if ($suggestion !== null) {
            $lines[] = $suggestion;
        }

        return implode("\n", $lines);
    }
}
