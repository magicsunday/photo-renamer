<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use function array_merge;
use function is_string;
use function stripos;
use function strlen;

final class MetadataEntryCollection
{
    /** @var list<MetadataEntry> */
    private readonly array $entries;

    /**
     * @param list<MetadataEntry> $entries
     */
    private function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    public static function fromMetadata(ExifRawMetadata $data): self
    {
        return new self(self::buildEntries($data->values()));
    }

    /**
     * @param ExifMetadataCollection $data
     * @param string                 $prefix
     *
     * @return list<MetadataEntry>
     */
    private static function buildEntries(ExifMetadataCollection $data, string $prefix = ''): array
    {
        $entries = [];

        foreach ($data->all() as $key => $value) {
            $keyPart = is_string($key) ? $key : (string) $key;
            $path = $prefix === '' ? $keyPart : $prefix . '.' . $keyPart;

            $stringValue = $value->asString();

            if ($stringValue !== null && $stringValue !== '') {
                $entries[] = new MetadataEntry($path, $stringValue);
            }

            $childCollection = $value->asArray();

            if ($childCollection !== null) {
                $entries = array_merge($entries, self::buildEntries($childCollection, $path));
            }
        }

        return $entries;
    }

    public function findContentIdentifier(): ?MetadataEntry
    {
        foreach ($this->entries as $entry) {
            if (
                stripos($entry->getPath(), 'content') !== false
                && stripos($entry->getPath(), 'identifier') !== false
                && strlen($entry->getValue()) > 0
            ) {
                return $entry;
            }
        }

        return null;
    }
}
