<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use function array_merge;
use function is_array;
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

    /**
     * @param array<string|int, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(self::buildEntries($data));
    }

    /**
     * @param array<string|int, mixed> $data
     * @param string                   $prefix
     *
     * @return list<MetadataEntry>
     */
    private static function buildEntries(array $data, string $prefix = ''): array
    {
        $entries = [];

        foreach ($data as $key => $value) {
            $keyPart = is_string($key) ? $key : (string) $key;
            $path = $prefix === '' ? $keyPart : $prefix . '.' . $keyPart;

            if (is_string($value) && $value !== '') {
                $entries[] = new MetadataEntry($path, $value);
            }

            if (is_array($value)) {
                $entries = array_merge($entries, self::buildEntries($value, $path));
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
