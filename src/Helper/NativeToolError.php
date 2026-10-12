<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Helper;

use function preg_match;
use function strtr;
use function substr;
use function trim;

/**
 * Formats bounded native-media diagnostics while removing named credential
 * values. It shares the ffmpeg/ffprobe text contract without owning processes,
 * reporting policy or environment lookup; Console escaping remains downstream.
 */
final readonly class NativeToolError
{
    /**
     * Preserves useful exit/timeout context and a bounded stderr excerpt.
     * Environment entries are never serialized. Values of credential-like names
     * are removed before truncation so a clipped secret cannot remain visible.
     *
     * @param string                $tool        Fixed native capability name
     * @param string                $failure     Trusted exit, timeout or startup description
     * @param string                $details     Untrusted stderr or exception detail
     * @param array<string, string> $environment Current environment supplied explicitly for redaction
     *
     * @return string Plain diagnostic text, with no trusted Console markup
     */
    public static function describe(string $tool, string $failure, string $details, array $environment): string
    {
        $replacements = [];

        foreach ($environment as $name => $value) {
            if (($value !== '') && (preg_match('/TOKEN|SECRET|PASSWORD|AUTH|CREDENTIAL|PRIVATE|API_KEY/i', $name) === 1)) {
                $replacements[$value] = '[redacted]';
            }
        }

        // Longest matches win and replacement text is never processed again.
        $details = trim(substr(strtr($details, $replacements), 0, 1024));

        return $tool . ' ' . $failure . ($details === '' ? '' : ': ' . $details);
    }
}
