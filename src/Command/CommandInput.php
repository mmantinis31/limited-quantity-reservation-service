<?php

declare(strict_types=1);

namespace App\Command;

final class CommandInput
{
    private function __construct()
    {
    }

    public static function integer(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || 1 !== preg_match('/^-?(?:0|[1-9]\d*)$/D', $value)) {
            throw new \InvalidArgumentException(sprintf('%s must be an integer.', $label));
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if (false === $integer) {
            throw new \InvalidArgumentException(sprintf('%s is outside the supported integer range.', $label));
        }

        return $integer;
    }

    public static function string(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $label));
        }

        return $value;
    }
}
