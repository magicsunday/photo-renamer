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
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Exception\TargetFilenameException;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ContentIdentifier;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifData;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ExifRawMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\MetadataEntryCollection;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\QuickTimeKey;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\QuickTimeMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\QuickTimeValue;
use MagicSunday\Renamer\Service\SafeExifReader;
use MagicSunday\Renamer\Service\SafeFileReader;
use Override;
use SplFileInfo;
use SplObjectStorage;

use function in_array;
use function is_array;
use function is_int;
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
     * @var SplObjectStorage<SplFileInfo, ExifData|null>
     */
    private SplObjectStorage $exifDataCache;

    /**
     * Cached Live Photo content identifier per file path.
     *
     * @var SplObjectStorage<SplFileInfo, ContentIdentifier|null>
     */
    private SplObjectStorage $contentIdentifierCache;

    public function __construct(
        string $targetFilenamePattern,
        private readonly SafeExifReader $safeExifReader,
        private readonly SafeFileReader $safeFileReader,
    ) {
        $this->targetFilenamePattern = $targetFilenamePattern;
        $this->exifDataCache = new SplObjectStorage();
        $this->contentIdentifierCache = new SplObjectStorage();
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

        if (!$this->contentIdentifierCache->offsetExists($splFileInfo)) {
            return null;
        }

        $identifier = $this->contentIdentifierCache[$splFileInfo];

        return $identifier?->getValue();
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
        if (!$this->exifDataCache->offsetExists($splFileInfo)) {
            try {
                $this->exifDataCache[$splFileInfo] = $this->createExifData($splFileInfo);
            } catch (ExifMetadataReadException $exception) {
                throw new TargetFilenameException($exception->getMessage(), previous: $exception);
            }
        }

        $exifData = $this->exifDataCache[$splFileInfo];

        return $exifData instanceof ExifData ? $exifData : null;
    }

    /**
     * Builds a typed EXIF data object for the given file and caches the Live Photo identifier when present.
     *
     * @param SplFileInfo $splFileInfo File to inspect
     *
     * @return ExifData|null Structured EXIF data or null when the file lacks the required tags
     */
    private function createExifData(SplFileInfo $splFileInfo): ?ExifData
    {
        $rawExifData = $this->safeExifReader->read($splFileInfo);

        $contentIdentifier = null;
        $metadata = null;

        if (is_array($rawExifData)) {
            $metadata = ExifRawMetadata::fromArray($rawExifData);
            $metadataEntries = MetadataEntryCollection::fromArray($metadata);
            $contentIdentifier = $this->extractContentIdentifierFromMetadata($metadataEntries);
        }

        if ($contentIdentifier === null) {
            $contentIdentifier = $this->extractContentIdentifierFromQuickTimeIfApplicable($splFileInfo);
        }

        $this->contentIdentifierCache[$splFileInfo] = $contentIdentifier;

        if ($metadata === null) {
            return null;
        }

        $dateTimeOriginal = $metadata->getString('DateTimeOriginal');

        if ($dateTimeOriginal === null || $dateTimeOriginal === '') {
            return null;
        }

        $subSecTimeOriginal = $metadata->getString('SubSecTimeOriginal');

        if ($subSecTimeOriginal === null || $subSecTimeOriginal === '') {
            $subSecTimeOriginal = null;
        }

        return new ExifData($dateTimeOriginal, $subSecTimeOriginal, $contentIdentifier);
    }

    /**
     * Extracts a Live Photo content identifier from metadata entries.
     */
    private function extractContentIdentifierFromMetadata(MetadataEntryCollection $entries): ?ContentIdentifier
    {
        $match = $entries->findContentIdentifier();

        if ($match === null) {
            return null;
        }

        return new ContentIdentifier($match->getValue());
    }

    /**
     * Extracts a Live Photo content identifier from QuickTime containers when EXIF metadata did not provide one.
     *
     * @param SplFileInfo $splFileInfo Potential QuickTime container to inspect
     *
     * @return ContentIdentifier|null The embedded content identifier or null when the file is not supported or no identifier was found
     */
    private function extractContentIdentifierFromQuickTimeIfApplicable(SplFileInfo $splFileInfo): ?ContentIdentifier
    {
        if (!$this->isQuickTimeFile($splFileInfo)) {
            return null;
        }

        return $this->extractContentIdentifierFromQuickTime($splFileInfo);
    }

    /**
     * Checks whether the given file uses a QuickTime-based container format.
     *
     * @param SplFileInfo $splFileInfo File to validate
     *
     * @return bool True when the extension matches a known QuickTime container
     */
    private function isQuickTimeFile(SplFileInfo $splFileInfo): bool
    {
        $extension = strtolower($splFileInfo->getExtension());

        return in_array($extension, ['mov', 'mp4', 'm4v', 'qt'], true);
    }

    /**
     * Reads the QuickTime file structure to locate the Live Photo content identifier stored in metadata atoms.
     *
     * The method traverses the container hierarchy (moov → udta → meta) before pairing `keys` entries with the
     * corresponding `ilst` values to discover the `content.identifier` string.
     *
     * @param SplFileInfo $splFileInfo QuickTime container to parse
     *
     * @return ContentIdentifier|null Extracted identifier or null when parsing fails
     */
    private function extractContentIdentifierFromQuickTime(SplFileInfo $splFileInfo): ?ContentIdentifier
    {
        try {
            $data = $this->safeFileReader->read($splFileInfo);
        } catch (Exception $exception) {
            throw new ExifMetadataReadException(
                'Unable to read QuickTime metadata: ' . $exception->getMessage(),
                previous: $exception,
            );
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

        $metadata = $this->parseQuickTimeKeysAtom($keys);
        $metadata = $this->parseQuickTimeIlstAtom($ilst, $metadata);

        $identifier = $metadata->findValueByKeyFragment('content.identifier');

        if ($identifier === null || $identifier->getValue() === '') {
            return null;
        }

        return new ContentIdentifier($identifier->getValue());
    }

    /**
     * Searches the provided byte sequence for the specified QuickTime atom and returns its payload.
     *
     * @param string $data Binary data to scan
     * @param string $type Four-character atom type to locate
     *
     * @return string|null Atom payload without the header or null when the atom is missing or truncated
     */
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
     * Parses the `keys` atom and builds an index-based map of metadata key names.
     *
     * @param string $data Raw payload of the `keys` atom
     *
     * @return QuickTimeMetadata Map of atom index to UTF-8 key name encapsulated in metadata value objects
     */
    private function parseQuickTimeKeysAtom(string $data): QuickTimeMetadata
    {
        $length = strlen($data);

        if ($length < 8) {
            return QuickTimeMetadata::empty();
        }

        $offset = 0;

        $offset += 4; // version and flags

        $entryCount = $this->unpackUInt32(substr($data, $offset, 4));
        $offset += 4;

        $metadata = QuickTimeMetadata::empty();

        for ($index = 1; $index <= $entryCount; ++$index) {
            $size = $this->unpackUInt32(substr($data, $offset, 4));

            if ($size === 0 || $offset + $size > $length) {
                break;
            }

            if ($size < 8) {
                break;
            }

            $key = substr($data, $offset + 8, $size - 8);
            $metadata = $metadata->withKey(new QuickTimeKey($index, rtrim($key, "\0")));

            $offset += $size;
        }

        return $metadata;
    }

    /**
     * Parses the `ilst` atom and returns the decoded metadata values keyed by their index.
     *
     * @param string $data Raw payload of the `ilst` atom
     *
     * @return QuickTimeMetadata Metadata augmented with parsed values
     */
    private function parseQuickTimeIlstAtom(string $data, QuickTimeMetadata $metadata): QuickTimeMetadata
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

            $index = $this->unpackUInt32(substr($data, $offset + 4, 4));
            $itemData = substr($data, $offset + 8, $size - 8);

            $value = $this->parseQuickTimeMetadataItem($itemData, $index);

            if ($value !== null) {
                $metadata = $metadata->withValue($value);
            }

            $offset += $size;
        }

        return $metadata;
    }

    /**
     * Extracts the string payload from a QuickTime metadata item within an `ilst` entry.
     *
     * @param string $data  Binary representation of the metadata item
     * @param int    $index Index of the metadata entry
     *
     * @return QuickTimeValue|null Decoded string value or null when the item cannot be parsed
     */
    private function parseQuickTimeMetadataItem(string $data, int $index): ?QuickTimeValue
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
                    return new QuickTimeValue($index, '');
                }

                $payload = substr($data, $offset + 16, $size - 16);

                return new QuickTimeValue($index, rtrim($payload, "\0"));
            }

            $offset += $size;
        }

        return null;
    }

    /**
     * Unpacks a big-endian unsigned 32-bit integer from binary data.
     *
     * @param string $bytes Big-endian binary representation (4 bytes)
     *
     * @return int Unsigned integer value
     */
    private function unpackUInt32(string $bytes): int
    {
        $result = unpack('N', $bytes);
        $value = $result[1] ?? null;

        if (!is_int($value)) {
            return 0;
        }

        return $value;
    }

    /**
     * Unpacks a big-endian unsigned 64-bit integer from binary data.
     *
     * @param string $bytes Big-endian binary representation (8 bytes)
     *
     * @return int Unsigned integer value
     */
    private function unpackUInt64(string $bytes): int
    {
        $parts = unpack('N2', $bytes);
        $high = $parts[1] ?? null;
        $low = $parts[2] ?? null;

        if (!is_int($high) || !is_int($low)) {
            return 0;
        }

        return ($high << 32) | $low;
    }
}
