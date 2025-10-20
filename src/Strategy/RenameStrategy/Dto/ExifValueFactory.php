<?php

declare(strict_types=1);

namespace MagicSunday\Renamer\Strategy\RenameStrategy\Dto;

use InvalidArgumentException;
use Stringable;
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

    public static function fromNative(mixed $value): ExifArrayValue
    | ExifBooleanValue
    | ExifFloatValue
    | ExifIntegerValue
    | ExifNullValue
    | ExifStringValue
    {
        if ($value instanceof Stringable) {
            return new ExifStringValue((string) $value);
        }

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

        if ($value === null) {
            return new ExifNullValue();
        }

        if (is_array($value)) {
            return new ExifArrayValue(ExifMetadataCollection::fromArray($value));
        }

        throw new InvalidArgumentException('Unsupported EXIF value type.');
    }
}
