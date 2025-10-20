<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

final class ExifValueFactory
{
    private function __construct()
    {
    }

    public static function fromNative(mixed $value): ExifValue
    {
        if (is_string($value)) {
            return new ExifStringValue($value);
        }

        if (is_int($value)) {
            return new ExifIntegerValue($value);
        }

        if (is_float($value)) {
            return new ExifFloatValue($value);
        }

        if (is_bool($value)) {
            return new ExifBooleanValue($value);
        }

        if (is_array($value)) {
            return new ExifArrayValue(ExifMetadataCollection::fromArray($value));
        }

        if ($value === null) {
            return new ExifNullValue();
        }

        return new ExifStringValue((string) $value);
    }
}
