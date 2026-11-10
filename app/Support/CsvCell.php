<?php

namespace App\Support;

/**
 * Neutralises spreadsheet formula injection in exported CSV cells.
 *
 * A text cell that starts with = + - @ tab or carriage return is run as a
 * formula by Excel, LibreOffice and Google Sheets. Prefixing a single quote
 * makes them show it as plain text. Numbers, and numeric strings like
 * "-12.50", cannot run, so they are left untouched.
 */
final class CsvCell
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], self::TRIGGERS, true) ? "'".$value : $value;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    public static function row(array $values): array
    {
        return array_map(self::safe(...), $values);
    }
}
