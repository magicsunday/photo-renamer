<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Service\Verify;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Renamer\Helper\ConsoleText;
use MagicSunday\Renamer\Helper\FileHelper;
use MagicSunday\Renamer\Helper\FilenameDateParser;
use MagicSunday\Renamer\Service\Verify\VerifyCategoryCatalog;
use MagicSunday\Renamer\Service\Verify\VerifyDetailEntryFormatter;
use MagicSunday\Renamer\Test\Fixtures\WorkspaceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function str_contains;
use function substr_count;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Verifies the formatter used for verify detail-mode output.
 *
 * The formatter must preserve the existing operator guidance: problem
 * explanation, metadata echoing, filename-based recovery hints, and the
 * corresponding write-date fix command for supported categories.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
#[CoversClass(VerifyDetailEntryFormatter::class)]
#[UsesClass(VerifyCategoryCatalog::class)]
#[UsesClass(FileHelper::class)]
#[UsesClass(FilenameDateParser::class)]
#[UsesClass(ConsoleText::class)]
final class VerifyDetailEntryFormatterTest extends TestCase
{
    use WorkspaceTrait;

    /**
     * Verifies that timezone findings include the QuickTime problem description,
     * existing metadata, and a write-date fix command with the configured timezone.
     */
    #[Test]
    public function formatBuildsTimezoneDetailEntry(): void
    {
        $workspace = $this->createTempWorkspace('verifydetail_');
        $movPath   = $workspace . DIRECTORY_SEPARATOR . 'clip.mov';
        file_put_contents($movPath, 'video');

        try {
            $formatter = new VerifyDetailEntryFormatter();

            $entry = $formatter->format(
                'clip.mov',
                $movPath,
                VerifyCategoryCatalog::TIMEZONE,
                new DateTimeImmutable('2025-04-03 16:50:50', new DateTimeZone('UTC')),
                new DateTimeZone('Europe/Berlin'),
            );

            self::assertStringContainsString('Ambiguous timezone', $entry);
            self::assertStringContainsString('CreateDate (UTC)', $entry);
            self::assertStringContainsString('--reason=timezone', $entry);
            self::assertStringContainsString('--timezone=Europe/Berlin', $entry);
        } finally {
            @unlink($movPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Verifies that nodata findings without a filename-derived timestamp tell the
     * user to rename the file first instead of suggesting an immediate write.
     */
    #[Test]
    public function formatExplainsWhenNoFilenameRecoveryExists(): void
    {
        $workspace = $this->createTempWorkspace('verifydetail_');
        $jpgPath   = $workspace . DIRECTORY_SEPARATOR . 'scan.jpg';
        file_put_contents($jpgPath, 'photo');

        try {
            $formatter = new VerifyDetailEntryFormatter();

            $entry = $formatter->format(
                'scan.jpg',
                $jpgPath,
                VerifyCategoryCatalog::NODATA,
                null,
            );

            self::assertStringContainsString('No capture date found', $entry);
            self::assertStringContainsString('no date in filename — rename file first', $entry);
            self::assertStringContainsString('Rename to date-based name, then: rename:write-date --reason=nodata', $entry);
        } finally {
            @unlink($jpgPath);
            $this->removeWorkspace($workspace);
        }
    }

    /**
     * Detail filenames and the quoted write-date fix suggestion must display
     * literal tags and visible controls. Generated explanation lines remain
     * formatted while raw paths used for date recovery stay unchanged.
     *
     * @return void
     */
    #[Test]
    public function filenamesAndFixSuggestionsCannotInjectTerminalRows(): void
    {
        $workspace = $this->createTempWorkspace('verify-safe-');
        $filename  = "2024-01-15-<info>FORGED\x1B[2J\r\n.jpg";
        $path      = $workspace . '/' . $filename;

        try {
            new Filesystem()->dumpFile($path, 'synthetic');
            $entry    = new VerifyDetailEntryFormatter()->format($filename, $path, VerifyCategoryCatalog::NODATA, null);
            $captured = new OutputFormatter()->format($entry);
            self::assertNotNull($captured);
            self::assertSame(2, substr_count($captured, '<info>FORGED\\u001b[2J\\r\\n.jpg'));
            self::assertTrue(str_contains($captured, 'rename:write-date --reason=nodata'));
            self::assertTrue(str_contains($captured, 'date from filename: 2024-01-15'));
            self::assertSame(4, substr_count($captured, "\n"));
            self::assertFalse(str_contains($captured, "\x1B"));
            self::assertFalse(str_contains($captured, "\r"));
        } finally {
            $this->removeWorkspace($workspace);
        }
    }
}
