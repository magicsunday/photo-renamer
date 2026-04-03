<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Helper;

use MagicSunday\Renamer\Model\LinkConfig;

use function array_map;
use function explode;
use function implode;
use function in_array;
use function is_dir;
use function is_string;
use function ltrim;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function realpath;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Provides purely path-oriented transformations for display, command argument
 * normalization, and terminal hyperlink generation.
 *
 * The helper deliberately stays mechanical: it encodes, normalizes, or
 * relativizes paths without carrying media-specific domain decisions.
 */
final class PathHelper
{
    /**
     * Converts an absolute pathname to a display-friendly relative path.
     *
     * @param string      $pathname      Absolute file path to relativize.
     * @param string|null $baseDirectory Base directory used as the display anchor.
     *
     * @return string Relative path when the pathname is inside the base directory, otherwise the original pathname.
     */
    public static function relativizePath(string $pathname, ?string $baseDirectory): string
    {
        if (($baseDirectory === null) || ($baseDirectory === '')) {
            return $pathname;
        }

        $normalizedBase = rtrim($baseDirectory, DIRECTORY_SEPARATOR);

        if ($normalizedBase === '') {
            return $pathname;
        }

        if (!str_starts_with($normalizedBase, DIRECTORY_SEPARATOR)) {
            return $pathname;
        }

        $prefix = $normalizedBase . DIRECTORY_SEPARATOR;

        if (str_starts_with($pathname, $prefix)) {
            return substr($pathname, strlen($prefix));
        }

        return $pathname;
    }

    /**
     * Resolves and validates a directory path from CLI input.
     *
     * @param string|null $directory Raw directory path from CLI input.
     *
     * @return string|null Canonicalized directory path, or null when invalid.
     */
    public static function resolveDirectory(?string $directory): ?string
    {
        if (!is_string($directory)) {
            return null;
        }

        $resolved = realpath($directory);

        if (($resolved === false) || !is_dir($resolved)) {
            return null;
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * Converts a native filesystem path to a `file://` URL suitable for terminal hyperlinks.
     *
     * @param string $nativePath Absolute Unix or Windows path.
     *
     * @return string URL-encoded file URL.
     */
    public static function pathToFileUrl(string $nativePath): string
    {
        $path = str_replace('\\', '/', $nativePath);

        $parts   = explode('/', $path);
        $encoded = implode('/', array_map(rawurlencode(...), $parts));

        if (preg_match('/^[A-Za-z]%3A/', $encoded) === 1) {
            $encoded = $encoded[0] . ':' . substr($encoded, 4);

            return 'file:///' . $encoded;
        }

        return 'file://' . $encoded;
    }

    /**
     * Wraps a display path in a Symfony Console hyperlink tag when link support is enabled.
     *
     * @param string      $displayPath     Text shown to the operator.
     * @param string      $relativePath    Relative path to the file.
     * @param string|null $sourceDirectory Absolute command source directory.
     * @param LinkConfig  $linkConfig      Link configuration from environment variables.
     * @param string|null $color           Optional Symfony Console color name.
     *
     * @return string Display text with optional color and/or href formatting.
     */
    public static function linkifyPath(
        string $displayPath,
        string $relativePath,
        ?string $sourceDirectory,
        LinkConfig $linkConfig,
        ?string $color = null,
    ): string {
        if (!$linkConfig->isEnabled()) {
            return $color !== null
                ? sprintf('<fg=%s>%s</>', $color, $displayPath)
                : $displayPath;
        }

        $normalizedRoot   = rtrim(str_replace('\\', '/', $linkConfig->root ?? ''), '/');
        $normalizedSource = rtrim($sourceDirectory ?? '', '/');
        $offset           = '';

        if (($normalizedSource !== '') && str_starts_with($normalizedSource, $normalizedRoot)) {
            $offset = substr($normalizedSource, strlen($normalizedRoot));
            $offset = ltrim($offset, '/');
        }

        $normalizedBase = rtrim(str_replace('\\', '/', $linkConfig->base ?? ''), '/');
        $fullFilePath   = $normalizedBase . '/' . ($offset !== '' ? $offset . '/' : '') . $relativePath;

        if (!in_array($linkConfig->protocol, [null, '', 'file'], true)) {
            $url = self::pathToFileUrl($fullFilePath);
            $url = preg_replace('/^file/', $linkConfig->protocol ?? '', $url) ?? $url;
        } else {
            $dirPath = implode('/', explode('/', $fullFilePath, -1));
            $url     = self::pathToFileUrl($dirPath . '/');
        }

        if ($color !== null) {
            return sprintf('<fg=%s;href=%s>%s</>', $color, $url, $displayPath);
        }

        return sprintf('<href=%s>%s</>', $url, $displayPath);
    }
}
