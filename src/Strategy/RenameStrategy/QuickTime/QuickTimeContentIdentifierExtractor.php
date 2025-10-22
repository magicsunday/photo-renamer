<?php

/**
 * This file is part of the package magicsunday/photo-renamer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\QuickTime;

use Exception;
use MagicSunday\Renamer\Exception\ExifMetadataReadException;
use MagicSunday\Renamer\Service\SafeFileReader;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\ContentIdentifier;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\QuickTimeKey;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\QuickTimeMetadata;
use MagicSunday\Renamer\Strategy\RenameStrategy\Dto\QuickTimeValue;
use SplFileInfo;

use function in_array;
use function is_int;
use function rtrim;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unpack;

class QuickTimeContentIdentifierExtractor
{
    /**
     * Initializes the extractor with a reader that safely loads binary file contents.
     */
    public function __construct(private readonly SafeFileReader $safeFileReader)
    {
    }

    /**
     * Extracts the QuickTime content identifier from the provided media file, if available.
     *
     * @param SplFileInfo $splFileInfo The QuickTime-based file to inspect.
     *
     * @return ContentIdentifier|null The extracted identifier or null when the metadata is missing.
     */
    public function extractContentIdentifier(SplFileInfo $splFileInfo): ?ContentIdentifier
    {
        if (!$this->supports($splFileInfo)) {
            return null;
        }

        $metadata = $this->extractMetadata($splFileInfo);

        if ($metadata === null) {
            return null;
        }

        $identifier = $metadata->findValueByKeyFragment('content.identifier');

        if ($identifier === null || $identifier->getValue() === '') {
            return null;
        }

        return new ContentIdentifier($identifier->getValue());
    }

    private function supports(SplFileInfo $splFileInfo): bool
    {
        $extension = strtolower($splFileInfo->getExtension());

        return in_array($extension, ['mov', 'mp4', 'm4v', 'qt'], true);
    }

    private function extractMetadata(SplFileInfo $splFileInfo): ?QuickTimeMetadata
    {
        $data = $this->readFile($splFileInfo);

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

        $metaPayload = substr($meta, 4);

        $keys = $this->findAtom($metaPayload, 'keys');
        $ilst = $this->findAtom($metaPayload, 'ilst');

        if ($keys === null || $ilst === null) {
            return null;
        }

        $metadata = $this->parseQuickTimeKeysAtom($keys);

        return $this->parseQuickTimeIlstAtom($ilst, $metadata);
    }

    private function readFile(SplFileInfo $splFileInfo): string
    {
        try {
            return $this->safeFileReader->read($splFileInfo);
        } catch (Exception $exception) {
            throw new ExifMetadataReadException(
                'Unable to read QuickTime metadata: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
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

                $size       = $this->unpackUInt64(substr($data, $offset + 8, 8));
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

    private function parseQuickTimeKeysAtom(string $data): QuickTimeMetadata
    {
        $length = strlen($data);

        if ($length < 8) {
            return QuickTimeMetadata::empty();
        }

        $offset = 0;

        $offset += 4;

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

            $key      = substr($data, $offset + 8, $size - 8);
            $metadata = $metadata->withKey(new QuickTimeKey($index, rtrim($key, "\0")));

            $offset += $size;
        }

        return $metadata;
    }

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

            $index    = $this->unpackUInt32(substr($data, $offset + 4, 4));
            $itemData = substr($data, $offset + 8, $size - 8);

            $value = $this->parseQuickTimeMetadataItem($itemData, $index);

            if ($value !== null) {
                $metadata = $metadata->withValue($value);
            }

            $offset += $size;
        }

        return $metadata;
    }

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
                $payload = rtrim($payload, "\0");
                $payload = trim($payload, " \t\n\r\0\x0B");

                return new QuickTimeValue($index, $payload);
            }

            $offset += $size;
        }

        return null;
    }

    private function unpackUInt32(string $bytes): int
    {
        $result = unpack('N', $bytes);
        $value  = $result[1] ?? null;

        if (!is_int($value)) {
            return 0;
        }

        return $value;
    }

    private function unpackUInt64(string $bytes): int
    {
        $parts = unpack('N2', $bytes);
        $high  = $parts[1] ?? null;
        $low   = $parts[2] ?? null;

        if (!is_int($high) || !is_int($low)) {
            return 0;
        }

        return ($high << 32) | $low;
    }
}

