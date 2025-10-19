<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy;

use DateTime;
use Exception;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifData;
use Override;
use SplFileInfo;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;
use function rtrim;
use function strlen;
use function stripos;
use function strtolower;
use function substr;
use function unpack;

/**
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-renamer/
 */
class ExifDateFilenameStrategy implements RenameStrategyInterface
{
    /**
     * @var string
     */
    private readonly string $targetFilenamePattern;

    /**
     * Cached EXIF data per file path.
     *
     * @var array<string, ExifData|null>
     */
    private array $exifDataCache = [];

    /**
     * Cached Live Photo content identifier per file path.
     *
     * @var array<string, string|null>
     */
    private array $contentIdentifierCache = [];

    /**
     * Constructor.
     *
     * @param string $targetFilenamePattern
     */
    public function __construct(string $targetFilenamePattern)
    {
        $this->targetFilenamePattern = $targetFilenamePattern;
    }

    #[Override]
    public function generateFilename(SplFileInfo $splFileInfo): ?string
    {
        // Create a new filename based on the formatted value of the EXIF field "DateTimeOriginal".
        $targetBasename = $this->getExifDateFormatted($this->targetFilenamePattern, $splFileInfo);

        if ($targetBasename === null) {
            return null;
        }

        return $targetBasename . '.' . $splFileInfo->getExtension();
    }

    public function getLivePhotoContentIdentifier(SplFileInfo $splFileInfo): ?string
    {
        $this->getExifData($splFileInfo);

        return $this->contentIdentifierCache[$splFileInfo->getPathname()] ?? null;
    }

    /**
     * Returns the formatted EXIF date of the specified file, formatted according to the specified pattern.
     *
     * @param string      $pattern
     * @param SplFileInfo $splFileInfo
     *
     * @return string|null
     */
    private function getExifDateFormatted(
        string $pattern,
        SplFileInfo $splFileInfo,
    ): ?string {
        // Look up EXIF data
        $exifData = $this->getExifData($splFileInfo);

        if ($exifData === null) {
            return null;
        }

        $exifDateTimeOriginal  = $exifData->getDateTimeOriginal();
        $exifSubSecTimeOriginal = $exifData->getSubSecTimeOriginal() ?? '';

        try {
            $dateTimeOriginal = new DateTime($exifDateTimeOriginal);

            if ($exifSubSecTimeOriginal !== '') {
                if (strlen($exifSubSecTimeOriginal) > 4) {
                    $dateTimeOriginal->modify('+' . $exifSubSecTimeOriginal . ' Microseconds');
                } else {
                    $dateTimeOriginal->modify('+' . $exifSubSecTimeOriginal . ' Milliseconds');
                }
            }
        } catch (Exception) {
            // $this->io->warning('=> Invalid EXIF date format in "DateTimeOriginal".');

            return null;
        }

        return $dateTimeOriginal->format($pattern);
    }

    /**
     * Retrieves EXIF data from the specified file.
     *
     * @param SplFileInfo $splFileInfo The file information object representing the target file
     *
     * @return ExifData|null Typed EXIF data or null when no usable information is available
     */
    private function getExifData(SplFileInfo $splFileInfo): ?ExifData
    {
        $pathname = $splFileInfo->getPathname();

        if (!array_key_exists($pathname, $this->exifDataCache)) {
            $this->exifDataCache[$pathname] = $this->createExifData($splFileInfo);
        }

        return $this->exifDataCache[$pathname];
    }

    private function createExifData(SplFileInfo $splFileInfo): ?ExifData
    {
        $pathname = $splFileInfo->getPathname();
        $rawExifData = @exif_read_data($pathname);

        $contentIdentifier = null;

        if (is_array($rawExifData)) {
            $contentIdentifier = $this->extractContentIdentifierFromArray($rawExifData);
        }

        if ($contentIdentifier === null) {
            $contentIdentifier = $this->extractContentIdentifierFromQuickTimeIfApplicable($splFileInfo);
        }

        $this->contentIdentifierCache[$pathname] = $contentIdentifier;

        if (!is_array($rawExifData) || !isset($rawExifData['DateTimeOriginal'])) {
            return null;
        }

        $dateTimeOriginal = $rawExifData['DateTimeOriginal'];
        $subSecTimeOriginal = $rawExifData['SubSecTimeOriginal'] ?? null;

        if (!is_string($dateTimeOriginal) || $dateTimeOriginal === '') {
            return null;
        }

        if (!is_string($subSecTimeOriginal) || $subSecTimeOriginal === '') {
            $subSecTimeOriginal = null;
        }

        return new ExifData($dateTimeOriginal, $subSecTimeOriginal, $contentIdentifier);
    }

    private function extractContentIdentifierFromArray(array $exifData): ?string
    {
        foreach ($exifData as $key => $value) {
            if (is_string($key) && stripos($key, 'content') !== false && stripos($key, 'identifier') !== false) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            if (is_array($value)) {
                $nested = $this->extractContentIdentifierFromArray($value);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function extractContentIdentifierFromQuickTimeIfApplicable(SplFileInfo $splFileInfo): ?string
    {
        if (!$this->isQuickTimeFile($splFileInfo)) {
            return null;
        }

        return $this->extractContentIdentifierFromQuickTime($splFileInfo);
    }

    private function isQuickTimeFile(SplFileInfo $splFileInfo): bool
    {
        $extension = strtolower($splFileInfo->getExtension());

        return in_array($extension, ['mov', 'mp4', 'm4v', 'qt'], true);
    }

    private function extractContentIdentifierFromQuickTime(SplFileInfo $splFileInfo): ?string
    {
        $data = @file_get_contents($splFileInfo->getPathname());

        if ($data === false) {
            return null;
        }

        $moov = $this->findAtom($data, 'moov');

        if ($moov === null) {
            return null;
        }

        $udta = $this->findAtom($moov, 'udta');

        if ($udta === null) {
            return null;
        }

        $meta = $this->findAtom($udta, 'meta');

        if ($meta === null || strlen($meta) < 4) {
            return null;
        }

        $metaPayload = substr($meta, 4); // Skip version and flags

        $keys = $this->findAtom($metaPayload, 'keys');
        $ilst = $this->findAtom($metaPayload, 'ilst');

        if ($keys === null || $ilst === null) {
            return null;
        }

        $keyMap    = $this->parseQuickTimeKeysAtom($keys);
        $valueMap  = $this->parseQuickTimeIlstAtom($ilst);

        foreach ($keyMap as $index => $key) {
            if (stripos($key, 'content.identifier') !== false) {
                $value = $valueMap[$index] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function findAtom(string $data, string $type): ?string
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset + 8 <= $length) {
            $size = $this->unpackUInt32(substr($data, $offset, 4));
            $atomType = substr($data, $offset + 4, 4);

            $headerSize = 8;

            if ($size === 1) {
                if ($offset + 16 > $length) {
                    return null;
                }

                $size = $this->unpackUInt64(substr($data, $offset + 8, 8));
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $length - $offset;
            }

            if ($size < $headerSize || $offset + $size > $length) {
                return null;
            }

            $payloadOffset = $offset + $headerSize;
            $payloadSize   = $size - $headerSize;

            if ($atomType === $type) {
                return substr($data, $payloadOffset, $payloadSize);
            }

            $offset += $size;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function parseQuickTimeKeysAtom(string $data): array
    {
        $length = strlen($data);

        if ($length < 8) {
            return [];
        }

        $offset = 0;

        $offset += 4; // version and flags

        if ($offset + 4 > $length) {
            return [];
        }

        $entryCount = $this->unpackUInt32(substr($data, $offset, 4));
        $offset += 4;

        $keys = [];

        for ($index = 1; $index <= $entryCount; ++$index) {
            if ($offset + 8 > $length) {
                break;
            }

            $size = $this->unpackUInt32(substr($data, $offset, 4));

            if ($size === 0 || $offset + $size > $length) {
                break;
            }

            if ($size < 8) {
                break;
            }

            $key = substr($data, $offset + 8, $size - 8);
            $keys[$index] = rtrim($key, "\0");

            $offset += $size;
        }

        return $keys;
    }

    /**
     * @return array<int, string>
     */
    private function parseQuickTimeIlstAtom(string $data): array
    {
        $length = strlen($data);
        $offset = 0;
        $values = [];

        while ($offset + 8 <= $length) {
            $size = $this->unpackUInt32(substr($data, $offset, 4));

            if ($size === 0) {
                $size = $length - $offset;
            }

            if ($size < 8 || $offset + $size > $length) {
                break;
            }

            $index = $this->unpackUInt32(substr($data, $offset + 4, 4));
            $itemData = substr($data, $offset + 8, $size - 8);

            $value = $this->parseQuickTimeMetadataItem($itemData);

            if ($value !== null) {
                $values[$index] = $value;
            }

            $offset += $size;
        }

        return $values;
    }

    private function parseQuickTimeMetadataItem(string $data): ?string
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset + 8 <= $length) {
            $size = $this->unpackUInt32(substr($data, $offset, 4));

            if ($size === 0) {
                $size = $length - $offset;
            }

            if ($size < 8 || $offset + $size > $length) {
                break;
            }

            $type = substr($data, $offset + 4, 4);

            if ($type === 'data') {
                if ($size <= 16) {
                    return '';
                }

                $payload = substr($data, $offset + 16, $size - 16);

                return rtrim($payload, "\0");
            }

            $offset += $size;
        }

        return null;
    }

    private function unpackUInt32(string $bytes): int
    {
        return unpack('N', $bytes)[1];
    }

    private function unpackUInt64(string $bytes): int
    {
        $parts = unpack('N2', $bytes);

        return ($parts[1] << 32) | $parts[2];
    }
}
