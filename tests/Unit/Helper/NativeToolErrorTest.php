<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Test\Unit\Helper;

use MagicSunday\Renamer\Helper\NativeToolError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

/**
 * Protects the diagnostic text boundary independently from process behavior:
 * useful failure context remains, credential values are removed and huge native
 * stderr does not create unbounded retained or rendered messages.
 */
#[CoversClass(NativeToolError::class)]
final class NativeToolErrorTest extends TestCase
{
    /**
     * Named credentials are removed from details before presentation, while a
     * non-secret timezone value and the trusted exit context remain readable.
     * Environment keys are never appended or serialized into the diagnostic.
     *
     * @return void
     */
    #[Test]
    public function credentialValuesAreRedactedWithoutDumpingEnvironment(): void
    {
        $text = NativeToolError::describe('ffmpeg', 'exited with code 47', 'decoder synthetic-token synthetic-password Europe/Berlin', [
            'GITHUB_TOKEN' => 'synthetic-token',
            'DB_PASSWORD'  => 'synthetic-password',
            'TIMEZONE'     => 'Europe/Berlin',
        ]);

        self::assertSame('ffmpeg exited with code 47: decoder [redacted] [redacted] Europe/Berlin', $text);
        self::assertStringNotContainsString('GITHUB_TOKEN', $text);
        self::assertStringNotContainsString('DB_PASSWORD', $text);
    }

    /**
     * Overlapping credential values are replaced simultaneously using the
     * longest match, so a shorter token cannot expose a password suffix.
     *
     * @return void
     */
    #[Test]
    public function overlappingCredentialValuesDoNotExposeSuffixes(): void
    {
        self::assertSame('ffmpeg failed: [redacted] [redacted]', NativeToolError::describe('ffmpeg', 'failed', 'synthetic-token-password synthetic-token', [
            'TOKEN'    => 'synthetic-token',
            'PASSWORD' => 'synthetic-token-password',
        ]));
    }

    /**
     * Redact a long credential before clipping stderr; otherwise the clipped
     * prefix of the secret could survive even though full-value replacement runs.
     *
     * @return void
     */
    #[Test]
    public function redactionPrecedesBoundedExcerpt(): void
    {
        $secret = str_repeat('synthetic-secret-', 100);
        $text   = NativeToolError::describe('ffprobe', 'failed', $secret . str_repeat('x', 2000), ['API_KEY' => $secret]);

        self::assertStringNotContainsString('synthetic-secret-', $text);
        self::assertStringContainsString('[redacted]', $text);
        self::assertSame(strlen('ffprobe failed: ') + 1024, strlen($text));
    }

    /**
     * An empty native stderr still produces the trustworthy capability and
     * timeout reason without an empty suffix or implicit environment expansion.
     *
     * @return void
     */
    #[Test]
    public function absentStderrRetainsFailureContext(): void
    {
        self::assertSame('ffmpeg timed out after 20 seconds', NativeToolError::describe('ffmpeg', 'timed out after 20 seconds', '', []));
    }
}
